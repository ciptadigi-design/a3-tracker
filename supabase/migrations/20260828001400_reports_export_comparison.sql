-- M2.6B - comparative Reports projections and export-ready analytics.
-- These functions remain read-only and derive from the accepted M2.6A contracts.

create or replace function public.get_report_period_comparison(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,
  target_period_start date,target_period_end date,target_period_preset text
) returns table(
  metric_code text,metric_label text,display_order integer,
  current_period_start date,current_period_end date,previous_period_start date,previous_period_end date,
  current_value numeric,previous_value numeric,delta_percent numeric,delta_status text,
  current_evidence_status text,previous_evidence_status text
) language plpgsql stable security definer set search_path='' as $$
declare
  v_duration integer;
  v_previous_start date;
  v_previous_end date;
begin
  perform * from public.resolve_operational_report_scope(
    target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end
  );
  if target_period_preset not in ('today','this_week','this_month','last_month','this_year','custom') then
    raise exception 'valid report period preset is required' using errcode='22023';
  end if;
  v_previous_end:=target_period_start-1;
  v_previous_start:=case target_period_preset
    when 'today' then target_period_start-1
    when 'this_week' then target_period_start-7
    when 'this_month' then (target_period_start-interval '1 month')::date
    when 'last_month' then (target_period_start-interval '1 month')::date
    when 'this_year' then make_date(extract(year from target_period_start)::integer-1,1,1)
    else target_period_start-v_duration end;
  perform * from public.resolve_operational_report_scope(
    target_account_id,target_branch_id,target_machine_id,v_previous_start,v_previous_end
  );

  return query
  with current_economics as (
    select * from public.get_report_machine_economics(
      target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end
    )
  ), previous_economics as (
    select * from public.get_report_machine_economics(
      target_account_id,target_branch_id,target_machine_id,v_previous_start,v_previous_end
    )
  ), current_overview as (
    select * from public.get_report_overview(
      target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end
    )
  ), previous_overview as (
    select * from public.get_report_overview(
      target_account_id,target_branch_id,target_machine_id,v_previous_start,v_previous_end
    )
  ), current_status as (
    select count(*)::integer machine_count,
      count(*) filter(where e.total_clicks is null)::integer missing_counter_count,
      count(*) filter(where e.standard_economics_status='PARTIAL')::integer partial_cost_count,
      count(*) filter(where e.revenue_status in ('PARTIAL','NO_PRICE'))::integer partial_price_count,
      count(*) filter(where e.standard_contribution_status='PARTIAL_COST')::integer contribution_cost_count,
      count(*) filter(where e.standard_contribution_status='UNAVAILABLE_REVENUE')::integer contribution_price_count
    from current_economics e
  ), previous_status as (
    select count(*)::integer machine_count,
      count(*) filter(where e.total_clicks is null)::integer missing_counter_count,
      count(*) filter(where e.standard_economics_status='PARTIAL')::integer partial_cost_count,
      count(*) filter(where e.revenue_status in ('PARTIAL','NO_PRICE'))::integer partial_price_count,
      count(*) filter(where e.standard_contribution_status='PARTIAL_COST')::integer contribution_cost_count,
      count(*) filter(where e.standard_contribution_status='UNAVAILABLE_REVENUE')::integer contribution_price_count
    from previous_economics e
  ), values_by_metric as (
    select metric.metric_code,metric.metric_label,metric.display_order,
      metric.current_value,metric.previous_value,metric.current_status,metric.previous_status
    from current_overview current_row cross join previous_overview previous_row
    cross join current_status current_state cross join previous_status previous_state
    cross join lateral (values
      ('TOTAL_CLICKS'::text,'Total Clicks'::text,1,current_row.total_clicks,previous_row.total_clicks,
        case when current_state.machine_count=0 then 'NO_DATA' when current_state.missing_counter_count=current_state.machine_count then 'NO_DATA'
          when current_state.missing_counter_count>0 then 'PARTIAL' else 'COMPLETE' end,
        case when previous_state.machine_count=0 then 'NO_DATA' when previous_state.missing_counter_count=previous_state.machine_count then 'NO_DATA'
          when previous_state.missing_counter_count>0 then 'PARTIAL' else 'COMPLETE' end),
      ('COMPONENT_CONSUMPTION'::text,'Component Consumption'::text,2,current_row.component_consumption_cost,previous_row.component_consumption_cost,
        case when current_state.machine_count=0 then 'NO_DATA' when current_state.partial_cost_count>0 then 'PARTIAL_COST' else 'COMPLETE' end,
        case when previous_state.machine_count=0 then 'NO_DATA' when previous_state.partial_cost_count>0 then 'PARTIAL_COST' else 'COMPLETE' end),
      ('ERROR_WASTE'::text,'Error / Waste'::text,3,
        current_row.machine_attributed_error_waste+current_row.branch_only_error_waste,
        previous_row.machine_attributed_error_waste+previous_row.branch_only_error_waste,
        case when current_state.machine_count=0 then 'NO_DATA' else 'COMPLETE' end,
        case when previous_state.machine_count=0 then 'NO_DATA' else 'COMPLETE' end),
      ('STANDARD_MACHINE_COST'::text,'Standard Machine Cost'::text,4,current_row.standard_machine_cost,previous_row.standard_machine_cost,
        case when current_state.machine_count=0 then 'NO_DATA' when current_state.partial_cost_count>0 then 'PARTIAL_COST' else 'COMPLETE' end,
        case when previous_state.machine_count=0 then 'NO_DATA' when previous_state.partial_cost_count>0 then 'PARTIAL_COST' else 'COMPLETE' end),
      ('STANDARD_COST_PER_CLICK'::text,'Cost / Click'::text,5,current_row.standard_cost_per_click,previous_row.standard_cost_per_click,
        case when current_state.machine_count=0 or coalesce(current_row.total_clicks,0)=0 then 'NO_DATA'
          when current_state.partial_cost_count>0 or current_state.missing_counter_count>0 then 'PARTIAL_COST' else 'COMPLETE' end,
        case when previous_state.machine_count=0 or coalesce(previous_row.total_clicks,0)=0 then 'NO_DATA'
          when previous_state.partial_cost_count>0 or previous_state.missing_counter_count>0 then 'PARTIAL_COST' else 'COMPLETE' end),
      ('ESTIMATED_MACHINE_REVENUE'::text,'Estimated Machine Revenue'::text,6,current_row.estimated_machine_revenue,previous_row.estimated_machine_revenue,
        case when current_state.machine_count=0 or current_state.missing_counter_count=current_state.machine_count then 'NO_DATA'
          when current_state.partial_price_count>0 or current_state.missing_counter_count>0 then 'PARTIAL_PRICE' else 'COMPLETE' end,
        case when previous_state.machine_count=0 or previous_state.missing_counter_count=previous_state.machine_count then 'NO_DATA'
          when previous_state.partial_price_count>0 or previous_state.missing_counter_count>0 then 'PARTIAL_PRICE' else 'COMPLETE' end),
      ('ESTIMATED_CONTRIBUTION'::text,'Estimated Contribution'::text,7,current_row.estimated_standard_contribution,previous_row.estimated_standard_contribution,
        case when current_state.machine_count=0 or current_state.missing_counter_count=current_state.machine_count then 'NO_DATA'
          when current_state.contribution_price_count>0 or current_state.missing_counter_count>0 then 'PARTIAL_PRICE'
          when current_state.contribution_cost_count>0 then 'PARTIAL_COST' else 'COMPLETE' end,
        case when previous_state.machine_count=0 or previous_state.missing_counter_count=previous_state.machine_count then 'NO_DATA'
          when previous_state.contribution_price_count>0 or previous_state.missing_counter_count>0 then 'PARTIAL_PRICE'
          when previous_state.contribution_cost_count>0 then 'PARTIAL_COST' else 'COMPLETE' end)
    ) as metric(metric_code,metric_label,display_order,current_value,previous_value,current_status,previous_status)
  )
  select metric.metric_code,metric.metric_label,metric.display_order,
    target_period_start,target_period_end,v_previous_start,v_previous_end,
    metric.current_value,metric.previous_value,
    case when metric.current_status='COMPLETE' and metric.previous_status='COMPLETE'
      and metric.current_value is not null and metric.previous_value is not null and metric.previous_value<>0
      then round((metric.current_value-metric.previous_value)/abs(metric.previous_value)*100,4) end,
    case when metric.current_status='NO_DATA' or metric.previous_status='NO_DATA' then 'NO_COMPARISON'
      when metric.current_status<>'COMPLETE' or metric.previous_status<>'COMPLETE' then 'PARTIAL'
      when metric.current_value is null or metric.previous_value is null then 'NO_COMPARISON'
      when metric.previous_value=0 and metric.current_value>0 then 'NEW'
      when metric.previous_value=0 then 'NO_COMPARISON'
      else 'COMPLETE' end,
    metric.current_status,metric.previous_status
  from values_by_metric metric order by metric.display_order;
