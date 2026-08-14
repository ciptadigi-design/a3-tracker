# Batch 1 Auth + Tenant SQL Security and Integrity Review

Review status: static pre-push review

Review target: actual uncommitted Batch 1 SQL

Remote schema operations performed: none

## 1. Scope and source integrity

The following files were reviewed exactly as they existed at review time:

| File | SHA-256 |
|---|---|
| `supabase/migrations/20260814000100_auth_tenant_foundation.sql` | `1f6f7abfa69d6b2c8c438b5f737b8777ed1d3bb54070816163f967f600e12a79` |
| `supabase/migrations/20260814000200_auth_tenant_access.sql` | `379976d1847ac3958f4e0db45ca5259ef631f76d68c718069929d03c9f31128b` |
| `supabase/tests/database/001_auth_tenant_foundation.test.sql` | `fb2b300a3229fbf324c30ab316e1927f07a48766b7f37ae782e31a7eaa9cb2ed` |
| `supabase/bootstrap/dev_first_account.sql.example` | `0c46de8ad004901fce0b9feaecf5962d4686b5eb73e97ba7d6d05aa18d039edd` |

No migration, test, or bootstrap file was modified during this review.

### Review limitation

The SQL migrations previously parsed and applied successfully in an isolated PostgreSQL-compatible engine. The official Supabase local pgTAP runner could not execute because Docker/Podman is unavailable. Consequently, this is a static and isolated-engine review, not proof of behavior in the exact hosted Supabase role/default-privilege environment.

---

## 2. Executive security assessment

The intended application path is structurally sound:

- Auth users receive profiles without receiving accounts or memberships.
- Tenant reads depend on an active membership tied to `auth.uid()`.
- Membership writes are removed from direct authenticated table privileges.
- `manage_account_membership` re-authorizes the caller inside the definer function.
- Admins cannot assign or modify owner membership.
- Users cannot modify their own membership through the RPC.
- Branch account selection is protected by RLS and column-level grants.
- Last-owner protection exists independently as a database trigger.
- Shared profile lookup returns only user ID, display name, and avatar path.
- The first-owner bootstrap is a nonpersistent trusted transaction, not a public RPC.

No obvious path was found for an authenticated operator, technician, or admin to forge another account's membership or promote themselves to owner through the exposed API.

However, changes or explicit verification are required before DEV push because:

1. SECURITY DEFINER ownership is implicit rather than pinned in SQL. The expected owner is the migration executor (`postgres` under normal Supabase CLI deployment), but this is not encoded or test-asserted.
2. Table privileges are revoked from `anon` and `authenticated`, but not explicitly from the PostgreSQL pseudo-role `PUBLIC`. Standard PostgreSQL/Supabase defaults normally grant no table access to `PUBLIC`, but anonymous denial should not depend on external default privileges.
3. The last-owner account-row lock is appropriate for the controlled RPC path, but the absolute trigger guarantee has no two-session concurrency test. A static review cannot prove the invariant under concurrent privileged direct updates at all supported isolation levels.
4. The pgTAP suite does not verify function ownership, SECURITY DEFINER executable roles, `PUBLIC` table/function privileges, shared-profile leakage, or cross-account branch/membership writes.
5. The pgTAP suite has not run in an actual local Supabase database because the required container runtime is absent.

These are pre-push assurance gaps, not evidence of a currently exploitable client privilege escalation.

---

## 3. Exact SQL: profiles table and Auth FK

```sql
create table public.profiles (
  user_id uuid primary key references auth.users(id) on delete cascade,
  display_name text not null,
  phone text,
  avatar_path text,
  locale text not null default 'id-ID',
  created_at timestamptz not null default statement_timestamp(),
  updated_at timestamptz not null default statement_timestamp(),
  constraint profiles_display_name_not_blank check (btrim(display_name) <> ''),
  constraint profiles_locale_not_blank check (btrim(locale) <> '')
);
```

### Review

- `user_id` is both the PK and FK to `auth.users.id`.
- `ON DELETE CASCADE` removes the profile when the Auth identity is removed.
- No `account_id` exists on profiles, preserving multi-account membership.
- Direct authenticated updates are later restricted to safe profile columns.
- Phone is not exposed through the shared-account lookup function.
- A direct profile SELECT policy is own-row only.

Risk: deleting an Auth user that still has memberships will normally be prevented by the membership FK's default restrictive behavior, even though the profile FK cascades. This is conservative but should be reflected in future user-deprovisioning workflows.

---

## 4. Exact SQL: profile bootstrap trigger and function

