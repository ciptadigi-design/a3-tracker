-- M2.3A RLS and least-privilege grants.

revoke all on type public.component_tracking_method from public;
grant usage on type public.component_tracking_method to authenticated, service_role;

alter table public.components enable row level security;
alter table public.machine_model_components enable row level security;

create policy components_select_visible on public.components for select to authenticated
using (account_id is null or public.is_account_member(account_id));
create policy components_insert_owner_admin on public.components for insert to authenticated
with check (account_id is not null and public.has_account_role(account_id, array['owner','admin']::public.account_role[]));
create policy components_update_owner_admin on public.components for update to authenticated
using (account_id is not null and public.has_account_role(account_id, array['owner','admin']::public.account_role[]))
with check (account_id is not null and public.has_account_role(account_id, array['owner','admin']::public.account_role[]));
create policy components_delete_owner_admin on public.components for delete to authenticated
using (account_id is not null and public.has_account_role(account_id, array['owner','admin']::public.account_role[]));

create policy model_components_select_visible on public.machine_model_components for select to authenticated
using (account_id is null or public.is_account_member(account_id));
create policy model_components_insert_owner_admin on public.machine_model_components for insert to authenticated
with check (
  account_id is not null
  and public.has_account_role(account_id, array['owner','admin']::public.account_role[])
  and exists (
    select 1 from public.components c
    where c.id = component_id and (c.account_id is null or c.account_id = account_id)
  )
);
create policy model_components_update_owner_admin on public.machine_model_components for update to authenticated
using (account_id is not null and public.has_account_role(account_id, array['owner','admin']::public.account_role[]))
with check (
  account_id is not null
  and public.has_account_role(account_id, array['owner','admin']::public.account_role[])
  and exists (
    select 1 from public.components c
    where c.id = component_id and (c.account_id is null or c.account_id = account_id)
  )
);
create policy model_components_delete_owner_admin on public.machine_model_components for delete to authenticated
using (account_id is not null and public.has_account_role(account_id, array['owner','admin']::public.account_role[]));

revoke all on table public.components from public, anon, authenticated, service_role;
revoke all on table public.machine_model_components from public, anon, authenticated, service_role;
grant select on table public.components, public.machine_model_components to authenticated;
grant insert (account_id, code, name, category, description, manufacturer_id, part_number, default_tracking_method, is_active)
  on public.components to authenticated;
grant update (code, name, category, description, manufacturer_id, part_number, default_tracking_method, is_active)
  on public.components to authenticated;
grant delete on public.components to authenticated;
grant insert (account_id, machine_model_id, component_id, slot_code, display_order, tracking_method,
  baseline_expected_clicks, adaptive_enabled, healthy_threshold_percent, watch_threshold_percent,
  warning_threshold_percent, critical_threshold_percent, notes, is_active)
  on public.machine_model_components to authenticated;
grant update (component_id, slot_code, display_order, tracking_method, baseline_expected_clicks,
  adaptive_enabled, healthy_threshold_percent, watch_threshold_percent, warning_threshold_percent,
  critical_threshold_percent, notes, is_active)
  on public.machine_model_components to authenticated;
grant delete on public.machine_model_components to authenticated;
grant select, insert, update, delete on public.components, public.machine_model_components to service_role;

revoke all on table public.components, public.machine_model_components from anon;
