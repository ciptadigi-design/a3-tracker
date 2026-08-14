-- A3 Tracker V2 - Migration Batch 1
-- Core Auth + tenant tables and invariant triggers.

create type public.account_role as enum (
  'owner',
  'admin',
  'technician',
  'operator'
);

create type public.membership_status as enum (
  'invited',
  'active',
  'suspended',
  'revoked'
);

create type public.account_status as enum (
  'active',
  'suspended',
  'archived'
);

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

create or replace function public.set_updated_at()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at := statement_timestamp();
  return new;
end;
$$;

revoke all on function public.set_updated_at() from public;

create or replace function public.set_updated_audit_fields()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at := statement_timestamp();
  new.updated_by := coalesce((select auth.uid()), new.updated_by, old.updated_by);
  return new;
end;
$$;

revoke all on function public.set_updated_audit_fields() from public;

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

create table public.accounts (
  id uuid primary key default gen_random_uuid(),
  code text not null,
  name text not null,
  default_timezone text not null default 'Asia/Jakarta',
  default_currency character(3) not null default 'IDR',
  status public.account_status not null default 'active',
  notes text,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint accounts_code_not_blank check (btrim(code) <> ''),
  constraint accounts_name_not_blank check (btrim(name) <> ''),
  constraint accounts_currency_format check (default_currency ~ '^[A-Z]{3}$'),
  constraint accounts_timezone_valid check (public.is_valid_timezone(default_timezone)),
  constraint accounts_archive_fields_consistent check (
    (status = 'archived' and archived_at is not null)
    or (status <> 'archived' and archived_at is null and archived_by is null)
  )
);

create unique index accounts_code_normalized_key
  on public.accounts (lower(btrim(code)));

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

create table public.branches (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  code text not null,
  name text not null,
  address text,
  timezone text,
  is_active boolean not null default true,
  notes text,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint branches_id_account_key unique (id, account_id),
  constraint branches_code_not_blank check (btrim(code) <> ''),
  constraint branches_name_not_blank check (btrim(name) <> ''),
  constraint branches_timezone_valid check (
    timezone is null or public.is_valid_timezone(timezone)
  ),
  constraint branches_archive_fields_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index branches_account_code_normalized_key
  on public.branches (account_id, lower(btrim(code)));

create index branches_account_active_idx
  on public.branches (account_id, is_active);

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

revoke all on function public.set_branch_audit_fields() from public;

create trigger profiles_set_updated_at
before update on public.profiles
for each row execute function public.set_updated_at();

create trigger accounts_set_updated_audit_fields
before update on public.accounts
for each row execute function public.set_updated_audit_fields();

create trigger account_memberships_set_updated_audit_fields
before update on public.account_memberships
for each row execute function public.set_updated_audit_fields();

create trigger branches_set_audit_fields
before update on public.branches
for each row execute function public.set_branch_audit_fields();

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
revoke all on function public.handle_new_auth_user() from anon, authenticated, service_role;

create trigger on_auth_user_created_create_profile
after insert on auth.users
for each row execute function public.handle_new_auth_user();

-- Backfill profiles safely if Auth users already exist when this migration is applied.
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
    -- Serializes owner mutations for this account, including privileged direct SQL.
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
revoke all on function public.protect_membership_identity_and_last_owner()
  from anon, authenticated, service_role;

create trigger account_memberships_protect_identity_and_last_owner
before update or delete on public.account_memberships
for each row execute function public.protect_membership_identity_and_last_owner();
