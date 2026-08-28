-- M2.6A - read-only operational Reports foundation.
-- Reports project existing facts and authoritative economics; no report totals are stored.

create index inventory_movements_account_time_idx
  on public.inventory_movements(account_id,occurred_at desc,created_at desc);
create index inventory_receipts_account_time_idx
  on public.inventory_receipts(account_id,received_at desc,created_at desc);

create or replace function public.resolve_operational_report_scope(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,
  target_period_start date,target_period_end date
) returns table(resolved_timezone text,period_start_at timestamptz,period_end_at timestamptz)
language plpgsql stable security definer set search_path='' as $$
declare account_record public.accounts%rowtype; branch_record public.branches%rowtype; machine_record public.machines%rowtype; v_timezone text;
begin
  if (select auth.uid()) is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.is_account_member(target_account_id) then raise exception 'active account membership required' using errcode='42501'; end if;
  if target_period_start is null or target_period_end is null or target_period_end<target_period_start then
    raise exception 'valid report period is required' using errcode='22007'; end if;
  select * into account_record from public.accounts where id=target_account_id and status='active';
  if not found then raise exception 'active account not found' using errcode='P0002'; end if;
  if target_branch_id is not null then
    select * into branch_record from public.branches where id=target_branch_id and account_id=target_account_id and is_active;
    if not found then raise exception 'active branch not found in account' using errcode='P0002'; end if;
  end if;
  if target_machine_id is not null then
    select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active;
    if not found then raise exception 'active machine not found in account' using errcode='P0002'; end if;
    if target_branch_id is not null and machine_record.branch_id<>target_branch_id then
      raise exception 'machine is outside selected branch' using errcode='22023'; end if;
  end if;
  v_timezone:=coalesce(machine_record.timezone,branch_record.timezone,account_record.default_timezone);
  return query select v_timezone,target_period_start::timestamp at time zone v_timezone,
    (target_period_end+1)::timestamp at time zone v_timezone;
end; $$;

create or replace function public.get_report_machine_economics(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table(
  machine_id uuid,machine_code text,machine_name text,branch_id uuid,branch_code text,branch_name text,resolved_timezone text,
  total_clicks numeric,component_consumption_cost numeric,error_waste_cost numeric,standard_machine_cost numeric,standard_cost_per_click numeric,
  current_selling_price_per_click numeric,period_end_selling_price_per_click numeric,period_price_count integer,priced_clicks numeric,unpriced_clicks numeric,
  estimated_machine_revenue numeric,revenue_status public.machine_revenue_status,estimated_standard_contribution numeric,
  standard_contribution_per_click numeric,standard_contribution_margin_percent numeric,standard_contribution_status public.machine_contribution_status,
  standard_economics_status public.machine_economics_status,advanced_enabled boolean,full_machine_operating_cost numeric,
  full_cost_per_click numeric,estimated_full_contribution numeric,full_contribution_per_click numeric,full_contribution_margin_percent numeric,
  full_contribution_status public.machine_contribution_status
) language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end);
  return query
  select machine.id,machine.machine_code,machine.display_name,branch.id,branch.code,branch.name,economics.resolved_timezone,
    economics.total_clicks,economics.known_component_consumption_cost,economics.known_error_waste_cost,economics.known_standard_machine_cost,
    economics.known_standard_cost_per_click,economics.current_selling_price_per_click,economics.period_end_selling_price_per_click,
    economics.period_price_count,economics.priced_clicks,economics.unpriced_clicks,economics.estimated_revenue,economics.revenue_status,
    economics.estimated_standard_contribution,economics.standard_contribution_per_click,economics.standard_contribution_margin_percent,
    economics.standard_contribution_status,economics.standard_economics_status,economics.advanced_machine_economics_enabled,
    economics.known_full_machine_operating_cost,economics.known_full_operating_cost_per_click,economics.estimated_full_contribution,
    economics.full_contribution_per_click,economics.full_contribution_margin_percent,economics.full_contribution_status
  from public.machines machine join public.branches branch on branch.id=machine.branch_id
  cross join lateral public.get_machine_economics_period(target_account_id,machine.id,target_period_start,target_period_end) economics
  where machine.account_id=target_account_id and machine.is_active
    and (target_branch_id is null or machine.branch_id=target_branch_id)
    and (target_machine_id is null or machine.id=target_machine_id)
  order by branch.name,machine.machine_code,machine.id;
end; $$;