```sql
create or replace function public.handle_new_auth_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  profile_name text;
  profile_locale text;
begin
  profile_name := coalesce(
    nullif(btrim(new.raw_user_meta_data ->> 'display_name'), ''),
    nullif(btrim(new.raw_user_meta_data ->> 'full_name'), ''),
    nullif(split_part(coalesce(new.email, ''), '@', 1), ''),
    'User'
  );

  profile_locale := coalesce(
    nullif(btrim(new.raw_user_meta_data ->> 'locale'), ''),
    'id-ID'
  );

  insert into public.profiles (user_id, display_name, locale)
  values (new.id, profile_name, profile_locale)
  on conflict (user_id) do nothing;

  return new;
end;
$$;

revoke all on function public.handle_new_auth_user() from public;

create trigger on_auth_user_created_create_profile
after insert on auth.users
for each row execute function public.handle_new_auth_user();
```

The migration also backfills existing Auth users:

```sql
insert into public.profiles (user_id, display_name, locale)
select
  auth_user.id,
  coalesce(
    nullif(btrim(auth_user.raw_user_meta_data ->> 'display_name'), ''),
    nullif(btrim(auth_user.raw_user_meta_data ->> 'full_name'), ''),
    nullif(split_part(coalesce(auth_user.email, ''), '@', 1), ''),
    'User'
  ),
  coalesce(
    nullif(btrim(auth_user.raw_user_meta_data ->> 'locale'), ''),
    'id-ID'
  )
from auth.users as auth_user
on conflict (user_id) do nothing;
```

### Review

- The trigger creates only a profile. It does not create an account or membership.
- User-controlled metadata affects only display name and locale; it cannot assign roles or tenant IDs.
- `search_path = ''` prevents object-shadowing through search-path manipulation.
- `public.profiles` is schema-qualified.
- `ON CONFLICT DO NOTHING` makes profile creation idempotent.
- PUBLIC execution is revoked; callers do not invoke this function as an RPC.
- SECURITY DEFINER is justified because an Auth insertion must create a public-schema profile even when the Auth execution role does not have direct profile INSERT rights.

Nonblocking observation: there are no length limits on metadata-derived display name or locale. This is not privilege escalation, but application-level input limits may be useful later.

---

## 5. Exact SQL: account memberships table

```sql
create table public.account_memberships (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  user_id uuid not null references auth.users(id) on delete restrict,
  role public.account_role not null,
  status public.membership_status not null default 'invited',
  invited_at timestamptz not null default statement_timestamp(),
  accepted_at timestamptz,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  constraint account_memberships_account_user_key unique (account_id, user_id),
  constraint account_memberships_id_account_key unique (id, account_id),
  constraint account_memberships_active_accepted check (
    status <> 'active' or accepted_at is not null
  )
);

create index account_memberships_user_active_idx
  on public.account_memberships (user_id, account_id)
  where status = 'active';

create index account_memberships_account_role_status_idx
  on public.account_memberships (account_id, role, status);
```

### Review

- One membership exists per user/account.
- Active membership requires acceptance time.
- User and account foreign keys cannot be silently orphaned.
- `(id, account_id)` supports future composite tenant FKs.
- The helper indexes match active membership and owner-count queries.
- Direct client INSERT/UPDATE/DELETE is not granted later.

The database does not require every account row to have an owner at all intermediate times. Account creation is therefore restricted to trusted bootstrap/onboarding and the bootstrap inserts the account and first owner in one transaction.

---

## 6. Exact SQL: last-owner trigger and function

```sql
create or replace function public.protect_membership_identity_and_last_owner()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  removes_active_owner boolean;
begin
  if tg_op = 'UPDATE' and (
    new.account_id is distinct from old.account_id
    or new.user_id is distinct from old.user_id
  ) then
    raise exception 'membership account_id and user_id are immutable'
      using errcode = '23514';
  end if;

  removes_active_owner := false;

  if old.role = 'owner' and old.status = 'active' then
    if tg_op = 'DELETE' then
      removes_active_owner := true;
    else
      removes_active_owner := new.role <> 'owner' or new.status <> 'active';
    end if;
  end if;

  if removes_active_owner then
    perform 1
    from public.accounts
    where id = old.account_id
    for update;

    if not exists (
      select 1
      from public.account_memberships as membership
      where membership.account_id = old.account_id
        and membership.id <> old.id
        and membership.role = 'owner'
        and membership.status = 'active'
    ) then
      raise exception 'account must retain at least one active owner'
        using errcode = '23514';
    end if;
  end if;

  if tg_op = 'DELETE' then
    return old;
  end if;

  return new;
end;
$$;

revoke all on function public.protect_membership_identity_and_last_owner() from public;

create trigger account_memberships_protect_identity_and_last_owner
before update or delete on public.account_memberships
for each row execute function public.protect_membership_identity_and_last_owner();
```

