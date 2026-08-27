-- M2.4D follow-up: expose receipt/purchase/supplier lineage in replacement detail.

create or replace view public.component_replacement_history with (security_invoker=true) as
select event.id as replacement_event_id,event.account_id,event.branch_id,event.machine_id,
  machine.machine_code,machine.display_name as machine_name,event.model_component_profile_id,
  event.component_id,component.code as component_code,component.name as component_name,profile.tracking_method,
  event.slot_code_snapshot,event.previous_lifecycle_id,event.new_lifecycle_id,event.previous_installed_counter,
  event.replacement_counter,event.actual_usage,event.expected_at_install,event.baseline_expected_snapshot,
  event.adaptive_expected_snapshot,case when event.expected_at_install>0 then round(event.actual_usage/event.expected_at_install*100,2) end as performance_percent,
  event.replacement_reason,event.condition_at_removal,event.include_in_adaptive_learning,
  event.performed_by_user_id,event.performed_by_name_snapshot,event.replaced_at,event.notes,event.counter_reading_id,
  new_lifecycle.status as new_lifecycle_status,new_lifecycle.installed_counter as new_installed_counter,
  new_lifecycle.expected_at_install as new_expected_at_install,event.created_by,event.created_at,
  event.inventory_source,event.inventory_movement_id,event.external_inventory_reason,
  movement.inventory_item_id,inventory_item.sku as inventory_item_sku,inventory_item.name as inventory_item_name,
  movement.location_id as inventory_location_id,inventory_location.name as inventory_location_name,
  case when movement.quantity is null then null else -movement.quantity end as inventory_quantity,
  movement.unit_snapshot as inventory_unit,consumption.known_consumption_cost as inventory_consumption_cost,
  consumption.known_cost_quantity as inventory_known_cost_quantity,
  consumption.unknown_cost_quantity as inventory_unknown_cost_quantity,
  consumption.cost_is_complete as inventory_cost_is_complete,consumption.cost_layer_count as inventory_cost_layer_count,
  lifecycle_cost.realized_lifecycle_cost,lifecycle_cost.realized_cost_per_click,
  evidence.receipt_numbers as inventory_cost_receipts,evidence.purchase_numbers as inventory_cost_purchases,
  evidence.supplier_names as inventory_cost_suppliers
from public.component_replacement_events event
join public.machines machine on machine.id=event.machine_id
join public.components component on component.id=event.component_id
join public.machine_model_components profile on profile.id=event.model_component_profile_id
join public.machine_component_lifecycles new_lifecycle on new_lifecycle.id=event.new_lifecycle_id
left join public.inventory_movements movement on movement.id=event.inventory_movement_id
left join public.inventory_items inventory_item on inventory_item.id=movement.inventory_item_id
left join public.inventory_locations inventory_location on inventory_location.id=movement.location_id
left join public.inventory_consumption_cost_history consumption on consumption.outbound_movement_id=movement.id
left join public.component_lifecycle_costs lifecycle_cost on lifecycle_cost.lifecycle_id=event.previous_lifecycle_id
left join lateral (
  select string_agg(distinct history.receipt_number,', ' order by history.receipt_number) filter (where history.receipt_number is not null) as receipt_numbers,
    string_agg(distinct history.purchase_number,', ' order by history.purchase_number) filter (where history.purchase_number is not null) as purchase_numbers,
    string_agg(distinct history.supplier_name_snapshot,', ' order by history.supplier_name_snapshot) filter (where history.supplier_name_snapshot is not null) as supplier_names
  from public.inventory_cost_allocation_history history where history.outbound_movement_id=movement.id
) evidence on true;

comment on view public.component_replacement_history is
  'Replacement/lifecycle history with derived FIFO consumption cost and receipt, purchase, and supplier lineage.';