create or replace function public.get_report_overview(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table(
  active_machines integer,total_clicks numeric,component_consumption_cost numeric,error_waste_cost numeric,standard_machine_cost numeric,
  standard_cost_per_click numeric,estimated_machine_revenue numeric,estimated_standard_contribution numeric,contribution_margin_percent numeric,
  machine_attributed_error_waste numeric,branch_only_error_waste numeric,report_status text,partial_machine_count integer
) language plpgsql stable security definer set search_path='' as $$
declare scope record;
begin
  select * into scope from public.resolve_operational_report_scope(target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end);
  return query with economics as (
    select * from public.get_report_machine_economics(target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end)
  ), economics_totals as (
    select count(*)::integer active_machines,coalesce(sum(e.total_clicks),0)::numeric total_clicks,
      coalesce(sum(e.component_consumption_cost),0)::numeric component_cost,coalesce(sum(e.error_waste_cost),0)::numeric error_cost,
      coalesce(sum(e.standard_machine_cost),0)::numeric standard_cost,sum(e.estimated_machine_revenue)::numeric estimated_revenue,
      sum(e.estimated_standard_contribution)::numeric estimated_contribution,
      count(*) filter(where e.revenue_status in ('PARTIAL','NO_PRICE'))::integer partial_price_count,
      count(*) filter(where e.standard_contribution_status='PARTIAL_COST')::integer partial_cost_count,
      count(*) filter(where e.total_clicks is null)::integer no_counter_count,
      count(*) filter(where e.revenue_status in ('PARTIAL','NO_PRICE') or e.standard_contribution_status='PARTIAL_COST' or e.total_clicks is null)::integer partial_machine_count,
      count(*) filter(where e.estimated_standard_contribution is null)::integer unavailable_contribution_count
    from economics e
  ), incident_totals as (
    select coalesce(sum(incident.assessed_loss) filter(where incident.machine_id is not null),0)::numeric(30,2) machine_loss,
      coalesce(sum(incident.assessed_loss) filter(where incident.machine_id is null),0)::numeric(30,2) branch_loss
    from public.operational_incidents incident join public.branches branch on branch.id=incident.branch_id
    left join public.machines machine on machine.id=incident.machine_id
    where incident.account_id=target_account_id and incident.status<>'voided'
      and (target_branch_id is null or incident.branch_id=target_branch_id)
      and (target_machine_id is null or incident.machine_id=target_machine_id)
      and ((incident.occurred_at at time zone coalesce(machine.timezone,branch.timezone,scope.resolved_timezone))::date between target_period_start and target_period_end)
  ) select totals.active_machines,totals.total_clicks,totals.component_cost,
    totals.error_cost,totals.standard_cost,
    case when totals.total_clicks>0 then round(totals.standard_cost/totals.total_clicks,4) end,
    totals.estimated_revenue,totals.estimated_contribution,
    case when coalesce(totals.estimated_revenue,0)>0 and totals.unavailable_contribution_count=0
      then round(totals.estimated_contribution/totals.estimated_revenue*100,4) end,
    incident_totals.machine_loss,incident_totals.branch_loss,
    case when totals.active_machines=0 then 'NO_DATA'
      when totals.partial_price_count>0 then 'PARTIAL_PRICE'
      when totals.partial_cost_count>0 then 'PARTIAL_COST'
      when totals.no_counter_count>0 then 'NO_COUNTER_DATA' else 'COMPLETE' end,
    totals.partial_machine_count
  from economics_totals totals cross join incident_totals;
end; $$;

create or replace function public.get_report_machine_performance(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table(machine_id uuid,machine_code text,machine_name text,branch_id uuid,branch_code text,branch_name text,resolved_timezone text,
  total_clicks numeric,active_days integer,daily_average_clicks numeric,latest_counter numeric,last_input_at timestamptz,counter_status text)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end);
  return query with machine_scope as (
    select machine.*,branch.code branch_code,branch.name branch_name,coalesce(machine.timezone,branch.timezone,account.default_timezone) tz
    from public.machines machine join public.branches branch on branch.id=machine.branch_id join public.accounts account on account.id=machine.account_id
    where machine.account_id=target_account_id and machine.is_active and (target_branch_id is null or machine.branch_id=target_branch_id)
      and (target_machine_id is null or machine.id=target_machine_id)
  ), usage as (
    select scope.id machine_id,coalesce(sum(history.usage),0)::numeric(20,4) clicks,
      count(distinct (history.observed_at at time zone scope.tz)::date) filter(where coalesce(history.usage,0)>0)::integer active_days
    from machine_scope scope left join public.machine_counter_history history on history.account_id=target_account_id and history.machine_id=scope.id
      and lower(btrim(history.counter_type_code))='total_impressions' and history.status='effective'
      and (history.observed_at at time zone scope.tz)::date between target_period_start and target_period_end
    group by scope.id
  ) select scope.id,scope.machine_code,scope.display_name,scope.branch_id,scope.branch_code,scope.branch_name,scope.tz,
    usage.clicks,usage.active_days,case when usage.active_days>0 then round(usage.clicks/usage.active_days,4) end,
    latest.reading_value,latest.observed_at,case when latest.reading_value is null then 'NO_DATA' else 'COMPLETE' end
  from machine_scope scope join usage on usage.machine_id=scope.id left join lateral (
    select history.reading_value,history.observed_at from public.machine_counter_history history
    where history.account_id=target_account_id and history.machine_id=scope.id and lower(btrim(history.counter_type_code))='total_impressions'
      and history.status='effective' and (history.observed_at at time zone scope.tz)::date between target_period_start and target_period_end
    order by history.observed_at desc,history.created_at desc,history.reading_id desc limit 1
  ) latest on true order by scope.branch_name,scope.machine_code,scope.id;
end; $$;

create or replace function public.get_report_daily_clicks(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table(operational_date date,total_clicks numeric,active_machines integer)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end);
  return query with machine_scope as (
    select machine.id,coalesce(machine.timezone,branch.timezone,account.default_timezone) tz
    from public.machines machine join public.branches branch on branch.id=machine.branch_id join public.accounts account on account.id=machine.account_id
    where machine.account_id=target_account_id and machine.is_active and (target_branch_id is null or machine.branch_id=target_branch_id)
      and (target_machine_id is null or machine.id=target_machine_id)
  ) select (history.observed_at at time zone scope.tz)::date,
    coalesce(sum(history.usage),0)::numeric(20,4),count(distinct scope.id) filter(where coalesce(history.usage,0)>0)::integer
  from machine_scope scope join public.machine_counter_history history on history.account_id=target_account_id and history.machine_id=scope.id
  where lower(btrim(history.counter_type_code))='total_impressions' and history.status='effective'
    and (history.observed_at at time zone scope.tz)::date between target_period_start and target_period_end
  group by (history.observed_at at time zone scope.tz)::date order by 1;
end; $$;

create or replace function public.get_report_component_consumption(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table(component_id uuid,component_code text,component_name text,component_category text,machine_id uuid,machine_code text,machine_name text,
  branch_id uuid,branch_name text,replacement_count integer,known_consumed_cost numeric,unknown_cost_events integer,average_observed_yield numeric)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end);
  return query select event.component_id,event.component_code,event.component_name,event.component_category,machine.id,machine.machine_code,machine.display_name,
    branch.id,branch.name,count(*)::integer,coalesce(sum(event.known_consumption_cost),0)::numeric(30,2),
    count(*) filter(where not event.cost_is_complete)::integer,round(avg(replacement.actual_usage),4)
  from public.machine_component_consumption_events event join public.component_replacement_events replacement on replacement.id=event.replacement_event_id
  join public.machines machine on machine.id=event.machine_id join public.branches branch on branch.id=machine.branch_id
  join public.accounts account on account.id=event.account_id
  where event.account_id=target_account_id and (target_branch_id is null or event.branch_id=target_branch_id)
    and (target_machine_id is null or event.machine_id=target_machine_id)
    and (event.occurred_at at time zone coalesce(machine.timezone,branch.timezone,account.default_timezone))::date between target_period_start and target_period_end
  group by event.component_id,event.component_code,event.component_name,event.component_category,machine.id,machine.machine_code,machine.display_name,branch.id,branch.name
  order by known_consumed_cost desc,event.component_name,machine.machine_code;
end; $$;

create or replace function public.get_report_error_waste(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date,
  target_category public.operational_incident_category default null,target_status public.operational_incident_status default null
) returns table(incident_id uuid,occurred_at timestamptz,branch_id uuid,branch_code text,branch_name text,machine_id uuid,machine_code text,machine_name text,
  attribution_scope text,category public.operational_incident_category,incident_type public.operational_incident_type,status public.operational_incident_status,
  responsible_name text,material_loss numeric,service_loss numeric,assessed_loss numeric,description text)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end);
  return query select incident.id,incident.occurred_at,branch.id,branch.code,branch.name,machine.id,machine.machine_code,machine.display_name,
    case when incident.machine_id is null then 'BRANCH_ONLY' else 'MACHINE' end,incident.category,incident.incident_type,incident.status,
    incident.responsible_name_snapshot,incident.material_loss,incident.service_loss,incident.assessed_loss,incident.description
  from public.operational_incidents incident join public.branches branch on branch.id=incident.branch_id
  join public.accounts account on account.id=incident.account_id left join public.machines machine on machine.id=incident.machine_id
  where incident.account_id=target_account_id and (target_branch_id is null or incident.branch_id=target_branch_id)
    and (target_machine_id is null or incident.machine_id=target_machine_id)
    and (target_category is null or incident.category=target_category) and (target_status is null or incident.status=target_status)
    and (incident.occurred_at at time zone coalesce(machine.timezone,branch.timezone,account.default_timezone))::date between target_period_start and target_period_end
  order by incident.occurred_at desc,incident.id;