end; $$;

create or replace function public.get_report_machine_comparison(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,
  target_period_start date,target_period_end date
) returns table(
  machine_id uuid,machine_code text,machine_name text,branch_id uuid,branch_code text,branch_name text,
  total_clicks numeric,standard_cost_per_click numeric,error_waste_cost numeric,estimated_machine_revenue numeric,
  estimated_standard_contribution numeric,standard_contribution_margin_percent numeric,
  revenue_status public.machine_revenue_status,cost_evidence_status public.machine_economics_status,
  contribution_status public.machine_contribution_status,comparison_status text,contribution_rank integer
) language sql stable security definer set search_path='' as $$
  with economics as (
    select e.*,
      case when e.total_clicks is null then 'NO_COUNTER_DATA'
        when e.revenue_status in ('PARTIAL','NO_PRICE') or e.standard_contribution_status='UNAVAILABLE_REVENUE' then 'PARTIAL_PRICE'
        when e.standard_economics_status='PARTIAL' or e.standard_contribution_status='PARTIAL_COST' then 'PARTIAL_COST'
        else 'COMPLETE' end comparison_status
    from public.get_report_machine_economics(
      target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end
    ) e
  ), ranked as (
    select economics.*,
      dense_rank() over(order by
        case when economics.comparison_status='COMPLETE' then economics.estimated_standard_contribution end desc nulls last,
        economics.machine_code,economics.machine_id)::integer raw_rank
    from economics
  )
  select ranked.machine_id,ranked.machine_code,ranked.machine_name,ranked.branch_id,ranked.branch_code,ranked.branch_name,
    ranked.total_clicks,ranked.standard_cost_per_click,ranked.error_waste_cost,ranked.estimated_machine_revenue,
    ranked.estimated_standard_contribution,ranked.standard_contribution_margin_percent,
    ranked.revenue_status,ranked.standard_economics_status,ranked.standard_contribution_status,ranked.comparison_status,
    case when ranked.comparison_status='COMPLETE' and ranked.estimated_standard_contribution is not null then ranked.raw_rank end as contribution_rank
  from ranked order by contribution_rank nulls last,ranked.branch_name,ranked.machine_code,ranked.machine_id;
