-- A3 Tracker V2 - Migration Batch 1
-- RLS helpers, controlled membership mutation, policies, and grants.

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

  -- Serialize all membership mutations for the account and verify it exists.
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

revoke all on function public.is_account_member(uuid)
  from public, anon, authenticated, service_role;
revoke all on function public.has_account_role(uuid, public.account_role[])
  from public, anon, authenticated, service_role;
revoke all on function public.get_account_member_profiles(uuid)
  from public, anon, authenticated, service_role;
revoke all on function public.manage_account_membership(
  uuid,
  uuid,
  public.account_role,
  public.membership_status
) from public, anon, authenticated, service_role;

grant execute on function public.is_account_member(uuid) to authenticated;
grant execute on function public.has_account_role(uuid, public.account_role[]) to authenticated;
grant execute on function public.get_account_member_profiles(uuid) to authenticated;
grant execute on function public.manage_account_membership(
  uuid,
  uuid,
  public.account_role,
  public.membership_status
) to authenticated;

grant execute on function public.is_valid_timezone(text) to authenticated, service_role;

revoke all on type public.account_role from public;
revoke all on type public.membership_status from public;
revoke all on type public.account_status from public;
grant usage on type public.account_role to authenticated, service_role;
grant usage on type public.membership_status to authenticated, service_role;
grant usage on type public.account_status to authenticated, service_role;

alter table public.profiles enable row level security;
alter table public.accounts enable row level security;
alter table public.account_memberships enable row level security;
alter table public.branches enable row level security;

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

create policy account_memberships_select_active_members
on public.account_memberships
for select
to authenticated
using (public.is_account_member(account_id));

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

revoke all on table public.profiles from public, anon, authenticated;
revoke all on table public.accounts from public, anon, authenticated;
revoke all on table public.account_memberships from public, anon, authenticated;
revoke all on table public.branches from public, anon, authenticated;

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

-- Explicitly keep anonymous access closed, including helper execution.
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
