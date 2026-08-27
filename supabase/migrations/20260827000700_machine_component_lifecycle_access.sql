-- M2.3B lifecycle read access and controlled initialization boundary.

revoke all on type public.machine_component_lifecycle_status from public;
revoke all on type public.component_installation_source from public;
grant usage on type public.machine_component_lifecycle_status, public.component_installation_source
  to authenticated, service_role;

alter table public.machine_component_lifecycles enable row level security;

create policy machine_component_lifecycles_select_members
on public.machine_component_lifecycles
for select to authenticated
using (public.is_account_member(account_id));

revoke all on table public.machine_component_lifecycles
  from public, anon, authenticated, service_role;
revoke all on table public.machine_component_health
  from public, anon, authenticated, service_role;

grant select on table public.machine_component_lifecycles, public.machine_component_health
  to authenticated;
grant select, insert, update, delete on table public.machine_component_lifecycles
  to service_role;
grant select on table public.machine_component_health to service_role;

-- Authenticated clients intentionally receive no direct INSERT/UPDATE/DELETE.
-- Initialization is available only through initialize_machine_component_lifecycle.

