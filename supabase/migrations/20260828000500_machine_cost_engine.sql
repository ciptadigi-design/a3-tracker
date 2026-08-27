-- A3 Tracker V2 - M2.5A authoritative component-consumption machine cost engine.
-- Period inputs are inclusive operational dates; the resolved timezone converts them
-- to a half-open [period_start_at, period_end_at) timestamptz interval.

create type public.machine_counter_period_status as enum (
  'COMPLETE',
  'NO_DATA',
  'INSUFFICIENT_START',
  'INSUFFICIENT_END'
);

create type public.machine_consumption_cost_status as enum (
  'COMPLETE',
  'PARTIAL',
  'NO_CONSUMPTION'
);

create type public.machine_component_cost_status as enum (
  'COMPLETE',
  'PARTIAL',
  'NO_CONSUMPTION',
  'INSUFFICIENT_COUNTER_DATA',
  'NO_DATA'
);

create view public.machine_component_consumption_events with (security_invoker=true) as
select event.id as replacement_event_id,
  event.account_id,
  event.branch_id,
  event.machine_id,
  event.component_id,
  component.code as component_code,
  component.name as component_name,
  coalesce(nullif(btrim(component.category),''),'Other') as component_category,
  event.replaced_at as occurred_at,
  event.inventory_source,
  event.inventory_movement_id,
  movement.inventory_item_id,
  case when movement.quantity is null then null else -movement.quantity end::numeric(20,4) as consumed_quantity,
  coalesce(consumption.known_cost_quantity,0)::numeric(20,4) as known_cost_quantity,
  coalesce(consumption.unknown_cost_quantity,0)::numeric(20,4) as unknown_cost_quantity,
  coalesce(consumption.known_consumption_cost,0)::numeric(30,2) as known_consumption_cost,
  case
    when event.inventory_source='inventory'
      and event.inventory_movement_id is not null
      and consumption.cost_is_complete is true
    then true
    else false
  end as cost_is_complete,
  case
    when event.inventory_source='external_untracked' then 'EXTERNAL_UNTRACKED'
    when event.inventory_movement_id is null then 'NO_INVENTORY_EVIDENCE'
    when consumption.cost_is_complete is not true then 'UNKNOWN_COST_BASIS'
    else 'KNOWN'
  end as cost_evidence_state
from public.component_replacement_events event
join public.components component on component.id=event.component_id
left join public.inventory_movements movement on movement.id=event.inventory_movement_id
left join public.inventory_consumption_cost_history consumption
  on consumption.outbound_movement_id=event.inventory_movement_id;

create view public.machine_lifecycle_cost_evidence with (security_invoker=true) as
select lifecycle.lifecycle_id,
  lifecycle.account_id,
  lifecycle.branch_id,
  lifecycle.machine_id,
  lifecycle.component_id,
  component.code as component_code,
  component.name as component_name,
  coalesce(nullif(btrim(component.category),''),'Other') as component_category,
  lifecycle.slot_code,
  lifecycle.installed_at,
  lifecycle.removed_at,
  lifecycle.actual_usage,
  lifecycle.installed_component_cost,
  lifecycle.cost_is_unknown,
  lifecycle.realized_lifecycle_cost,
  lifecycle.realized_cost_per_click
from public.component_lifecycle_costs lifecycle
join public.components component on component.id=lifecycle.component_id
where lifecycle.status='closed';