end; $$;

create or replace function public.get_report_inventory_activity(
  target_account_id uuid,target_branch_id uuid,target_period_start date,target_period_end date
) returns table(purchases integer,purchase_value numeric,receipts integer,received_quantity numeric,issues integer,replacement_issues integer,
  adjustments integer,transfer_legs integer,active_items integer,out_of_stock_items integer,low_stock_items integer,purchase_scope text)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,null,target_period_start,target_period_end);
  return query with procurement as (
    select count(distinct purchase.id) filter(where purchase.status<>'cancelled')::integer purchase_count,
      coalesce(sum(line.line_total) filter(where purchase.status<>'cancelled'),0)::numeric(30,2) purchase_value
    from public.inventory_purchases purchase left join public.inventory_purchase_lines line on line.purchase_id=purchase.id
    where purchase.account_id=target_account_id and purchase.purchase_date between target_period_start and target_period_end
  ), receipt_totals as (
    select count(distinct receipt.id)::integer receipt_count,coalesce(sum(line.quantity),0)::numeric(20,4) quantity
    from public.inventory_receipts receipt join public.inventory_receipt_lines line on line.receipt_id=receipt.id
    join public.inventory_locations location on location.id=receipt.location_id join public.accounts account on account.id=receipt.account_id
    left join public.branches branch on branch.id=location.branch_id where receipt.account_id=target_account_id
      and (target_branch_id is null or location.branch_id=target_branch_id)
      and (receipt.received_at at time zone coalesce(branch.timezone,account.default_timezone))::date between target_period_start and target_period_end
  ), movement_totals as (
    select count(*) filter(where movement.movement_type='issue')::integer issue_count,
      count(*) filter(where movement.reference_type='component_replacement' and movement.movement_type='issue')::integer replacement_count,
      count(*) filter(where movement.movement_type in ('adjustment_in','adjustment_out'))::integer adjustment_count,
      count(*) filter(where movement.movement_type in ('transfer_in','transfer_out'))::integer transfer_count
    from public.inventory_movements movement join public.inventory_locations location on location.id=movement.location_id
    join public.accounts account on account.id=movement.account_id left join public.branches branch on branch.id=location.branch_id
    where movement.account_id=target_account_id and (target_branch_id is null or location.branch_id=target_branch_id)
      and (movement.occurred_at at time zone coalesce(branch.timezone,account.default_timezone))::date between target_period_start and target_period_end
  ), stock_quantities as (
    select item.id,item.is_active,item.minimum_stock,
      coalesce(sum(movement.quantity) filter(where target_branch_id is null or location.branch_id=target_branch_id),0)::numeric quantity
    from public.inventory_items item left join public.inventory_movements movement on movement.inventory_item_id=item.id
      and movement.account_id=item.account_id left join public.inventory_locations location on location.id=movement.location_id
    where item.account_id=target_account_id group by item.id
  ), stock as (
    select count(*) filter(where is_active)::integer active_count,
      count(*) filter(where is_active and quantity<=0)::integer out_count,
      count(*) filter(where is_active and minimum_stock is not null and quantity>0 and quantity<=minimum_stock)::integer low_count
    from stock_quantities
  ) select procurement.purchase_count,procurement.purchase_value,receipt_totals.receipt_count,receipt_totals.quantity,
    movement_totals.issue_count,movement_totals.replacement_count,movement_totals.adjustment_count,movement_totals.transfer_count,
    stock.active_count,stock.out_count,stock.low_count,'ACCOUNT_PURCHASES' from procurement,receipt_totals,movement_totals,stock;
