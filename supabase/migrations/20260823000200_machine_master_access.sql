-- A3 Tracker V2 - Migration Batch 2
-- Machine master and counter catalog RLS, grants, and revokes.

revoke all on type public.machine_category from public;
revoke all on type public.color_capability from public;
revoke all on type public.machine_status from public;
grant usage on type public.machine_category to authenticated, service_role;
grant usage on type public.color_capability to authenticated, service_role;
grant usage on type public.machine_status to authenticated, service_role;

alter table public.manufacturers enable row level security;
alter table public.machine_models enable row level security;
alter table public.machines enable row level security;
alter table public.counter_types enable row level security;

create policy manufacturers_select_active_authenticated
on public.manufacturers
for select
to authenticated
using (is_active);

create policy machine_models_select_active_authenticated
on public.machine_models
for select
to authenticated
using (is_active);

create policy counter_types_select_active_authenticated
on public.counter_types
for select
to authenticated
using (is_active);

create policy machines_select_account_members
on public.machines
for select
to authenticated
using (public.is_account_member(account_id));

create policy machines_insert_owner_admin
on public.machines
for insert
to authenticated
with check (
  public.has_account_role(
    account_id,
    array['owner', 'admin']::public.account_role[]
  )
);

create policy machines_update_owner_admin
on public.machines
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

revoke all on table public.manufacturers from public, anon, authenticated, service_role;
revoke all on table public.machine_models from public, anon, authenticated, service_role;
revoke all on table public.machines from public, anon, authenticated, service_role;
revoke all on table public.counter_types from public, anon, authenticated, service_role;

grant select on table public.manufacturers to authenticated;
grant select on table public.machine_models to authenticated;
grant select on table public.counter_types to authenticated;

grant select on table public.machines to authenticated;
grant insert (
  account_id,
  branch_id,
  machine_model_id,
  machine_code,
  display_name,
  serial_number,
  installed_on,
  status,
  timezone,
  notes,
  is_active
) on table public.machines to authenticated;
grant update (
  branch_id,
  machine_model_id,
  machine_code,
  display_name,
  serial_number,
  installed_on,
  status,
  timezone,
  notes,
  is_active
) on table public.machines to authenticated;

-- Trusted platform administration uses service_role; tenant roles have no
-- catalog write grants and RLS supplies no authenticated catalog write policy.
grant select, insert, update on table public.manufacturers to service_role;
grant select, insert, update on table public.machine_models to service_role;
grant select, insert, update on table public.machines to service_role;
grant select, insert, update on table public.counter_types to service_role;

-- Explicit anonymous denial, including the new trigger functions.
revoke all on table public.manufacturers from anon;
revoke all on table public.machine_models from anon;
revoke all on table public.machines from anon;
revoke all on table public.counter_types from anon;
revoke all on function public.set_archivable_catalog_audit_fields() from anon;
revoke all on function public.set_machine_audit_fields() from anon;
