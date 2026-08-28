-- M2.5C - effective-dated machine selling price, utilization revenue, and contribution.
-- Posted price evidence is immutable. Interval ends are derived from the next posted
-- effective_from, so inserting a later price never rewrites historical rows.

create type public.machine_selling_price_status as enum ('posted','voided');
create type public.machine_revenue_status as enum ('COMPLETE','PARTIAL','NO_PRICE','NO_CLICKS');
create type public.machine_contribution_status as enum ('COMPLETE','PARTIAL_COST','UNAVAILABLE_REVENUE','NO_CLICKS','ZERO_REVENUE');

create table public.machine_selling_prices (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid not null,
  machine_id uuid not null,
  price_per_click numeric(30,4) not null,
  currency text not null default 'IDR',
  effective_from timestamptz not null,
  status public.machine_selling_price_status not null default 'posted',
  notes text,
  client_request_id uuid not null,
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  created_by_name_snapshot text not null,
  created_at timestamptz not null default statement_timestamp(),
  voided_by uuid references auth.users(id) on delete restrict,
  voided_by_name_snapshot text,
  voided_at timestamptz,
  void_reason text,
  void_client_request_id uuid,
  constraint machine_selling_prices_branch_scope_fkey foreign key (branch_id,account_id)
    references public.branches(id,account_id) on delete restrict,
  constraint machine_selling_prices_machine_scope_fkey foreign key (machine_id,account_id,branch_id)
    references public.machines(id,account_id,branch_id) on delete restrict,
  constraint machine_selling_prices_price_positive check (price_per_click>0),
  constraint machine_selling_prices_price_finite check (price_per_click::text not in ('NaN','Infinity','-Infinity')),
  constraint machine_selling_prices_price_scale check (round(price_per_click,4)=price_per_click),
  constraint machine_selling_prices_currency_idr check (currency='IDR'),
  constraint machine_selling_prices_notes_length check (notes is null or (nullif(btrim(notes),'') is not null and char_length(notes)<=1000)),
  constraint machine_selling_prices_actor_snapshot_present check (nullif(btrim(created_by_name_snapshot),'') is not null),
  constraint machine_selling_prices_void_shape check (
    (status='posted' and voided_by is null and voided_by_name_snapshot is null and voided_at is null and void_reason is null and void_client_request_id is null)
    or (status='voided' and voided_by is not null and nullif(btrim(voided_by_name_snapshot),'') is not null
      and voided_at is not null and nullif(btrim(void_reason),'') is not null and char_length(void_reason)<=500
      and void_client_request_id is not null)
  )
);

create unique index machine_selling_prices_account_request_key
  on public.machine_selling_prices(account_id,client_request_id);
create unique index machine_selling_prices_void_request_key
  on public.machine_selling_prices(account_id,void_client_request_id) where void_client_request_id is not null;
create unique index machine_selling_prices_active_effective_key
  on public.machine_selling_prices(machine_id,effective_from) where status='posted';
create index machine_selling_prices_history_idx
  on public.machine_selling_prices(account_id,machine_id,effective_from desc,created_at desc);

create or replace function public.protect_machine_selling_price_history()
returns trigger language plpgsql set search_path='' as $$
begin
  if tg_op='DELETE' then
    raise exception 'machine selling price evidence cannot be deleted' using errcode='42501';
  end if;
  if old.status='voided' then
    raise exception 'voided machine selling price is immutable' using errcode='42501';
  end if;
  if new.status<>'voided'
    or (to_jsonb(new)-'status'-'voided_by'-'voided_by_name_snapshot'-'voided_at'-'void_reason'-'void_client_request_id')
      is distinct from
      (to_jsonb(old)-'status'-'voided_by'-'voided_by_name_snapshot'-'voided_at'-'void_reason'-'void_client_request_id') then
    raise exception 'posted machine selling price is immutable; void it instead' using errcode='42501';
  end if;
  return new;
end; $$;

create trigger machine_selling_prices_protect
before update or delete on public.machine_selling_prices
for each row execute function public.protect_machine_selling_price_history();

alter table public.machine_selling_prices enable row level security;
create policy machine_selling_prices_select_members on public.machine_selling_prices for select to authenticated
using (public.is_account_member(account_id));