create or replace function public.get_machine_cost_period(
  target_account_id uuid,
  target_machine_id uuid,
  target_period_start date,
  target_period_end date
)
returns table (
  account_id uuid,
  branch_id uuid,
  machine_id uuid,
  machine_code text,
  machine_name text,
  resolved_timezone text,
  period_start date,
  period_end date,
  period_start_at timestamptz,
  period_end_at timestamptz,
  start_counter numeric,
  start_counter_at timestamptz,
  end_counter numeric,
  end_counter_at timestamptz,
  total_clicks numeric,
  counter_status public.machine_counter_period_status,
  total_consumption_events integer,
  known_consumption_events integer,
  unknown_consumption_events integer,
  known_consumption_quantity numeric,
  unknown_consumption_quantity numeric,
  known_consumption_cost numeric,
  consumption_event_coverage_percent numeric,
  consumption_status public.machine_consumption_cost_status,
  cost_status public.machine_component_cost_status,
  known_component_cost_per_click numeric,
  purchase_cost_context numeric,
  ending_known_inventory_cost_context numeric,
  ending_known_inventory_quantity_context numeric,
  ending_unknown_inventory_quantity_context numeric,
  component_breakdown jsonb,
  realized_lifecycle_evidence jsonb
)
language plpgsql
stable
security definer
set search_path=''
as $$
declare
  machine_record public.machines%rowtype;
  branch_record public.branches%rowtype;
  account_record public.accounts%rowtype;
  total_counter_type_id uuid;
  v_timezone text;
  v_start_at timestamptz;
  v_end_at timestamptz;
  v_start_counter numeric(20,4);
  v_start_counter_at timestamptz;
  v_end_counter numeric(20,4);
  v_end_counter_at timestamptz;
  v_total_clicks numeric(20,4);
  v_counter_status public.machine_counter_period_status;
  v_total_events integer := 0;
  v_known_events integer := 0;
  v_unknown_events integer := 0;
  v_known_quantity numeric(20,4) := 0;
  v_unknown_quantity numeric(20,4) := 0;
  v_known_cost numeric(30,2) := 0;
  v_coverage numeric(7,2);
  v_consumption_status public.machine_consumption_cost_status;
  v_cost_status public.machine_component_cost_status;
  v_cost_per_click numeric(30,4);
  v_purchase_cost numeric(30,2) := 0;
  v_inventory_cost numeric(30,2) := 0;
  v_inventory_known_quantity numeric(20,4) := 0;
  v_inventory_unknown_quantity numeric(20,4) := 0;
  v_breakdown jsonb := '[]'::jsonb;
  v_lifecycle_evidence jsonb := '[]'::jsonb;