end; $$;

create or replace function public.get_report_purchase_lines(target_account_id uuid,target_period_start date,target_period_end date)
returns table(purchase_id uuid,purchase_number text,external_reference text,supplier_name text,purchase_date date,status public.inventory_purchase_status,
  item_name text,item_sku text,ordered_quantity numeric,unit_price numeric,line_total numeric,received_quantity numeric,remaining_quantity numeric,unit text)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,null,null,target_period_start,target_period_end);
  return query select purchase.purchase_id,purchase.purchase_number,purchase.supplier_reference,purchase.supplier_name_snapshot,purchase.purchase_date,purchase.status,
    line.item_name_snapshot,nullif(line.item_sku_snapshot,''),line.ordered_quantity,line.unit_price,line.line_total,line.received_quantity,line.remaining_quantity,line.unit_snapshot::text
  from public.inventory_purchase_summary purchase join public.inventory_purchase_line_status line on line.purchase_id=purchase.purchase_id
  where purchase.account_id=target_account_id and purchase.purchase_date between target_period_start and target_period_end
  order by purchase.purchase_date desc,purchase.purchase_number,line.item_name_snapshot;
end; $$;

create or replace function public.get_report_inventory_stock(target_account_id uuid,target_branch_id uuid)
returns table(inventory_item_id uuid,item_name text,sku text,component_code text,component_name text,unit text,total_stock numeric,minimum_stock numeric,status text,location_breakdown jsonb)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,null,current_date,current_date);
  return query select item.id,item.name,item.sku,component.code,component.name,item.unit::text,
    coalesce(sum(balance.quantity) filter(where target_branch_id is null or location.branch_id=target_branch_id),0)::numeric(20,4),item.minimum_stock,
    case when coalesce(sum(balance.quantity) filter(where target_branch_id is null or location.branch_id=target_branch_id),0)<=0 then 'OUT_OF_STOCK'
      when item.minimum_stock is not null and coalesce(sum(balance.quantity) filter(where target_branch_id is null or location.branch_id=target_branch_id),0)<=item.minimum_stock then 'LOW_STOCK'
      else 'HEALTHY' end,
    coalesce(jsonb_agg(jsonb_build_object('location_name',location.name,'branch_id',location.branch_id,'quantity',balance.quantity)
      order by location.name) filter(where balance.location_id is not null and (target_branch_id is null or location.branch_id=target_branch_id)),'[]'::jsonb)
  from public.inventory_items item left join public.components component on component.id=item.component_id
  left join public.inventory_stock_balances balance on balance.inventory_item_id=item.id left join public.inventory_locations location on location.id=balance.location_id
  where item.account_id=target_account_id and item.is_active group by item.id,component.id order by item.name,item.id;
