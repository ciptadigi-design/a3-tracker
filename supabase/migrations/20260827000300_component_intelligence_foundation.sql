-- A3 Tracker V2 - M2.3A Component Intelligence Foundation
-- Shared catalog definitions plus account-overridable machine-model profiles.

create type public.component_tracking_method as enum (
  'counter_based',
  'consumption_based',
  'inspection_based'
);

create table public.components (
  id uuid primary key default gen_random_uuid(),
  account_id uuid references public.accounts(id) on delete restrict,
  code text not null,
  name text not null,
  category text,
  description text,
  manufacturer_id uuid references public.manufacturers(id) on delete restrict,
  part_number text,
  default_tracking_method public.component_tracking_method not null default 'counter_based',
  is_active boolean not null default true,
  archived_at timestamptz,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  constraint components_code_not_blank check (btrim(code) <> ''),
  constraint components_name_not_blank check (btrim(name) <> ''),
  constraint components_archive_consistent check (
    (is_active and archived_at is null) or (not is_active and archived_at is not null)
  )
);

create unique index components_scope_code_key
  on public.components (coalesce(account_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(btrim(code)));
create index components_account_active_idx on public.components (account_id, is_active);

create table public.machine_model_components (
  id uuid primary key default gen_random_uuid(),
  account_id uuid references public.accounts(id) on delete restrict,
  machine_model_id uuid not null references public.machine_models(id) on delete restrict,
  component_id uuid not null references public.components(id) on delete restrict,
  slot_code text not null,
  display_order integer not null default 0,
  tracking_method public.component_tracking_method not null,
  baseline_expected_clicks bigint,
  adaptive_enabled boolean not null default true,
  healthy_threshold_percent numeric(5,2) not null default 30,
  watch_threshold_percent numeric(5,2) not null default 15,
  warning_threshold_percent numeric(5,2) not null default 5,
  critical_threshold_percent numeric(5,2) not null default 0,
  notes text,
  is_active boolean not null default true,
  archived_at timestamptz,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  constraint machine_model_components_slot_not_blank check (btrim(slot_code) <> ''),
  constraint machine_model_components_display_order_nonnegative check (display_order >= 0),
  constraint machine_model_components_expected_positive check (
    baseline_expected_clicks is null or baseline_expected_clicks > 0
  ),
  constraint machine_model_components_thresholds_valid check (
    healthy_threshold_percent <= 100
    and healthy_threshold_percent > watch_threshold_percent
    and watch_threshold_percent > warning_threshold_percent
    and warning_threshold_percent > critical_threshold_percent
    and critical_threshold_percent >= 0
  ),
  constraint machine_model_components_archive_consistent check (
    (is_active and archived_at is null) or (not is_active and archived_at is not null)
  )
);

create unique index machine_model_components_active_slot_key
  on public.machine_model_components (
    machine_model_id,
    coalesce(account_id, '00000000-0000-0000-0000-000000000000'::uuid),
    lower(btrim(slot_code))
  ) where is_active;
create index machine_model_components_model_scope_idx
  on public.machine_model_components (machine_model_id, account_id, display_order);
create index machine_model_components_component_idx
  on public.machine_model_components (component_id);

create or replace function public.set_component_foundation_audit_fields()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if tg_op = 'INSERT' then
    if not new.is_active then
      new.archived_at := coalesce(new.archived_at, statement_timestamp());
    end if;
    return new;
  end if;

  new.updated_at := statement_timestamp();
  new.updated_by := coalesce((select auth.uid()), new.updated_by, old.updated_by);
  if old.is_active and not new.is_active then
    new.archived_at := statement_timestamp();
  elsif not old.is_active and new.is_active then
    new.archived_at := null;
  else
    new.archived_at := old.archived_at;
  end if;
  return new;
end;
$$;

revoke all on function public.set_component_foundation_audit_fields()
  from public, anon, authenticated, service_role;

create trigger components_set_audit_fields before insert or update on public.components
for each row execute function public.set_component_foundation_audit_fields();
create trigger machine_model_components_set_audit_fields before insert or update on public.machine_model_components
for each row execute function public.set_component_foundation_audit_fields();

comment on table public.components is
  'Reusable shared or account-owned component definitions. Shared rows have a null account_id.';
comment on table public.machine_model_components is
  'Machine-model slot configuration. Account rows override shared rows with the same model and slot.';
comment on column public.machine_model_components.baseline_expected_clicks is
  'Editable human reference used for future installs; completed lifecycles must retain expected_at_install snapshots.';
comment on column public.machine_model_components.adaptive_enabled is
  'Allows future derived lifecycle estimates; it never authorizes rewriting the manual baseline.';