### Review

- It protects demotion, suspension, revocation, and deletion of an active owner.
- It also makes `account_id` and `user_id` immutable.
- It runs even for privileged direct table updates, not only the RPC.
- It locks the account row to serialize owner mutation for that account.
- It is intentionally SECURITY DEFINER so membership RLS cannot hide other owners from the invariant check.
- PUBLIC cannot execute it directly.

### Concurrency assessment

The application RPC locks the account row before reading or changing memberships, so concurrent RPC calls are serialized. The trigger also acquires the same account-row lock before counting alternative owners.

This is a good locking design for PostgreSQL's normal `READ COMMITTED` application transactions. Nevertheless, the pgTAP suite is single-session and does not demonstrate two concurrent owner demotions or privileged direct mutations. The absolute invariant should receive a two-session test before push or immediately in an approved local Supabase environment. Static review alone cannot certify every isolation-level interaction.

---

## 7. Exact SQL: `manage_account_membership`

```sql
create or replace function public.manage_account_membership(
  target_account_id uuid,
  target_user_id uuid,
  target_role public.account_role,
  target_status public.membership_status
)
returns public.account_memberships
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  actor_role public.account_role;
  current_membership public.account_memberships%rowtype;
  result_membership public.account_memberships%rowtype;
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;

  perform 1
  from public.accounts
  where id = target_account_id
  for update;

  if not found then
    raise exception 'account not found' using errcode = 'P0002';
  end if;

  select membership.role
  into actor_role
  from public.account_memberships as membership
  where membership.account_id = target_account_id
    and membership.user_id = actor_id
    and membership.status = 'active';

  if actor_role is null or actor_role not in ('owner', 'admin') then
    raise exception 'owner or admin role required' using errcode = '42501';
  end if;

  if target_user_id = actor_id then
    raise exception 'users cannot directly change their own membership'
      using errcode = '42501';
  end if;

  if not exists (select 1 from auth.users where id = target_user_id) then
    raise exception 'target Auth user does not exist' using errcode = '23503';
  end if;

  select membership.*
  into current_membership
  from public.account_memberships as membership
  where membership.account_id = target_account_id
    and membership.user_id = target_user_id
  for update;

  if actor_role = 'admin' and (
    target_role = 'owner'
    or current_membership.role = 'owner'
  ) then
    raise exception 'admins cannot create, promote, or modify owner memberships'
      using errcode = '42501';
  end if;

  insert into public.account_memberships (
    account_id,
    user_id,
    role,
    status,
    invited_at,
    accepted_at,
    created_by,
    updated_by
  )
  values (
    target_account_id,
    target_user_id,
    target_role,
    target_status,
    statement_timestamp(),
    case when target_status = 'active' then statement_timestamp() end,
    actor_id,
    actor_id
  )
  on conflict (account_id, user_id) do update
  set role = excluded.role,
      status = excluded.status,
      accepted_at = case
        when excluded.status = 'active'
          then coalesce(public.account_memberships.accepted_at, statement_timestamp())
        else public.account_memberships.accepted_at
      end,
      updated_by = actor_id
  returning * into result_membership;

  return result_membership;
end;
$$;
```

### Client-argument forgery analysis

- `target_account_id` is client-controlled, but the actor must have an active owner/admin membership in that exact account.
- `target_user_id` is client-controlled, but must exist in `auth.users`, and self-mutation is denied.
- `target_role` is client-controlled, but admins cannot set owner and cannot modify an existing owner.
- `target_status` is client-controlled, but only an owner/admin in the target account reaches the mutation.
- `actor_id`, `created_by`, and `updated_by` are derived from `auth.uid()`, not accepted from arguments.
- The last-owner trigger still validates the resulting update.

### Review result

No cross-account or self-promotion path was found. SECURITY DEFINER is necessary because direct membership table writes are withheld and the function must see memberships regardless of RLS. The internal authorization checks are therefore mandatory and are present.

---

## 8. Exact SQL: `is_account_member`

```sql
create or replace function public.is_account_member(target_account_id uuid)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
    from public.account_memberships as membership
    where membership.account_id = target_account_id
      and membership.user_id = (select auth.uid())
      and membership.status = 'active'
  );
$$;
```

### Review

- Arbitrary account IDs can be supplied, but the result is true only for `auth.uid()` with active membership.
- A NULL `auth.uid()` cannot match a non-null `user_id`, so it returns false.
- It intentionally bypasses membership RLS to prevent recursive policy evaluation.
- It discloses at most a boolean about the caller's own membership.
- PUBLIC execute is revoked later; authenticated receives execute.