end; $$;

revoke all on function public.resolve_operational_report_scope(uuid,uuid,uuid,date,date) from public,anon,authenticated,service_role;
do $$ declare signature text; begin
  foreach signature in array array[
    'public.get_report_machine_economics(uuid,uuid,uuid,date,date)','public.get_report_overview(uuid,uuid,uuid,date,date)',
    'public.get_report_machine_performance(uuid,uuid,uuid,date,date)','public.get_report_daily_clicks(uuid,uuid,uuid,date,date)',
    'public.get_report_component_consumption(uuid,uuid,uuid,date,date)',
    'public.get_report_error_waste(uuid,uuid,uuid,date,date,public.operational_incident_category,public.operational_incident_status)',
    'public.get_report_inventory_activity(uuid,uuid,date,date)','public.get_report_purchase_lines(uuid,date,date)',
    'public.get_report_inventory_stock(uuid,uuid)'
  ] loop execute format('revoke all on function %s from public,anon,authenticated,service_role',signature);
    execute format('grant execute on function %s to authenticated,service_role',signature); end loop;
end $$;

comment on function public.get_report_machine_economics(uuid,uuid,uuid,date,date) is 'Read-only report projection of the authoritative M2.5C period economics contract.';
comment on function public.get_report_overview(uuid,uuid,uuid,date,date) is 'Read-only cross-machine overview. Machine Standard cost excludes branch-only Error/Waste; branch-only loss is returned separately.';
comment on function public.get_report_inventory_activity(uuid,uuid,date,date) is 'Operational inventory activity; purchases remain account-scoped commercial context and are never machine consumption.';