create view public.machine_selling_price_history with (security_invoker=true) as
with sequenced as (
  select price.*,
    lead(price.effective_from) over (partition by price.machine_id order by price.effective_from,price.created_at,price.id) as effective_to
  from public.machine_selling_prices price
  where price.status='posted'
)
select price.id,price.account_id,price.branch_id,price.machine_id,machine.machine_code,
  machine.display_name as machine_name,price.price_per_click,price.currency,price.effective_from,
  case when price.status='posted' then sequenced.effective_to else null end as effective_to,
  price.status,price.notes,price.created_by,price.created_by_name_snapshot,price.created_at,
  price.voided_by,price.voided_by_name_snapshot,price.voided_at,price.void_reason
from public.machine_selling_prices price
join public.machines machine on machine.id=price.machine_id
left join sequenced on sequenced.id=price.id;

create or replace function public.create_machine_selling_price(
  target_account_id uuid,target_machine_id uuid,target_price_per_click numeric,
  target_effective_from timestamptz,target_notes text,target_client_request_id uuid
) returns public.machine_selling_prices language plpgsql security definer set search_path='' as $$
declare
  actor_id uuid:=(select auth.uid()); machine_record public.machines%rowtype;
  existing_record public.machine_selling_prices%rowtype; result_record public.machine_selling_prices%rowtype;
  actor_name text; clean_notes text:=nullif(btrim(target_notes),'');
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required to manage machine selling prices' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if target_price_per_click is null or target_price_per_click::text in ('NaN','Infinity','-Infinity')
    or target_price_per_click<=0 or round(target_price_per_click,4)<>target_price_per_click then
    raise exception 'selling price must be positive with at most four decimal places' using errcode='22003'; end if;
  if target_effective_from is null then raise exception 'effective date and time is required' using errcode='22004'; end if;
  if clean_notes is not null and char_length(clean_notes)>1000 then raise exception 'notes cannot exceed 1000 characters' using errcode='22001'; end if;

  select * into machine_record from public.machines
  where id=target_machine_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active machine not found in account' using errcode='P0002'; end if;

  select * into existing_record from public.machine_selling_prices
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing_record.machine_id=target_machine_id and existing_record.price_per_click=target_price_per_click
      and existing_record.effective_from=target_effective_from and existing_record.notes is not distinct from clean_notes then
      return existing_record;
    end if;
    raise exception 'client request id was already used for a different selling price' using errcode='23505';
  end if;

  if exists(select 1 from public.machine_selling_prices
    where machine_id=target_machine_id and effective_from=target_effective_from and status='posted') then
    raise exception 'an active selling price already starts at this effective time' using errcode='23P01';
  end if;

  select coalesce(nullif(btrim(profile.display_name),''),'User') into actor_name
  from public.profiles profile where profile.user_id=actor_id;
  actor_name:=coalesce(actor_name,'User');
  insert into public.machine_selling_prices(account_id,branch_id,machine_id,price_per_click,currency,
    effective_from,notes,client_request_id,created_by,created_by_name_snapshot)
  values(target_account_id,machine_record.branch_id,target_machine_id,target_price_per_click,'IDR',
    target_effective_from,clean_notes,target_client_request_id,actor_id,actor_name)
  returning * into result_record;
  return result_record;
end; $$;