---

## 9. Exact SQL: `has_account_role`

```sql
create or replace function public.has_account_role(
  target_account_id uuid,
  allowed_roles public.account_role[]
)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
    from public.account_memberships as membership
    where membership.account_id = target_account_id
      and membership.user_id = (select auth.uid())
      and membership.status = 'active'
      and membership.role = any(allowed_roles)
  );
$$;
```

### Review

- The caller may choose `allowed_roles` when calling the helper directly, but this only tests the caller's actual stored role; it does not grant that role.
- RLS policies pass hard-coded role arrays, so clients cannot alter policy role lists.
- NULL `auth.uid()` returns false.
- Membership RLS bypass is intentional to avoid recursion.
- PUBLIC execute is revoked later; authenticated receives execute.

---

## 10. Exact SQL: `get_account_member_profiles`

```sql
create or replace function public.get_account_member_profiles(target_account_id uuid)
returns table (
  user_id uuid,
  display_name text,
  avatar_path text
)
language sql
stable
security definer
set search_path = ''
as $$
  select profile.user_id, profile.display_name, profile.avatar_path
  from public.account_memberships as membership
  join public.profiles as profile on profile.user_id = membership.user_id
  where membership.account_id = target_account_id
    and membership.status in ('invited', 'active')
    and public.is_account_member(target_account_id);
$$;
```

### Review

- An arbitrary account ID does not yield rows unless the caller is an active member of that account.
- It intentionally bypasses profile RLS, but returns only three explicitly declared columns.
- It does not return phone, locale, email, Auth metadata, or audit details.
- All active members, including operators, can enumerate invited/active members' limited profile information. This matches the stated limited roster use but should be confirmed as a product privacy decision.
- Suspended/revoked callers fail `is_account_member` and receive no rows.
- PUBLIC execute is revoked later; authenticated receives execute.

---

## 11. SECURITY DEFINER function inventory

The SQL does not contain `ALTER FUNCTION ... OWNER TO ...`. PostgreSQL therefore assigns each function to the role executing the migration. For a normal Supabase CLI database migration this is expected to be `postgres`, but the owner is implicit and cannot be proven from the migration text alone.

| Function | Owner encoded in SQL | Search path | Explicit executable roles | PUBLIC revoked | Client argument forgery | `auth.uid()` | RLS bypass | Why definer is necessary |
|---|---|---|---|---|---|---|---|---|
| `handle_new_auth_user()` | No; expected migration executor/`postgres` | Empty | Trigger invocation only; no explicit role grant | Yes | No callable args; `NEW` comes from Auth trigger | Not used | Intentional | Auth insertion must create a profile without public profile INSERT rights |
| `protect_membership_identity_and_last_owner()` | No; expected migration executor/`postgres` | Empty | Trigger invocation only; no explicit role grant | Yes | No callable args; OLD/NEW come from membership trigger | Not used | Intentional | Must see all owners and protect invariant regardless of RLS |
| `is_account_member(uuid)` | No; expected migration executor/`postgres` | Empty | `authenticated` | Yes | Account ID arbitrary, but result bound to caller UID | Compared to membership user | Intentional | RLS-safe lookup must avoid membership-policy recursion |
| `has_account_role(uuid, account_role[])` | No; expected migration executor/`postgres` | Empty | `authenticated` | Yes | Account/role array arbitrary, but only tests stored caller role | Compared to membership user | Intentional | Role policies need nonrecursive membership lookup |
| `get_account_member_profiles(uuid)` | No; expected migration executor/`postgres` | Empty | `authenticated` | Yes | Account ID arbitrary, but active-member check gates result | Indirectly through helper | Intentional | Must return a restricted cross-profile roster while direct profile RLS remains self-only |
| `manage_account_membership(...)` | No; expected migration executor/`postgres` | Empty | `authenticated` | Yes | All target args controlled but internally revalidated | Actor identity and audit actor | Intentional | Centralizes authorized membership mutation while direct writes are denied |

### Owner requirement before push

Either:

1. explicitly pin SECURITY DEFINER function ownership to the intended trusted database role in migration SQL; or
2. establish and test that the Supabase migration executor is `postgres`, then add post-migration ownership assertions to the database test.

The functions are safe only while their owner is trusted, owns/can read the protected tables, and cannot be assumed by application users.

---

## 12. Exact SQL: every RLS policy

### Profiles

```sql
create policy profiles_select_own
on public.profiles
for select
to authenticated
using (user_id = (select auth.uid()));

create policy profiles_update_own
on public.profiles
for update
to authenticated
using (user_id = (select auth.uid()))
with check (user_id = (select auth.uid()));
```