$$;

create or replace function public.get_report_component_ranking(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,
  target_period_start date,target_period_end date
) returns table(
  component_id uuid,component_code text,component_name text,component_category text,
  replacement_count integer,known_consumed_cost numeric,known_cost_share_percent numeric,unknown_cost_events integer,
  evidence_status text,cost_rank integer,replacement_rank integer,unknown_evidence_rank integer
) language sql stable security definer set search_path='' as $$
  with aggregated as (
    select consumption.component_id,min(consumption.component_code) component_code,
      min(consumption.component_name) component_name,min(consumption.component_category) component_category,
      sum(consumption.replacement_count)::integer replacement_count,
      sum(consumption.known_consumed_cost)::numeric(30,2) known_consumed_cost,
      sum(consumption.unknown_cost_events)::integer unknown_cost_events
    from public.get_report_component_consumption(
      target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end
    ) consumption group by consumption.component_id
  ), with_total as (
    select aggregated.*,sum(aggregated.known_consumed_cost) over() total_known_cost from aggregated
  )
  select ranked.component_id,ranked.component_code,ranked.component_name,ranked.component_category,
    ranked.replacement_count,ranked.known_consumed_cost,
    case when ranked.total_known_cost>0 then round(ranked.known_consumed_cost/ranked.total_known_cost*100,4) end,
    ranked.unknown_cost_events,case when ranked.unknown_cost_events>0 then 'PARTIAL_COST' else 'COMPLETE' end,
    dense_rank() over(order by ranked.known_consumed_cost desc,ranked.component_name,ranked.component_id)::integer,
    dense_rank() over(order by ranked.replacement_count desc,ranked.component_name,ranked.component_id)::integer,
    dense_rank() over(order by ranked.unknown_cost_events desc,ranked.component_name,ranked.component_id)::integer
  from with_total ranked order by known_consumed_cost desc,component_name,component_id;
$$;

