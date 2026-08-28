-- Post-M2.5B acceptance policy: Standard economics is always authoritative;
-- Advanced operating-cost capture is an account feature that defaults off.

alter table public.accounts
  add column machine_economics_advanced_enabled boolean not null default false;

comment on column public.accounts.machine_economics_advanced_enabled is
  'Account policy flag for advanced machine operating-cost capture and Full economics presentation. Disabling never changes historical evidence.';

create or replace function public.set_machine_economics_advanced_enabled(
  target_account_id uuid,
  target_enabled boolean
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
  actor_id uuid := (select auth.uid());
  account_record public.accounts%rowtype;
begin
  if actor_id is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;
  if target_enabled is null then
    raise exception 'feature state is required' using errcode = '22004';
  end if;

  select * into account_record
  from public.accounts
  where id = target_account_id
  for update;

  if not found then
    raise exception 'account not found' using errcode = 'P0002';
  end if;
  if account_record.status <> 'active' then
    raise exception 'active account required' using errcode = '42501';
  end if;
  if not public.has_account_role(target_account_id, array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required' using errcode = '42501';
  end if;

  if account_record.machine_economics_advanced_enabled is distinct from target_enabled then
    update public.accounts
    set machine_economics_advanced_enabled = target_enabled,
        updated_at = statement_timestamp(),
        updated_by = actor_id
    where id = target_account_id;
  end if;

  return target_enabled;
end;
$$;

create or replace function public.enforce_advanced_machine_economics_enabled()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if not coalesce((
    select account.machine_economics_advanced_enabled
    from public.accounts account
    where account.id = new.account_id
      and account.status = 'active'
  ), false) then
    raise exception 'advanced machine economics is disabled for this account' using errcode = '42501';
  end if;
  return new;
end;
$$;

create trigger machine_operating_costs_require_advanced_feature
before insert on public.machine_operating_costs
for each row execute function public.enforce_advanced_machine_economics_enabled();

drop function public.get_machine_economics_period(uuid,uuid,date,date);

create function public.get_machine_economics_period(
  target_account_id uuid,
  target_machine_id uuid,
  target_period_start date,
  target_period_end date
)
returns table (
  account_id uuid,branch_id uuid,machine_id uuid,machine_code text,machine_name text,resolved_timezone text,
  period_start date,period_end date,period_start_at timestamptz,period_end_at timestamptz,
  start_counter numeric,start_counter_at timestamptz,end_counter numeric,end_counter_at timestamptz,total_clicks numeric,counter_status public.machine_counter_period_status,
  total_consumption_events integer,known_consumption_events integer,unknown_consumption_events integer,known_consumption_quantity numeric,unknown_consumption_quantity numeric,
  known_consumption_cost numeric,consumption_event_coverage_percent numeric,consumption_status public.machine_consumption_cost_status,cost_status public.machine_component_cost_status,
  known_component_cost_per_click numeric,purchase_cost_context numeric,ending_known_inventory_cost_context numeric,ending_known_inventory_quantity_context numeric,
  ending_unknown_inventory_quantity_context numeric,component_breakdown jsonb,realized_lifecycle_evidence jsonb,
  operating_cost_records integer,known_operating_cost numeric,operating_cost_breakdown jsonb,
  error_waste_events integer,known_error_waste_events integer,unknown_error_waste_events integer,known_error_waste_cost numeric,
  known_machine_operating_cost numeric,known_machine_operating_cost_per_click numeric,economics_status public.machine_economics_status,
  unknown_evidence_events integer,machine_economics_breakdown jsonb,
  advanced_machine_economics_enabled boolean,
  known_component_consumption_cost numeric,
  known_standard_machine_cost numeric,
  known_standard_cost_per_click numeric,
  known_advanced_operating_cost numeric,
  known_full_machine_operating_cost numeric,
  known_full_operating_cost_per_click numeric,
  standard_economics_status public.machine_economics_status,
  counter_complete boolean,
  standard_cost_complete boolean,
  full_cost_complete boolean,
  full_economics_available boolean
)
language plpgsql
stable
security definer
set search_path = ''
as $$
declare
  base record;
  v_advanced_enabled boolean := false;
  v_op_records integer := 0;
  v_op_cost numeric(30,2) := 0;
  v_op_breakdown jsonb := '[]'::jsonb;
  v_error_events integer := 0;
  v_known_error_events integer := 0;
  v_unknown_error_events integer := 0;
  v_error_cost numeric(30,2) := 0;
  v_standard_cost numeric(30,2) := 0;
  v_standard_cpc numeric(30,4);
  v_full_cost numeric(30,2) := 0;
  v_full_cpc numeric(30,4);
  v_status public.machine_economics_status;
  v_breakdown jsonb;
begin
  select * into base
  from public.get_machine_cost_period(target_account_id,target_machine_id,target_period_start,target_period_end);

  select account.machine_economics_advanced_enabled
  into v_advanced_enabled
  from public.accounts account
  where account.id = target_account_id;

  with allocated as (
    select cost.category,cost.id,
      case when cost.allocation_method='one_time' then cost.amount
        else round(cost.amount * (least(cost.period_end,target_period_end)-greatest(cost.period_start,target_period_start)+1)::numeric
          / (cost.period_end-cost.period_start+1)::numeric,2) end as allocated_amount
    from public.machine_operating_costs cost
    where cost.account_id=target_account_id and cost.machine_id=target_machine_id and cost.status='posted'
      and ((cost.allocation_method='one_time' and cost.effective_at>=base.period_start_at and cost.effective_at<base.period_end_at)
        or (cost.allocation_method='daily_proration_v1' and cost.period_start<=target_period_end and cost.period_end>=target_period_start))
  ), grouped as (
    select category,count(*)::integer record_count,sum(allocated_amount)::numeric(30,2) allocated_cost
    from allocated group by category
  )
  select coalesce(sum(record_count),0)::integer,coalesce(sum(allocated_cost),0)::numeric(30,2),
    coalesce(jsonb_agg(jsonb_build_object('category',category,'record_count',record_count,'known_cost',allocated_cost)
      order by allocated_cost desc,category),'[]'::jsonb)
  into v_op_records,v_op_cost,v_op_breakdown from grouped;

  select count(*)::integer,count(*) filter(where incident.assessed_loss>0)::integer,
    count(*) filter(where incident.assessed_loss=0)::integer,
    coalesce(sum(incident.assessed_loss) filter(where incident.assessed_loss>0),0)::numeric(30,2)
  into v_error_events,v_known_error_events,v_unknown_error_events,v_error_cost
  from public.operational_incidents incident
  where incident.account_id=target_account_id and incident.machine_id=target_machine_id
    and incident.status<>'voided' and incident.occurred_at>=base.period_start_at and incident.occurred_at<base.period_end_at;

  v_standard_cost := base.known_consumption_cost + v_error_cost;
  v_full_cost := v_standard_cost + v_op_cost;
  if base.counter_status='COMPLETE' and base.total_clicks>0 then
    v_standard_cpc := round(v_standard_cost/base.total_clicks,4);
    v_full_cpc := round(v_full_cost/base.total_clicks,4);
  end if;
  v_status := case
    when base.counter_status<>'COMPLETE' and base.total_consumption_events+v_error_events=0 and base.counter_status='NO_DATA'
      then 'NO_DATA'::public.machine_economics_status
    when base.counter_status<>'COMPLETE' then 'INSUFFICIENT_COUNTER_DATA'::public.machine_economics_status
    when base.unknown_consumption_events+v_unknown_error_events>0 then 'PARTIAL'::public.machine_economics_status
    else 'COMPLETE'::public.machine_economics_status end;
  v_breakdown := jsonb_build_array(
    jsonb_build_object('layer','component_consumption','known_cost',base.known_consumption_cost,'unknown_events',base.unknown_consumption_events),
    jsonb_build_object('layer','error_waste','known_cost',v_error_cost,'unknown_events',v_unknown_error_events),
    jsonb_build_object('layer','advanced_operating_cost','known_cost',v_op_cost,'unknown_events',0,'enabled',v_advanced_enabled));

  return query select
    base.account_id,base.branch_id,base.machine_id,base.machine_code,base.machine_name,base.resolved_timezone,
    base.period_start,base.period_end,base.period_start_at,base.period_end_at,base.start_counter,base.start_counter_at,base.end_counter,base.end_counter_at,
    base.total_clicks,base.counter_status,base.total_consumption_events,base.known_consumption_events,base.unknown_consumption_events,
    base.known_consumption_quantity,base.unknown_consumption_quantity,base.known_consumption_cost,base.consumption_event_coverage_percent,
    base.consumption_status,base.cost_status,base.known_component_cost_per_click,base.purchase_cost_context,base.ending_known_inventory_cost_context,
    base.ending_known_inventory_quantity_context,base.ending_unknown_inventory_quantity_context,base.component_breakdown,base.realized_lifecycle_evidence,
    v_op_records,v_op_cost,v_op_breakdown,v_error_events,v_known_error_events,v_unknown_error_events,v_error_cost,
    v_full_cost,v_full_cpc,v_status,base.unknown_consumption_events+v_unknown_error_events,v_breakdown,
    v_advanced_enabled,base.known_consumption_cost,v_standard_cost,v_standard_cpc,v_op_cost,v_full_cost,v_full_cpc,v_status,
    base.counter_status='COMPLETE',base.unknown_consumption_events+v_unknown_error_events=0,
    v_advanced_enabled and base.unknown_consumption_events+v_unknown_error_events=0,v_advanced_enabled;
end;
$$;

-- Append inbound FIFO cost evidence to the established movement history contract.
-- Existing columns and grants remain unchanged; read-only detail UI can now describe
-- opening, adjustment-in, receipt, and transfer-in evidence without raw identifiers.
create or replace view public.inventory_movement_history with (security_invoker=true) as
select movement.id as movement_id,movement.account_id,movement.inventory_item_id,item.sku,item.name as item_name,
  item.component_id,movement.location_id,location.code as location_code,location.name as location_name,location.branch_id,
  movement.movement_type,movement.quantity,movement.unit_snapshot,movement.occurred_at,movement.operational_person_id,
  movement.operational_person_name_snapshot,movement.reference_type,movement.reference_id,movement.reason,movement.notes,
  movement.client_request_id,movement.transfer_id,movement.created_by,movement.created_by_name_snapshot,movement.created_at,
  replacement.machine_id as replacement_machine_id,machine.machine_code as replacement_machine_code,
  machine.display_name as replacement_machine_name,replacement.component_id as replacement_component_id,
  component.code as replacement_component_code,component.name as replacement_component_name,
  receipt.purchase_id as receipt_purchase_id,receipt.purchase_number_snapshot as receipt_purchase_number,
  receipt.supplier_id as receipt_supplier_id,receipt.supplier_name_snapshot as receipt_supplier_name,
  receipt.receipt_number,receipt_line.unit_price_snapshot as receipt_unit_price,
  receipt.currency_code as receipt_currency_code,receipt_line.acquisition_value as receipt_acquisition_value,
  cost.known_cost_quantity,cost.unknown_cost_quantity,cost.known_consumption_cost as allocated_cost,
  cost.cost_is_complete,cost.cost_layer_count,
  inbound_cost.known_cost_quantity as inbound_known_cost_quantity,
  inbound_cost.unknown_cost_quantity as inbound_unknown_cost_quantity,
  inbound_cost.known_acquisition_cost as inbound_known_acquisition_cost,
  inbound_cost.cost_is_complete as inbound_cost_is_complete,
  inbound_cost.cost_layer_count as inbound_cost_layer_count
from public.inventory_movements movement
join public.inventory_items item on item.id=movement.inventory_item_id
join public.inventory_locations location on location.id=movement.location_id
left join public.component_replacement_events replacement on movement.reference_type='component_replacement' and replacement.id=movement.reference_id
left join public.machines machine on machine.id=replacement.machine_id
left join public.components component on component.id=replacement.component_id
left join public.inventory_receipts receipt on movement.reference_type='purchase_receipt' and receipt.id=movement.reference_id
left join public.inventory_receipt_lines receipt_line on receipt_line.inventory_movement_id=movement.id
left join lateral (
  select coalesce(sum(allocation.quantity) filter (where allocation.unit_cost is not null),0)::numeric(20,4) as known_cost_quantity,
    coalesce(sum(allocation.quantity) filter (where allocation.unit_cost is null),0)::numeric(20,4) as unknown_cost_quantity,
    coalesce(sum(allocation.allocated_cost),0)::numeric(30,2) as known_consumption_cost,
    bool_and(allocation.unit_cost is not null) as cost_is_complete,count(allocation.id)::integer as cost_layer_count
  from public.inventory_cost_allocations allocation where allocation.outbound_movement_id=movement.id
) cost on movement.movement_type in ('issue','adjustment_out','transfer_out')
left join lateral (
  select coalesce(sum(lot.source_quantity) filter (where lot.unit_cost is not null),0)::numeric(20,4) as known_cost_quantity,
    coalesce(sum(lot.source_quantity) filter (where lot.unit_cost is null),0)::numeric(20,4) as unknown_cost_quantity,
    coalesce(sum(lot.source_quantity*lot.unit_cost) filter (where lot.unit_cost is not null),0)::numeric(30,2) as known_acquisition_cost,
    bool_and(lot.unit_cost is not null) as cost_is_complete,count(lot.id)::integer as cost_layer_count
  from public.inventory_cost_lots lot where lot.inbound_movement_id=movement.id
) inbound_cost on movement.movement_type in ('opening_balance','receipt','adjustment_in','transfer_in');

revoke all on function public.set_machine_economics_advanced_enabled(uuid,boolean) from public,anon,authenticated,service_role;
grant execute on function public.set_machine_economics_advanced_enabled(uuid,boolean) to authenticated,service_role;
revoke all on function public.enforce_advanced_machine_economics_enabled() from public;
revoke all on function public.get_machine_economics_period(uuid,uuid,date,date) from public,anon,authenticated,service_role;
grant execute on function public.get_machine_economics_period(uuid,uuid,date,date) to authenticated,service_role;

comment on function public.set_machine_economics_advanced_enabled(uuid,boolean) is
  'Owner/admin idempotent account feature control. State changes do not create, void, delete, or rewrite operating-cost evidence.';
comment on function public.get_machine_economics_period(uuid,uuid,date,date) is
  'Database-authoritative Standard and Full machine economics. Standard is component consumption plus assessed error/waste; Full additionally includes advanced operating costs.';