Review: direct access is own-row only. The `user_id` cannot be changed because column-level UPDATE does not include it. NULL `auth.uid()` matches no profile.

### Accounts

```sql
create policy accounts_select_active_members
on public.accounts
for select
to authenticated
using (public.is_account_member(id));

create policy accounts_update_owner_settings
on public.accounts
for update
to authenticated
using (public.has_account_role(id, array['owner']::public.account_role[]))
with check (public.has_account_role(id, array['owner']::public.account_role[]));
```

Review: active membership gates reads. Only owners update, and column grants restrict updates to name, timezone, currency, and notes. Account ID, code, status, and archive/audit fields are not directly client-updatable.

### Account memberships

```sql
create policy account_memberships_select_active_members
on public.account_memberships
for select
to authenticated
using (public.is_account_member(account_id));
```

Review: any active member can read the membership roster for that account. No direct INSERT, UPDATE, or DELETE policy exists, and no corresponding authenticated table privilege is granted.

### Branches

```sql
create policy branches_select_active_members
on public.branches
for select
to authenticated
using (public.is_account_member(account_id));

create policy branches_insert_owner_admin
on public.branches
for insert
to authenticated
with check (
  public.has_account_role(
    account_id,
    array['owner', 'admin']::public.account_role[]
  )
);

create policy branches_update_owner_admin
on public.branches
for update
to authenticated
using (
  public.has_account_role(
    account_id,
    array['owner', 'admin']::public.account_role[]
  )
)
with check (
  public.has_account_role(
    account_id,
    array['owner', 'admin']::public.account_role[]
  )
);
```

Review: owner/admin can insert only into accounts where they hold the role. UPDATE requires the role against both old and new row. Column-level grants prevent changing `account_id`, so a branch cannot be moved across tenants through the client API.

---

## 13. Exact SQL: grants and revokes

### SECURITY DEFINER and helper functions

```sql
revoke all on function public.is_account_member(uuid) from public;
revoke all on function public.has_account_role(uuid, public.account_role[]) from public;
revoke all on function public.get_account_member_profiles(uuid) from public;
revoke all on function public.manage_account_membership(
  uuid,
  uuid,
  public.account_role,
  public.membership_status
) from public;

grant execute on function public.is_account_member(uuid) to authenticated;
grant execute on function public.has_account_role(uuid, public.account_role[]) to authenticated;
grant execute on function public.get_account_member_profiles(uuid) to authenticated;
grant execute on function public.manage_account_membership(
  uuid,
  uuid,
  public.account_role,
  public.membership_status
) to authenticated;
```

Trigger definer functions are also revoked from PUBLIC:

```sql
revoke all on function public.handle_new_auth_user() from public;
revoke all on function public.protect_membership_identity_and_last_owner() from public;
```

Timezone/type access:

```sql
grant execute on function public.is_valid_timezone(text) to authenticated, service_role;

revoke all on type public.account_role from public;
revoke all on type public.membership_status from public;
revoke all on type public.account_status from public;
grant usage on type public.account_role to authenticated, service_role;
grant usage on type public.membership_status to authenticated, service_role;
grant usage on type public.account_status to authenticated, service_role;
```

Explicit anonymous function revokes:

```sql
revoke all on function public.is_valid_timezone(text) from anon;
revoke all on function public.is_account_member(uuid) from anon;
revoke all on function public.has_account_role(uuid, public.account_role[]) from anon;
revoke all on function public.get_account_member_profiles(uuid) from anon;
revoke all on function public.manage_account_membership(
  uuid,
  uuid,
  public.account_role,
  public.membership_status
) from anon;
```

### Tables and safe columns

```sql
revoke all on table public.profiles from anon, authenticated;
revoke all on table public.accounts from anon, authenticated;
revoke all on table public.account_memberships from anon, authenticated;
revoke all on table public.branches from anon, authenticated;

grant select on table public.profiles to authenticated;
grant update (display_name, phone, avatar_path, locale)
  on table public.profiles to authenticated;

grant select on table public.accounts to authenticated;
grant update (name, default_timezone, default_currency, notes)
  on table public.accounts to authenticated;

grant select on table public.account_memberships to authenticated;

grant select on table public.branches to authenticated;
grant insert (account_id, code, name, address, timezone, is_active, notes)
  on table public.branches to authenticated;
grant update (code, name, address, timezone, is_active, notes)
  on table public.branches to authenticated;
```

### Review

- Membership mutation cannot bypass the function through authenticated table privileges.
- Profile identity and audit columns cannot be updated by clients.
- Account identity, code, status, and audit/archive fields cannot be updated by clients.
- Branch account and audit/archive fields cannot be supplied or updated by clients.
- `service_role` is not explicitly granted table privileges here. That is conservative; whether future trusted backend access needs explicit grants is an operational decision.

