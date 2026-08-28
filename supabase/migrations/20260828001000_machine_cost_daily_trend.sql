-- PRE-M2.5C UX consolidation: compact operational daily trend for the selected
-- Machine Cost period. Clicks retain Daily Counter event attribution; Standard
-- known cost is component consumption plus assessed Error / Waste only.

create function public.get_machine_cost_daily_trend(
  target_account_id uuid,
  target_machine_id uuid,
  target_period_start date,
  target_period_end date
)
returns table (
  operational_date date,
  daily_clicks numeric,
  counter_readings integer,
  component_events integer,
  error_waste_events integer,
  known_component_cost numeric,
  known_error_waste_cost numeric,
  known_daily_cost numeric,
  unknown_cost_events integer,
  cost_evidence_status text
)
language plpgsql
stable
security definer
set search_path = ''
as $$
declare
  machine_record public.machines%rowtype;
  branch_record public.branches%rowtype;
  account_record public.accounts%rowtype;
  v_timezone text;
  v_start_at timestamptz;
  v_end_at timestamptz;
begin
  if (select auth.uid()) is null then
    raise exception 'authentication required' using errcode = '42501';
  end if;
  if not public.is_account_member(target_account_id) then
    raise exception 'active account membership required' using errcode = '42501';
  end if;
  if target_period_start is null or target_period_end is null then
    raise exception 'period start and end are required' using errcode = '22004';
  end if;
  if target_period_end < target_period_start then
    raise exception 'period end must be on or after period start' using errcode = '22007';
  end if;

  select machine.* into machine_record
  from public.machines machine
  where machine.id = target_machine_id and machine.account_id = target_account_id;
  if not found then
    raise exception 'machine not found in account' using errcode = 'P0002';
  end if;

  select branch.* into branch_record from public.branches branch where branch.id = machine_record.branch_id;
  select account.* into account_record from public.accounts account where account.id = target_account_id;
  v_timezone := coalesce(machine_record.timezone, branch_record.timezone, account_record.default_timezone);
  v_start_at := target_period_start::timestamp at time zone v_timezone;
  v_end_at := (target_period_end + 1)::timestamp at time zone v_timezone;

  return query
  with dates as (
    select generated::date as operational_date
    from pg_catalog.generate_series(target_period_start::timestamp, target_period_end::timestamp, interval '1 day') generated
  ), counter_daily as (
    select (history.observed_at at time zone v_timezone)::date as operational_date,
      count(*)::integer as counter_readings,
      coalesce(sum(history.usage), 0)::numeric(20,4) as daily_clicks
    from public.machine_counter_history history
    where history.account_id = target_account_id
      and history.machine_id = target_machine_id
      and lower(btrim(history.counter_type_code)) = 'total_impressions'
      and history.status = 'effective'
      and history.observed_at >= v_start_at and history.observed_at < v_end_at
    group by (history.observed_at at time zone v_timezone)::date
  ), component_daily as (
    select (event.occurred_at at time zone v_timezone)::date as operational_date,
      count(*)::integer as component_events,
      count(*) filter (where event.cost_is_complete or event.known_consumption_cost > 0)::integer as known_evidence,
      count(*) filter (where not event.cost_is_complete)::integer as unknown_events,
      coalesce(sum(event.known_consumption_cost), 0)::numeric(30,2) as known_cost
    from public.machine_component_consumption_events event
    where event.account_id = target_account_id
      and event.machine_id = target_machine_id
      and event.occurred_at >= v_start_at and event.occurred_at < v_end_at
    group by (event.occurred_at at time zone v_timezone)::date
  ), error_daily as (
    select (incident.occurred_at at time zone v_timezone)::date as operational_date,
      count(*)::integer as error_events,
      count(*) filter (where incident.assessed_loss > 0)::integer as known_evidence,
      count(*) filter (where incident.assessed_loss = 0)::integer as unknown_events,
      coalesce(sum(incident.assessed_loss) filter (where incident.assessed_loss > 0), 0)::numeric(30,2) as known_cost
    from public.operational_incidents incident
    where incident.account_id = target_account_id
      and incident.machine_id = target_machine_id
      and incident.status <> 'voided'
      and incident.occurred_at >= v_start_at and incident.occurred_at < v_end_at
    group by (incident.occurred_at at time zone v_timezone)::date
  )
  select dates.operational_date,
    case when coalesce(counter_daily.counter_readings, 0) > 0 then counter_daily.daily_clicks else null end,
    coalesce(counter_daily.counter_readings, 0)::integer,
    coalesce(component_daily.component_events, 0)::integer,
    coalesce(error_daily.error_events, 0)::integer,
    case when coalesce(component_daily.known_evidence, 0) > 0 then component_daily.known_cost else null end,
    case when coalesce(error_daily.known_evidence, 0) > 0 then error_daily.known_cost else null end,
    case when coalesce(component_daily.known_evidence, 0) + coalesce(error_daily.known_evidence, 0) > 0
      then (coalesce(component_daily.known_cost, 0) + coalesce(error_daily.known_cost, 0))::numeric(30,2)
      else null end,
    (coalesce(component_daily.unknown_events, 0) + coalesce(error_daily.unknown_events, 0))::integer,
    case
      when coalesce(component_daily.component_events, 0) + coalesce(error_daily.error_events, 0) = 0 then 'NONE'
      when coalesce(component_daily.unknown_events, 0) + coalesce(error_daily.unknown_events, 0) > 0 then 'PARTIAL'
      else 'COMPLETE'
    end
  from dates
  left join counter_daily on counter_daily.operational_date = dates.operational_date
  left join component_daily on component_daily.operational_date = dates.operational_date
  left join error_daily on error_daily.operational_date = dates.operational_date
  order by dates.operational_date;
end;
$$;

revoke all on function public.get_machine_cost_daily_trend(uuid,uuid,date,date) from public, anon, authenticated, service_role;
grant execute on function public.get_machine_cost_daily_trend(uuid,uuid,date,date) to authenticated, service_role;

comment on function public.get_machine_cost_daily_trend(uuid,uuid,date,date) is
  'Database-authoritative operational dates, effective Daily Counter usage, and Standard known cost. Missing and partial cost evidence remain explicit; Advanced costs are excluded.';