create or replace function public.void_machine_selling_price(
  target_price_id uuid,target_reason text,target_client_request_id uuid
) returns public.machine_selling_prices language plpgsql security definer set search_path='' as $$
declare
  actor_id uuid:=(select auth.uid()); price_identity record; price_record public.machine_selling_prices%rowtype;
  actor_name text; clean_reason text:=nullif(btrim(target_reason),'');
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  select account_id,machine_id into price_identity from public.machine_selling_prices where id=target_price_id;
  if not found then raise exception 'machine selling price not found' using errcode='P0002'; end if;
  if not public.has_account_role(price_identity.account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required to manage machine selling prices' using errcode='42501'; end if;
  if clean_reason is null or target_client_request_id is null then
    raise exception 'void reason and client request id are required' using errcode='22023'; end if;
  if char_length(clean_reason)>500 then raise exception 'void reason cannot exceed 500 characters' using errcode='22001'; end if;

  perform 1 from public.machines where id=price_identity.machine_id for update;
  select * into price_record from public.machine_selling_prices where id=target_price_id for update;
  if price_record.status='voided' then
    if price_record.void_client_request_id=target_client_request_id and price_record.void_reason=clean_reason then return price_record; end if;
    raise exception 'machine selling price is already voided' using errcode='40001';
  end if;
  if exists(select 1 from public.machine_selling_prices
    where account_id=price_record.account_id and void_client_request_id=target_client_request_id and id<>target_price_id) then
    raise exception 'client request id was already used for a different selling-price correction' using errcode='23505';
  end if;
  select coalesce(nullif(btrim(profile.display_name),''),'User') into actor_name
  from public.profiles profile where profile.user_id=actor_id;
  update public.machine_selling_prices set status='voided',voided_by=actor_id,
    voided_by_name_snapshot=coalesce(actor_name,'User'),voided_at=statement_timestamp(),
    void_reason=clean_reason,void_client_request_id=target_client_request_id
  where id=target_price_id returning * into price_record;
  return price_record;
end; $$;

drop function public.get_machine_economics_period(uuid,uuid,date,date);

create function public.get_machine_economics_period(
  target_account_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table (
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
  advanced_machine_economics_enabled boolean,known_component_consumption_cost numeric,known_standard_machine_cost numeric,
  known_standard_cost_per_click numeric,known_advanced_operating_cost numeric,known_full_machine_operating_cost numeric,
  known_full_operating_cost_per_click numeric,standard_economics_status public.machine_economics_status,counter_complete boolean,
  standard_cost_complete boolean,full_cost_complete boolean,full_economics_available boolean,
  current_selling_price_per_click numeric,period_end_selling_price_per_click numeric,period_price_count integer,
  priced_clicks numeric,unpriced_clicks numeric,estimated_revenue numeric,revenue_status public.machine_revenue_status,
  standard_contribution_per_click numeric,estimated_standard_contribution numeric,standard_contribution_margin_percent numeric,
  standard_contribution_status public.machine_contribution_status,full_contribution_per_click numeric,
  estimated_full_contribution numeric,full_contribution_margin_percent numeric,full_contribution_status public.machine_contribution_status
) language plpgsql stable security definer set search_path='' as $$
declare
  base record; v_advanced_enabled boolean:=false; v_op_records integer:=0; v_op_cost numeric(30,2):=0; v_op_breakdown jsonb:='[]'::jsonb;
  v_error_events integer:=0; v_known_error_events integer:=0; v_unknown_error_events integer:=0; v_error_cost numeric(30,2):=0;
  v_standard_cost numeric(30,2):=0; v_standard_cpc numeric(30,4); v_full_cost numeric(30,2):=0; v_full_cpc numeric(30,4);
  v_status public.machine_economics_status; v_breakdown jsonb; v_current_price numeric(30,4); v_period_end_price numeric(30,4);
  v_price_count integer:=0; v_priced_clicks numeric(20,4):=0; v_unpriced_clicks numeric(20,4):=0; v_revenue numeric(30,2);
  v_revenue_status public.machine_revenue_status; v_standard_contribution numeric(30,2); v_standard_contribution_cpc numeric(30,4);
  v_standard_margin numeric; v_standard_contribution_status public.machine_contribution_status;
  v_full_contribution numeric(30,2); v_full_contribution_cpc numeric(30,4); v_full_margin numeric;
  v_full_contribution_status public.machine_contribution_status;
begin
  select * into base from public.get_machine_cost_period(target_account_id,target_machine_id,target_period_start,target_period_end);
  select account.machine_economics_advanced_enabled into v_advanced_enabled from public.accounts account where account.id=target_account_id;

  with allocated as (
    select cost.category,cost.id,case when cost.allocation_method='one_time' then cost.amount
      else round(cost.amount*(least(cost.period_end,target_period_end)-greatest(cost.period_start,target_period_start)+1)::numeric
        /(cost.period_end-cost.period_start+1)::numeric,2) end as allocated_amount
    from public.machine_operating_costs cost where cost.account_id=target_account_id and cost.machine_id=target_machine_id and cost.status='posted'
      and ((cost.allocation_method='one_time' and cost.effective_at>=base.period_start_at and cost.effective_at<base.period_end_at)
        or (cost.allocation_method='daily_proration_v1' and cost.period_start<=target_period_end and cost.period_end>=target_period_start))
  ), grouped as (select category,count(*)::integer record_count,sum(allocated_amount)::numeric(30,2) allocated_cost from allocated group by category)
  select coalesce(sum(record_count),0)::integer,coalesce(sum(allocated_cost),0)::numeric(30,2),
    coalesce(jsonb_agg(jsonb_build_object('category',category,'record_count',record_count,'known_cost',allocated_cost)
      order by allocated_cost desc,category),'[]'::jsonb)
  into v_op_records,v_op_cost,v_op_breakdown from grouped;

  select count(*)::integer,count(*) filter(where incident.assessed_loss>0)::integer,
    count(*) filter(where incident.assessed_loss=0)::integer,
    coalesce(sum(incident.assessed_loss) filter(where incident.assessed_loss>0),0)::numeric(30,2)
  into v_error_events,v_known_error_events,v_unknown_error_events,v_error_cost
  from public.operational_incidents incident where incident.account_id=target_account_id and incident.machine_id=target_machine_id
    and incident.status<>'voided' and incident.occurred_at>=base.period_start_at and incident.occurred_at<base.period_end_at;

  v_standard_cost:=base.known_consumption_cost+v_error_cost; v_full_cost:=v_standard_cost+v_op_cost;
  if base.counter_status='COMPLETE' and base.total_clicks>0 then
    v_standard_cpc:=round(v_standard_cost/base.total_clicks,4); v_full_cpc:=round(v_full_cost/base.total_clicks,4);
  end if;
  v_status:=case
    when base.counter_status<>'COMPLETE' and base.total_consumption_events+v_error_events=0 and base.counter_status='NO_DATA' then 'NO_DATA'::public.machine_economics_status
    when base.counter_status<>'COMPLETE' then 'INSUFFICIENT_COUNTER_DATA'::public.machine_economics_status
    when base.unknown_consumption_events+v_unknown_error_events>0 then 'PARTIAL'::public.machine_economics_status
    else 'COMPLETE'::public.machine_economics_status end;
  v_breakdown:=jsonb_build_array(
    jsonb_build_object('layer','component_consumption','known_cost',base.known_consumption_cost,'unknown_events',base.unknown_consumption_events),
    jsonb_build_object('layer','error_waste','known_cost',v_error_cost,'unknown_events',v_unknown_error_events),
    jsonb_build_object('layer','advanced_operating_cost','known_cost',v_op_cost,'unknown_events',0,'enabled',v_advanced_enabled));

  select price.price_per_click into v_current_price from public.machine_selling_prices price
  where price.machine_id=target_machine_id and price.status='posted' and price.effective_from<=statement_timestamp()
  order by price.effective_from desc,price.created_at desc,price.id desc limit 1;
  select price.price_per_click into v_period_end_price from public.machine_selling_prices price
  where price.machine_id=target_machine_id and price.status='posted' and price.effective_from<base.period_end_at
  order by price.effective_from desc,price.created_at desc,price.id desc limit 1;

  with usage_pricing as (
    select history.usage,price.price_per_click
    from public.machine_counter_history history
    left join lateral (
      select evidence.price_per_click from public.machine_selling_prices evidence
      where evidence.machine_id=target_machine_id and evidence.status='posted' and evidence.effective_from<=history.observed_at
      order by evidence.effective_from desc,evidence.created_at desc,evidence.id desc limit 1
    ) price on true
    where history.account_id=target_account_id and history.machine_id=target_machine_id
      and lower(btrim(history.counter_type_code))='total_impressions' and history.status='effective'
      and history.observed_at>=base.period_start_at and history.observed_at<base.period_end_at and coalesce(history.usage,0)>0
  )
  select count(distinct price_per_click)::integer,
    coalesce(sum(usage) filter(where price_per_click is not null),0)::numeric(20,4),
    coalesce(sum(usage) filter(where price_per_click is null),0)::numeric(20,4),
    round(sum(usage*price_per_click) filter(where price_per_click is not null),2)::numeric(30,2)
  into v_price_count,v_priced_clicks,v_unpriced_clicks,v_revenue from usage_pricing;

  v_revenue_status:=case
    when base.counter_status='COMPLETE' and coalesce(base.total_clicks,0)=0 then 'NO_CLICKS'::public.machine_revenue_status
    when coalesce(base.total_clicks,0)>0 and v_priced_clicks=0 then 'NO_PRICE'::public.machine_revenue_status
    when v_unpriced_clicks>0 or base.counter_status<>'COMPLETE' then 'PARTIAL'::public.machine_revenue_status
    else 'COMPLETE'::public.machine_revenue_status end;
  if v_revenue_status='NO_CLICKS' then v_revenue:=0; end if;

  v_standard_contribution_status:=case
    when v_revenue_status='NO_CLICKS' then 'NO_CLICKS'::public.machine_contribution_status
    when v_revenue_status<>'COMPLETE' then 'UNAVAILABLE_REVENUE'::public.machine_contribution_status
    when coalesce(v_revenue,0)=0 then 'ZERO_REVENUE'::public.machine_contribution_status
    when v_status='PARTIAL' then 'PARTIAL_COST'::public.machine_contribution_status
    else 'COMPLETE'::public.machine_contribution_status end;
  if v_revenue_status='COMPLETE' and v_revenue>0 then
    v_standard_contribution:=round(v_revenue-v_standard_cost,2);
    v_standard_contribution_cpc:=round(v_standard_contribution/base.total_clicks,4);
    v_standard_margin:=round(v_standard_contribution/v_revenue*100,4);
  end if;
  v_full_contribution_status:=case when not v_advanced_enabled then null
    else v_standard_contribution_status end;
  if v_advanced_enabled and v_revenue_status='COMPLETE' and v_revenue>0 then
    v_full_contribution:=round(v_revenue-v_full_cost,2);
    v_full_contribution_cpc:=round(v_full_contribution/base.total_clicks,4);
    v_full_margin:=round(v_full_contribution/v_revenue*100,4);
  end if;

  return query select base.account_id,base.branch_id,base.machine_id,base.machine_code,base.machine_name,base.resolved_timezone,
    base.period_start,base.period_end,base.period_start_at,base.period_end_at,base.start_counter,base.start_counter_at,base.end_counter,base.end_counter_at,
    base.total_clicks,base.counter_status,base.total_consumption_events,base.known_consumption_events,base.unknown_consumption_events,
    base.known_consumption_quantity,base.unknown_consumption_quantity,base.known_consumption_cost,base.consumption_event_coverage_percent,
    base.consumption_status,base.cost_status,base.known_component_cost_per_click,base.purchase_cost_context,base.ending_known_inventory_cost_context,
    base.ending_known_inventory_quantity_context,base.ending_unknown_inventory_quantity_context,base.component_breakdown,base.realized_lifecycle_evidence,
    v_op_records,v_op_cost,v_op_breakdown,v_error_events,v_known_error_events,v_unknown_error_events,v_error_cost,
    v_full_cost,v_full_cpc,v_status,base.unknown_consumption_events+v_unknown_error_events,v_breakdown,
    v_advanced_enabled,base.known_consumption_cost,v_standard_cost,v_standard_cpc,v_op_cost,v_full_cost,v_full_cpc,v_status,
    base.counter_status='COMPLETE',base.unknown_consumption_events+v_unknown_error_events=0,
    v_advanced_enabled and base.unknown_consumption_events+v_unknown_error_events=0,v_advanced_enabled,
    v_current_price,v_period_end_price,v_price_count,v_priced_clicks,v_unpriced_clicks,v_revenue,v_revenue_status,
    v_standard_contribution_cpc,v_standard_contribution,v_standard_margin,v_standard_contribution_status,
    v_full_contribution_cpc,v_full_contribution,v_full_margin,v_full_contribution_status;
end; $$;

revoke all on table public.machine_selling_prices,public.machine_selling_price_history from public,anon,authenticated,service_role;
grant select on table public.machine_selling_prices,public.machine_selling_price_history to authenticated,service_role;
revoke all on function public.protect_machine_selling_price_history() from public;
revoke all on function public.create_machine_selling_price(uuid,uuid,numeric,timestamptz,text,uuid) from public,anon,authenticated,service_role;
grant execute on function public.create_machine_selling_price(uuid,uuid,numeric,timestamptz,text,uuid) to authenticated,service_role;
revoke all on function public.void_machine_selling_price(uuid,text,uuid) from public,anon,authenticated,service_role;
grant execute on function public.void_machine_selling_price(uuid,text,uuid) to authenticated,service_role;
revoke all on function public.get_machine_economics_period(uuid,uuid,date,date) from public,anon,authenticated,service_role;
grant execute on function public.get_machine_economics_period(uuid,uuid,date,date) to authenticated,service_role;

comment on table public.machine_selling_prices is 'Immutable effective-dated IDR selling-price evidence for machine utilization revenue. Active interval ends are derived from the next posted effective_from.';
comment on view public.machine_selling_price_history is 'Human-readable price audit history. effective_to is derived and exclusive for posted evidence; voided evidence retains correction audit fields.';
comment on function public.create_machine_selling_price(uuid,uuid,numeric,timestamptz,text,uuid) is 'Owner/admin idempotent append-only machine selling-price mutation serialized by machine row lock.';
comment on function public.get_machine_economics_period(uuid,uuid,date,date) is 'Database-authoritative clicks, Standard/Full costs, effective-price utilization revenue, evidence completeness, and contribution for inclusive operational dates.';
