-- Launch-critical Machine Cost correction: period clicks follow the authoritative
-- Daily Counter event-attribution model. Each effective reading contributes its
-- database-derived usage when the reading timestamp belongs to the selected
-- timezone-resolved period. A first baseline has null usage and contributes zero.

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
  v_period_readings integer := 0;
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

  select machine.* into machine_record
  from public.machines machine
  where machine.id=target_machine_id and machine.account_id=target_account_id;
  if not found then
    raise exception 'machine not found in account' using errcode='P0002';
  end if;

  select branch.* into branch_record from public.branches branch where branch.id=machine_record.branch_id;
  select account.* into account_record from public.accounts account where account.id=target_account_id;
  v_timezone := coalesce(machine_record.timezone,branch_record.timezone,account_record.default_timezone);
  v_start_at := target_period_start::timestamp at time zone v_timezone;
  v_end_at := (target_period_end+1)::timestamp at time zone v_timezone;

  select counter_type.id into total_counter_type_id
  from public.counter_types counter_type
  where lower(btrim(counter_type.code))='total_impressions'
  order by counter_type.is_active desc,counter_type.created_at,counter_type.id
  limit 1;

  if total_counter_type_id is not null then
    select count(*)::integer,coalesce(sum(history.usage),0)::numeric(20,4)
    into v_period_readings,v_total_clicks
    from public.machine_counter_history history
    where history.account_id=target_account_id
      and history.machine_id=target_machine_id
      and history.counter_type_id=total_counter_type_id
      and history.status='effective'
      and history.observed_at>=v_start_at
      and history.observed_at<v_end_at;

    if v_period_readings>0 then
      select history.reading_value,history.observed_at
      into v_start_counter,v_start_counter_at
      from public.machine_counter_history history
      where history.account_id=target_account_id
        and history.machine_id=target_machine_id
        and history.counter_type_id=total_counter_type_id
        and history.status='effective'
        and history.observed_at>=v_start_at
        and history.observed_at<v_end_at
      order by history.observed_at,history.created_at,history.reading_id
      limit 1;

      select history.reading_value,history.observed_at
      into v_end_counter,v_end_counter_at
      from public.machine_counter_history history
      where history.account_id=target_account_id
        and history.machine_id=target_machine_id
        and history.counter_type_id=total_counter_type_id
        and history.status='effective'
        and history.observed_at>=v_start_at
        and history.observed_at<v_end_at
      order by history.observed_at desc,history.created_at desc,history.reading_id desc
      limit 1;
    end if;
  end if;

  if v_period_readings=0 then
    v_counter_status := 'NO_DATA'::public.machine_counter_period_status;
    v_total_clicks := null;
  else
    v_counter_status := 'COMPLETE'::public.machine_counter_period_status;
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
    when v_total_events=0 then 'NO_CONSUMPTION'::public.machine_consumption_cost_status
    when v_unknown_events>0 then 'PARTIAL'::public.machine_consumption_cost_status
    else 'COMPLETE'::public.machine_consumption_cost_status
  end;

  v_cost_status := case
    when v_counter_status='NO_DATA' and v_total_events=0 then 'NO_DATA'::public.machine_component_cost_status
    when v_counter_status<>'COMPLETE' then 'INSUFFICIENT_COUNTER_DATA'::public.machine_component_cost_status
    when v_total_events=0 then 'NO_CONSUMPTION'::public.machine_component_cost_status
    when v_unknown_events>0 then 'PARTIAL'::public.machine_component_cost_status
    else 'COMPLETE'::public.machine_component_cost_status
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

comment on function public.get_machine_cost_period(uuid,uuid,date,date) is
  'Machine cost for inclusive operational dates. Total clicks sum database-derived usage on effective Total Impressions readings attributed to the timezone-resolved period; baselines contribute zero and no boundary reading is fabricated.';
