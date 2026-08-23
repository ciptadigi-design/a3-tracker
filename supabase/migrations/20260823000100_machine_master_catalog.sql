-- A3 Tracker V2 - Migration Batch 2
-- Machine master and counter catalog schema.

create type public.machine_category as enum (
  'digital_a3'
);

create type public.color_capability as enum (
  'color',
  'monochrome'
);

create type public.machine_status as enum (
  'active',
  'down',
  'maintenance',
  'retired'
);

create table public.manufacturers (
  id uuid primary key default gen_random_uuid(),
  code text not null,
  name text not null,
  website text,
  notes text,
  is_active boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint manufacturers_code_not_blank check (btrim(code) <> ''),
  constraint manufacturers_name_not_blank check (btrim(name) <> ''),
  constraint manufacturers_archive_fields_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index manufacturers_code_normalized_key
  on public.manufacturers (lower(btrim(code)));

create table public.machine_models (
  id uuid primary key default gen_random_uuid(),
  manufacturer_id uuid not null references public.manufacturers(id) on delete restrict,
  model_code text not null,
  name text not null,
  machine_category public.machine_category not null,
  color_capability public.color_capability not null,
  notes text,
  is_active boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint machine_models_model_code_not_blank check (btrim(model_code) <> ''),
  constraint machine_models_name_not_blank check (btrim(name) <> ''),
  constraint machine_models_archive_fields_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index machine_models_manufacturer_model_code_normalized_key
  on public.machine_models (manufacturer_id, lower(btrim(model_code)));

create index machine_models_manufacturer_active_idx
  on public.machine_models (manufacturer_id, is_active);

create table public.machines (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid not null,
  machine_model_id uuid not null references public.machine_models(id) on delete restrict,
  machine_code text not null,
  display_name text not null,
  serial_number text,
  installed_on date,
  status public.machine_status not null default 'active',
  timezone text,
  notes text,
  is_active boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint machines_branch_account_fkey
    foreign key (branch_id, account_id)
    references public.branches(id, account_id)
    on delete restrict,
  constraint machines_machine_code_not_blank check (btrim(machine_code) <> ''),
  constraint machines_display_name_not_blank check (btrim(display_name) <> ''),
  constraint machines_timezone_valid check (
    timezone is null or public.is_valid_timezone(timezone)
  ),
  constraint machines_archive_fields_consistent check (
    (is_active and status <> 'retired' and archived_at is null and archived_by is null)
    or (not is_active and status = 'retired' and archived_at is not null)
  )
);

-- This is deliberately not partial: archived machine codes remain reserved.
create unique index machines_account_machine_code_normalized_key
  on public.machines (account_id, lower(btrim(machine_code)));

create unique index machines_account_model_serial_normalized_key
  on public.machines (
    account_id,
    machine_model_id,
    lower(btrim(serial_number))
  )
  where nullif(btrim(serial_number), '') is not null;

create index machines_account_branch_idx
  on public.machines (account_id, branch_id);

create index machines_account_status_idx
  on public.machines (account_id, status, is_active);

create table public.counter_types (
  id uuid primary key default gen_random_uuid(),
  code text not null,
  name text not null,
  unit text not null,
  decimal_scale smallint not null default 0,
  is_monotonic boolean not null default true,
  description text,
  is_active boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint counter_types_code_not_blank check (btrim(code) <> ''),
  constraint counter_types_name_not_blank check (btrim(name) <> ''),
  constraint counter_types_unit_not_blank check (btrim(unit) <> ''),
  constraint counter_types_decimal_scale_range check (
    decimal_scale between 0 and 6
  ),
  constraint counter_types_archive_fields_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index counter_types_code_normalized_key
  on public.counter_types (lower(btrim(code)));

create or replace function public.set_archivable_catalog_audit_fields()
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

revoke all on function public.set_archivable_catalog_audit_fields() from public;
revoke all on function public.set_archivable_catalog_audit_fields()
  from anon, authenticated, service_role;

create or replace function public.set_machine_audit_fields()
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
    new.status := 'retired';
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

revoke all on function public.set_machine_audit_fields() from public;
revoke all on function public.set_machine_audit_fields()
  from anon, authenticated, service_role;

create trigger manufacturers_set_audit_fields
before update on public.manufacturers
for each row execute function public.set_archivable_catalog_audit_fields();

create trigger machine_models_set_audit_fields
before update on public.machine_models
for each row execute function public.set_archivable_catalog_audit_fields();

create trigger machines_set_audit_fields
before update on public.machines
for each row execute function public.set_machine_audit_fields();

create trigger counter_types_set_audit_fields
before update on public.counter_types
for each row execute function public.set_archivable_catalog_audit_fields();