begin
  if (select auth.uid()) is null then
    raise exception 'authentication required' using errcode='42501';
  end if;
  if not public.is_account_member(target_account_id) then
    raise exception 'active account membership required' using errcode='42501';
  end if;
  if target_period_start is null or target_period_end is null then
    raise exception 'period start and end are required' using errcode='22004';
  end if;
  if target_period_end < target_period_start then
    raise exception 'period end must be on or after period start' using errcode='22007';
  end if;

  select * into machine_record
  from public.machines
  where id=target_machine_id and account_id=target_account_id;
  if not found then
    raise exception 'machine not found in account' using errcode='P0002';
  end if;

  select * into branch_record from public.branches where id=machine_record.branch_id;
  select * into account_record from public.accounts where id=target_account_id;
  v_timezone := coalesce(machine_record.timezone,branch_record.timezone,account_record.default_timezone);
  v_start_at := target_period_start::timestamp at time zone v_timezone;
  v_end_at := (target_period_end+1)::timestamp at time zone v_timezone;

  select counter_type.id into total_counter_type_id
  from public.counter_types counter_type
  where lower(btrim(counter_type.code))='total_impressions'
  order by counter_type.is_active desc,counter_type.created_at,counter_type.id
  limit 1;

  if total_counter_type_id is not null then
    select reading.reading_value,reading.observed_at
      into v_start_counter,v_start_counter_at
    from public.counter_readings reading
    where reading.account_id=target_account_id
      and reading.machine_id=target_machine_id
      and reading.counter_type_id=total_counter_type_id
      and reading.status='effective'
      and reading.observed_at<=v_start_at
    order by reading.observed_at desc,reading.created_at desc,reading.id desc
    limit 1;

    select reading.reading_value,reading.observed_at
      into v_end_counter,v_end_counter_at
    from public.counter_readings reading
    where reading.account_id=target_account_id
      and reading.machine_id=target_machine_id
      and reading.counter_type_id=total_counter_type_id
      and reading.status='effective'
      and reading.observed_at>v_start_at
      and reading.observed_at<=v_end_at
    order by reading.observed_at desc,reading.created_at desc,reading.id desc
    limit 1;
  end if;

  if v_start_counter is null and v_end_counter is null then
    v_counter_status := 'NO_DATA';
  elsif v_start_counter is null then
    v_counter_status := 'INSUFFICIENT_START';
  elsif v_end_counter is null then
    v_counter_status := 'INSUFFICIENT_END';
  else
    v_counter_status := 'COMPLETE';
    v_total_clicks := v_end_counter-v_start_counter;
  end if;

  select count(*)::integer,
    count(*) filter (where event.cost_is_complete)::integer,
    count(*) filter (where not event.cost_is_complete)::integer,
    coalesce(sum(event.known_cost_quantity),0)::numeric(20,4),
    coalesce(sum(event.unknown_cost_quantity),0)::numeric(20,4),
    coalesce(sum(event.known_consumption_cost),0)::numeric(30,2)
  into v_total_events,v_known_events,v_unknown_events,v_known_quantity,v_unknown_quantity,v_known_cost
  from public.machine_component_consumption_events event
  where event.account_id=target_account_id
    and event.machine_id=target_machine_id
    and event.occurred_at>=v_start_at
    and event.occurred_at<v_end_at;

  v_coverage := case when v_total_events=0 then null
    else round(v_known_events::numeric/v_total_events*100,2) end;
  v_consumption_status := case
    when v_total_events=0 then 'NO_CONSUMPTION'
    when v_unknown_events>0 then 'PARTIAL'
    else 'COMPLETE'
  end;

  v_cost_status := case
    when v_counter_status='NO_DATA' and v_total_events=0 then 'NO_DATA'
    when v_counter_status<>'COMPLETE' then 'INSUFFICIENT_COUNTER_DATA'
    when v_total_events=0 then 'NO_CONSUMPTION'
    when v_unknown_events>0 then 'PARTIAL'
    else 'COMPLETE'
  end;

  if v_counter_status='COMPLETE' and v_total_clicks>0 then
    v_cost_per_click := round(v_known_cost/v_total_clicks,4);
  end if;

  select coalesce(sum(line.line_total),0)::numeric(30,2)
  into v_purchase_cost
  from public.inventory_purchases purchase
  join public.inventory_purchase_lines line on line.purchase_id=purchase.id
  where purchase.account_id=target_account_id
    and purchase.status<>'cancelled'
    and purchase.purchase_date between target_period_start and target_period_end;

  with lot_position as (
    select lot.id,lot.source_quantity,lot.unit_cost,
      greatest(lot.source_quantity-coalesce(sum(allocation.quantity)
        filter (where outbound.occurred_at<v_end_at),0),0)::numeric(20,4) as remaining_quantity
    from public.inventory_cost_lots lot
    join public.inventory_locations location on location.id=lot.location_id
    left join public.inventory_cost_allocations allocation on allocation.source_cost_lot_id=lot.id
    left join public.inventory_movements outbound on outbound.id=allocation.outbound_movement_id
    where lot.account_id=target_account_id
      and location.branch_id=machine_record.branch_id
      and lot.effective_at<v_end_at
    group by lot.id
  )
  select coalesce(sum(position.remaining_quantity*position.unit_cost)
      filter (where position.unit_cost is not null),0)::numeric(30,2),
    coalesce(sum(position.remaining_quantity)
      filter (where position.unit_cost is not null),0)::numeric(20,4),
    coalesce(sum(position.remaining_quantity)
      filter (where position.unit_cost is null),0)::numeric(20,4)
  into v_inventory_cost,v_inventory_known_quantity,v_inventory_unknown_quantity
  from lot_position position
  where position.remaining_quantity>0;

  select coalesce(jsonb_agg(jsonb_build_object(
      'component_id',breakdown.component_id,
      'component_code',breakdown.component_code,
      'component_name',breakdown.component_name,
      'component_category',breakdown.component_category,
      'known_consumption_cost',breakdown.known_consumption_cost,
      'total_events',breakdown.total_events,
      'unknown_cost_events',breakdown.unknown_cost_events,
      'known_cost_percent',case when v_known_cost>0
        then round(breakdown.known_consumption_cost/v_known_cost*100,2) else 0 end
    ) order by breakdown.known_consumption_cost desc,breakdown.component_name),'[]'::jsonb)
  into v_breakdown
  from (
    select event.component_id,event.component_code,event.component_name,event.component_category,
      coalesce(sum(event.known_consumption_cost),0)::numeric(30,2) as known_consumption_cost,
      count(*)::integer as total_events,
      count(*) filter (where not event.cost_is_complete)::integer as unknown_cost_events
    from public.machine_component_consumption_events event
    where event.account_id=target_account_id
      and event.machine_id=target_machine_id
      and event.occurred_at>=v_start_at
      and event.occurred_at<v_end_at
    group by event.component_id,event.component_code,event.component_name,event.component_category
  ) breakdown;

  select coalesce(jsonb_agg(jsonb_build_object(
      'lifecycle_id',evidence.lifecycle_id,
      'component_id',evidence.component_id,
      'component_code',evidence.component_code,
      'component_name',evidence.component_name,
      'component_category',evidence.component_category,
      'slot_code',evidence.slot_code,
      'installed_at',evidence.installed_at,
      'removed_at',evidence.removed_at,
      'installed_component_cost',evidence.installed_component_cost,
      'actual_usage',evidence.actual_usage,
      'realized_lifecycle_cost',evidence.realized_lifecycle_cost,
      'realized_cost_per_click',evidence.realized_cost_per_click,
      'cost_is_unknown',evidence.cost_is_unknown
    ) order by evidence.removed_at desc,evidence.lifecycle_id),'[]'::jsonb)
  into v_lifecycle_evidence
  from public.machine_lifecycle_cost_evidence evidence
  where evidence.account_id=target_account_id
    and evidence.machine_id=target_machine_id
    and evidence.removed_at>=v_start_at
    and evidence.removed_at<v_end_at;

  return query select target_account_id,machine_record.branch_id,machine_record.id,
    machine_record.machine_code,machine_record.display_name,v_timezone,
    target_period_start,target_period_end,v_start_at,v_end_at,
    v_start_counter,v_start_counter_at,v_end_counter,v_end_counter_at,v_total_clicks,v_counter_status,
    v_total_events,v_known_events,v_unknown_events,v_known_quantity,v_unknown_quantity,v_known_cost,
    v_coverage,v_consumption_status,v_cost_status,v_cost_per_click,v_purchase_cost,
    v_inventory_cost,v_inventory_known_quantity,v_inventory_unknown_quantity,
    v_breakdown,v_lifecycle_evidence;
end;
$$;

revoke all on table public.machine_component_consumption_events,public.machine_lifecycle_cost_evidence
  from public,anon,authenticated,service_role;
grant select on table public.machine_component_consumption_events,public.machine_lifecycle_cost_evidence
  to authenticated,service_role;

revoke all on function public.get_machine_cost_period(uuid,uuid,date,date)
  from public,anon,authenticated,service_role;
grant execute on function public.get_machine_cost_period(uuid,uuid,date,date)
  to authenticated,service_role;

comment on view public.machine_component_consumption_events is
  'Machine-attributed replacement consumption events derived from immutable M2.4D FIFO evidence; unknown cost is never coerced to zero evidence.';
comment on view public.machine_lifecycle_cost_evidence is
  'Completed lifecycle economics for analysis only; these values are not added to period consumption cost.';
comment on function public.get_machine_cost_period(uuid,uuid,date,date) is
  'M2.5A component-consumption machine cost contract for inclusive operational dates. Counter boundaries use latest effective readings at/before start and after start through end boundary.';