create or replace function public.get_report_error_summary(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,
  target_period_start date,target_period_end date,
  target_category public.operational_incident_category default null,
  target_status public.operational_incident_status default null
) returns table(dimension_type text,dimension_value text,incident_count integer,assessed_loss numeric)
language sql stable security definer set search_path='' as $$
  with incidents as (
    select * from public.get_report_error_waste(
      target_account_id,target_branch_id,target_machine_id,target_period_start,target_period_end,target_category,target_status
    ) where status<>'voided'
  ), dimensions(dimension_type,dimension_value,incident_count,assessed_loss) as (
    select 'CATEGORY'::text,category::text,count(*)::integer,coalesce(sum(assessed_loss),0)::numeric(30,2) from incidents group by category
    union all
    select 'TYPE',incident_type::text,count(*)::integer,coalesce(sum(assessed_loss),0)::numeric(30,2) from incidents group by incident_type
    union all
    select 'ATTRIBUTION',attribution_scope,count(*)::integer,coalesce(sum(assessed_loss),0)::numeric(30,2) from incidents group by attribution_scope
    union all
    select 'PIC',coalesce(nullif(btrim(responsible_name),''),'Unassigned'),count(*)::integer,
      coalesce(sum(assessed_loss),0)::numeric(30,2) from incidents group by coalesce(nullif(btrim(responsible_name),''),'Unassigned')
  )
  select dimensions.dimension_type,dimensions.dimension_value,dimensions.incident_count,dimensions.assessed_loss
  from dimensions order by dimensions.dimension_type,dimensions.assessed_loss desc,dimensions.dimension_value;
$$;

create or replace function public.get_report_inventory_analytics(
  target_account_id uuid,target_branch_id uuid,target_period_start date,target_period_end date
) returns table(
  purchases integer,purchase_value numeric,receipts integer,received_quantity numeric,received_value numeric,
  issues integer,replacement_issues integer,adjustments integer,transfer_legs integer,
  active_items integer,out_of_stock_items integer,low_stock_items integer,purchase_scope text
) language plpgsql stable security definer set search_path='' as $$
declare scope record;
begin
  select * into scope from public.resolve_operational_report_scope(
    target_account_id,target_branch_id,null,target_period_start,target_period_end
  );
  return query
  with base as (
    select * from public.get_report_inventory_activity(target_account_id,target_branch_id,target_period_start,target_period_end)
  ), received as (
    select coalesce(sum(line.acquisition_value),0)::numeric(30,2) received_value
    from public.inventory_receipts receipt
    join public.inventory_receipt_lines line on line.receipt_id=receipt.id
    join public.inventory_locations location on location.id=receipt.location_id
    join public.accounts account on account.id=receipt.account_id
    left join public.branches branch on branch.id=location.branch_id
    where receipt.account_id=target_account_id and (target_branch_id is null or location.branch_id=target_branch_id)
      and (receipt.received_at at time zone coalesce(branch.timezone,account.default_timezone))::date
        between target_period_start and target_period_end
  )
  select base.purchases,base.purchase_value,base.receipts,base.received_quantity,received.received_value,
    base.issues,base.replacement_issues,base.adjustments,base.transfer_legs,
    base.active_items,base.out_of_stock_items,base.low_stock_items,base.purchase_scope
  from base cross join received;
end; $$;

do $$ declare signature text; begin
  foreach signature in array array[
    'public.get_report_period_comparison(uuid,uuid,uuid,date,date,text)',
    'public.get_report_machine_comparison(uuid,uuid,uuid,date,date)',
    'public.get_report_component_ranking(uuid,uuid,uuid,date,date)',
    'public.get_report_error_summary(uuid,uuid,uuid,date,date,public.operational_incident_category,public.operational_incident_status)',
    'public.get_report_inventory_analytics(uuid,uuid,date,date)'
  ] loop
    execute format('revoke all on function %s from public,anon,authenticated,service_role',signature);
    execute format('grant execute on function %s to authenticated,service_role',signature);
  end loop;
end $$;

comment on function public.get_report_period_comparison(uuid,uuid,uuid,date,date,text) is
  'Current versus prior preset period (or immediately preceding equal-duration custom range). Delta and evidence compatibility are PostgreSQL authoritative.';
comment on function public.get_report_machine_comparison(uuid,uuid,uuid,date,date) is
  'Machine comparison projection. Incomplete economics are explicitly non-comparable and are not contribution-ranked.';
comment on function public.get_report_component_ranking(uuid,uuid,uuid,date,date) is
  'Cross-machine logical-component ranking. Share uses known consumed cost only; unknown evidence remains explicit.';
comment on function public.get_report_error_summary(uuid,uuid,uuid,date,date,public.operational_incident_category,public.operational_incident_status) is
  'Current assessed Error/Waste aggregation; branch-only evidence remains a distinct attribution and voided incidents are excluded.';
comment on function public.get_report_inventory_analytics(uuid,uuid,date,date) is
  'Operational purchase, receipt, movement, and stock context. Purchase/receipt value is never machine consumed cost.';