### Blocking hardening gap

The SQL revokes table privileges from named roles but does not state:

```sql
revoke all on table ... from public;
```

PostgreSQL standard table defaults do not grant PUBLIC table access, and the current Supabase project configuration indicates new tables are not auto-exposed. Even so, explicit anonymous denial should be independent of pre-existing altered default privileges. Add explicit PUBLIC table revokes or add a pre-push assertion proving PUBLIC has no privileges on all four tables.

---

## 14. Exact SQL: branch INSERT/UPDATE protection

RLS protection:

```sql
create policy branches_insert_owner_admin
on public.branches
for insert
to authenticated
with check (
  public.has_account_role(
    account_id,
    array['owner', 'admin']::public.account_role[]
  )
);

create policy branches_update_owner_admin
on public.branches
for update
to authenticated
using (
  public.has_account_role(
    account_id,
    array['owner', 'admin']::public.account_role[]
  )
)
with check (
  public.has_account_role(
    account_id,
    array['owner', 'admin']::public.account_role[]
  )
);
```

Column protection:

```sql
grant insert (account_id, code, name, address, timezone, is_active, notes)
  on table public.branches to authenticated;
grant update (code, name, address, timezone, is_active, notes)
  on table public.branches to authenticated;
```

Archive/audit trigger:

```sql
create or replace function public.set_branch_audit_fields()
returns trigger
language plpgsql
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
begin
  new.updated_at := statement_timestamp();
  new.updated_by := coalesce(actor_id, new.updated_by, old.updated_by);

  if old.is_active and not new.is_active then
    new.archived_at := statement_timestamp();
    new.archived_by := coalesce(actor_id, new.archived_by);
  elsif not old.is_active and new.is_active then
    new.archived_at := null;
    new.archived_by := null;
  else
    new.archived_at := old.archived_at;
    new.archived_by := old.archived_by;
  end if;

  return new;
end;
$$;
```

Review result: an owner/admin cannot forge another tenant's `account_id`, `created_by`, `updated_by`, or archive actor through allowed columns. Operator/technician updates match no RLS row and cannot create branches.

Missing test: the suite checks operator denial within Account A but does not explicitly test an Account A admin inserting/updating an Account B branch.

---

## 15. Exact SQL: normalized unique indexes

Account code:

```sql
create unique index accounts_code_normalized_key
  on public.accounts (lower(btrim(code)));
```

Branch code within account:

```sql
create unique index branches_account_code_normalized_key
  on public.branches (account_id, lower(btrim(code)));
```

Review result: case and surrounding whitespace cannot create duplicate logical codes. Stored code values are not rewritten to normalized form, which is acceptable because uniqueness uses the normalized expression.

Missing tests: equivalent case/whitespace duplicate inserts are not asserted.

---

## 16. Exact SQL: timezone validation

Function:

```sql
create or replace function public.is_valid_timezone(timezone_name text)
returns boolean
language sql
stable
set search_path = ''
as $$
  select exists (
    select 1
    from pg_catalog.pg_timezone_names
    where name = timezone_name
  );
$$;

revoke all on function public.is_valid_timezone(text) from public;
```

Constraints:

```sql
constraint accounts_timezone_valid check (public.is_valid_timezone(default_timezone))
```

```sql
constraint branches_timezone_valid check (
  timezone is null or public.is_valid_timezone(timezone)
)
```

Review result:

- `pg_catalog.pg_timezone_names` is schema-qualified.
- Empty search path prevents object substitution.
- Account timezone is required and valid; branch timezone may be null for fallback.
- Authenticated/service-role execution is granted later so their allowed writes can satisfy the check.

Trade-off: a STABLE function in a CHECK constraint relies on the installed PostgreSQL timezone catalog remaining consistent. Timezone catalogs change only through database updates, making this reasonable for V1, but a post-upgrade constraint validation is prudent.

---

## 17. Exact SQL: first-account bootstrap transaction

```sql
begin;

do $$
declare
  owner_email constant text := 'REPLACE_WITH_REAL_DEV_OWNER_EMAIL';
  owner_user_id uuid;
  new_account_id uuid;
begin
  if owner_email = 'REPLACE_WITH_REAL_DEV_OWNER_EMAIL' then
    raise exception 'replace the DEV owner email before running bootstrap';
  end if;

  select id
  into strict owner_user_id
  from auth.users
  where lower(email) = lower(owner_email);

  if exists (
    select 1
    from public.accounts
    where lower(btrim(code)) = lower('CIPTA-GRAFIKA')
  ) then
    raise exception 'Cipta Grafika account already exists; bootstrap is one-time only';
  end if;

  insert into public.accounts (
    code,
    name,
    default_timezone,
    default_currency,
    status,
    created_by,
    updated_by
  )
  values (
    'CIPTA-GRAFIKA',
    'Cipta Grafika',
    'Asia/Jakarta',
    'IDR',
    'active',
    owner_user_id,
    owner_user_id
  )
  returning id into new_account_id;

  insert into public.account_memberships (
    account_id,
    user_id,
    role,
    status,
    accepted_at,
    created_by,
    updated_by
  )
  values (
    new_account_id,
    owner_user_id,
    'owner',
    'active',
    statement_timestamp(),
    owner_user_id,
    owner_user_id
  );

  insert into public.branches (
    account_id,
    code,
    name,
    timezone,
    created_by,
    updated_by
  )
  values (
    new_account_id,
    'TUPAREV',
    'Tuparev',
    'Asia/Jakarta',
    owner_user_id,
    owner_user_id
  );
end;
$$;

commit;
```

### Security review

- The placeholder forces explicit operator action.
- `INTO STRICT` fails when the user is missing or unexpectedly ambiguous.
- The real user must already exist through supported Supabase Auth tooling.
- No fake Auth row is inserted.
- The script is not a migration and is not included by `db push`.
- It creates no persistent privileged function.
- Account, owner membership, and branch commit atomically.
- Failure rolls back all three inserts.

### Why first owner succeeds

The last-owner trigger is:

```sql
before update or delete on public.account_memberships
```

It does not fire on INSERT. The bootstrap can therefore insert the first active owner. Normal last-owner protection begins immediately for any later UPDATE or DELETE of that membership. The account and owner are inside one outer transaction, so no account-without-owner state becomes visible or commits when the script succeeds.

### Operational risk

The SQL cannot verify the Supabase project ref. The operator must independently confirm linked project `sxitqjxljoqsnpepymrl` and DEV status immediately before manual execution. This is not privilege leakage in the script, but it is an important runbook control.

---

## 18. Exact SQL: pgTAP authentication context

The test creates rollback-only Auth identities:

```sql
begin;

create extension if not exists pgtap with schema extensions;

select extensions.plan(14);

insert into auth.users (
  id,
  aud,
  role,
  email,
  encrypted_password,
  email_confirmed_at,
  raw_app_meta_data,
  raw_user_meta_data,
  created_at,
  updated_at
)
values
  ('00000000-0000-0000-0000-000000000001', 'authenticated', 'authenticated', 'owner-a@test.invalid', '', now(), '{}', '{"display_name":"Owner A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000002', 'authenticated', 'authenticated', 'admin-a@test.invalid', '', now(), '{}', '{"display_name":"Admin A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000003', 'authenticated', 'authenticated', 'operator-a@test.invalid', '', now(), '{}', '{"display_name":"Operator A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000004', 'authenticated', 'authenticated', 'suspended-a@test.invalid', '', now(), '{}', '{"display_name":"Suspended A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000005', 'authenticated', 'authenticated', 'owner-b@test.invalid', '', now(), '{}', '{"display_name":"Owner B"}', now(), now()),
  ('00000000-0000-0000-0000-000000000006', 'authenticated', 'authenticated', 'outsider@test.invalid', '', now(), '{}', '{"display_name":"Outsider"}', now(), now());
```

Anonymous context:

```sql
set local role anon;
select extensions.throws_ok(
  'select * from public.accounts',
  '42501',
  null,
  'anonymous cannot read tenant data'
);
reset role;
```

Authenticated JWT-subject context:

```sql
set local role authenticated;
select set_config('request.jwt.claim.sub', '00000000-0000-0000-0000-000000000006', true);
select extensions.is(
  (select count(*)::integer from public.accounts),
  0,
  'user without membership cannot read an account'
);
reset role;
```

Admin self-promotion context:

```sql
set local role authenticated;
select set_config('request.jwt.claim.sub', '00000000-0000-0000-0000-000000000002', true);
select extensions.throws_ok(
  $$select public.manage_account_membership(
      '10000000-0000-0000-0000-000000000001',
      '00000000-0000-0000-0000-000000000002',
      'owner',
      'active'
    )$$,
  '42501',
  null,
  'user cannot self-promote'
);
reset role;
```

The test ends with:

```sql
select extensions.finish();

rollback;
```

### Review

- `SET LOCAL ROLE` simulates PostgREST database roles.
- `request.jwt.claim.sub` supplies the identity read by `auth.uid()`.
- Direct Auth inserts are confined to a rollback-only test and are not used for DEV bootstrap.
- Fixed UUIDs make tenant scenarios deterministic.
- The transaction rollback prevents test fixtures from persisting.

### Coverage gaps before push

Add or execute verification for:

- Function owners and `prosecdef` flags.
- PUBLIC/anon/authenticated function EXECUTE matrix.
- PUBLIC/anon/authenticated table privileges.
- Own-profile update and other-profile denial.
- Shared-profile function same-account result and cross-account/unauthenticated denial.
- Owner account update and admin account-update denial.
- Account A admin branch INSERT/UPDATE attempt against Account B.
- Account A owner/admin membership mutation attempt against Account B.
- Successful owner addition followed by safe demotion of a non-last owner.
- Duplicate normalized account and branch codes.
- Invalid timezone rejection.
- Two-session concurrent last-owner mutation.
- Profile trigger result for a newly inserted Auth user.

---

## 19. Static threat review matrix

| Threat | Result | Evidence and reasoning |
|---|---|---|
| RLS recursion | Pass | Membership policies call SECURITY DEFINER helpers; helpers query membership as definer and do not re-enter RLS |
| Privilege escalation | Pass in intended path | Direct membership writes withheld; RPC rechecks caller membership/role |
| Self-promotion | Pass | RPC rejects `target_user_id = auth.uid()`; no direct membership write grant |
| Admin-to-owner promotion | Pass | Admin cannot set target role owner or modify existing owner |
| Cross-account membership manipulation | Pass | Actor must be active owner/admin in `target_account_id` |
| Last-owner functional protection | Pass | Trigger blocks demote/suspend/revoke/delete when no other active owner |
| Last-owner race protection | Needs concurrent verification | Account row lock is present, but pgTAP is single-session |
| Unsafe NULL `auth.uid()` | Pass | RPC explicitly rejects NULL; boolean helpers return false through non-null user comparison |
| Unsafe function search path | Pass | All SECURITY DEFINER functions use empty search path and qualified protected objects |
| PUBLIC function execution | Pass for listed definer functions | Explicit `REVOKE ... FROM PUBLIC`; authenticated grants are deliberate |
| PUBLIC table access | Needs hardening/assertion | Named-role revokes exist, but no explicit table revoke from PUBLIC |
| Direct membership write bypass | Pass for authenticated | No authenticated mutation grants or mutation policies |
| Profile information leakage | Pass with product caveat | Direct profile read is self-only; helper exposes only ID/name/avatar to active account peers |
| Cross-tenant branch read | Pass | Active membership helper gates branch SELECT |
| Cross-tenant branch write | Pass by design; test gap | Owner/admin role checked against row account and `account_id` is not update-granted |
| Bootstrap privilege leakage | Pass | Trusted one-time DO block, no persistent function, no fake Auth insert |
| First-owner bootstrap | Pass | Owner insert does not fire UPDATE/DELETE last-owner trigger; outer transaction is atomic |
| Function-owner trust | Needs explicit confirmation | Owner is implicit migration executor, not encoded or test-asserted |

---

## 20. Required actions before DEV push

### SQL hardening

1. Explicitly revoke table privileges from `PUBLIC` on all four tables, then grant only the intended named-role privileges.
2. Pin SECURITY DEFINER ownership to the approved trusted migration/database role, or add an executable pre/post-deployment assertion proving each function owner is the expected trusted role.

### Verification

3. Run the pgTAP suite in a real local Supabase database.
4. Add privilege-catalog assertions for function owner, SECURITY DEFINER, search path, and executable roles.
5. Add PUBLIC table-privilege assertions.
6. Add same-account/cross-account tests for limited profile lookup, branch writes, and membership RPCs.
7. Add a two-session concurrency test for simultaneous owner mutation.
8. Reconfirm the bootstrap target ref immediately before the separate manual bootstrap action.

The first two items are migration hardening changes. Items three through seven are evidence required to substantiate the security claims before remote execution.

---

## 21. Verdict

**CHANGES_REQUIRED_BEFORE_DEV_PUSH**

Exact reasons:

1. Anonymous denial is not fully environment-independent until table privileges are explicitly revoked from `PUBLIC` or equivalent privilege assertions prove no inherited PUBLIC access.
2. SECURITY DEFINER ownership is implicit and untested; the trusted owner must be pinned or verified.
3. The last-owner concurrency guarantee has not been tested with concurrent sessions.
4. The provided pgTAP suite has not run against an actual Supabase local database and omits privilege-catalog and several cross-tenant negative assertions.

No migration should be pushed until these items are addressed or explicitly accepted by the security/database approver with documented evidence.
