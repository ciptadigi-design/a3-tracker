-- M2.5B - machine economics foundation. M2.5A remains the authoritative
-- component-consumption engine; this migration layers attributable operating
-- costs and explicit operational-incident loss evidence on top.

create type public.machine_operating_cost_category as enum (
  'electricity','service_contract','routine_service','labor','rental_or_lease',
  'depreciation','calibration','cleaning_material','external_technician',
  'software_or_license','other_operating'
);
create type public.machine_operating_cost_source as enum ('manual','imported','integrated','derived');
create type public.machine_operating_cost_allocation as enum ('one_time','daily_proration_v1');
create type public.machine_operating_cost_status as enum ('posted','voided');
create type public.machine_economics_status as enum ('COMPLETE','PARTIAL','INSUFFICIENT_COUNTER_DATA','NO_DATA');

create table public.machine_operating_costs (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid not null,
  machine_id uuid not null,
  category public.machine_operating_cost_category not null,
  amount numeric(30,2) not null,
  currency text not null default 'IDR',
  effective_at timestamptz,
  period_start date,
  period_end date,
  allocation_method public.machine_operating_cost_allocation not null,
  description text not null,
  notes text,
  operational_person_id uuid,
  pic_name_snapshot text,
  external_reference text,
  source_type public.machine_operating_cost_source not null default 'manual',
  status public.machine_operating_cost_status not null default 'posted',
  client_request_id uuid not null,
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  created_at timestamptz not null default statement_timestamp(),
  voided_by uuid references auth.users(id) on delete restrict,
  voided_at timestamptz,
  void_reason text,
  void_client_request_id uuid,
  constraint machine_operating_costs_branch_scope_fkey foreign key (branch_id,account_id)
    references public.branches(id,account_id) on delete restrict,
  constraint machine_operating_costs_machine_scope_fkey foreign key (machine_id,account_id,branch_id)
    references public.machines(id,account_id,branch_id) on delete restrict,
  constraint machine_operating_costs_person_scope_fkey foreign key (operational_person_id,account_id)
    references public.operational_people(id,account_id) on delete restrict,
  constraint machine_operating_costs_amount_positive check (amount>0),
  constraint machine_operating_costs_currency_idr check (currency='IDR'),
  constraint machine_operating_costs_description_present check (nullif(btrim(description),'') is not null),
  constraint machine_operating_costs_optional_text check (
    (notes is null or nullif(btrim(notes),'') is not null)
    and (pic_name_snapshot is null or nullif(btrim(pic_name_snapshot),'') is not null)
    and (external_reference is null or nullif(btrim(external_reference),'') is not null)
  ),
  constraint machine_operating_costs_allocation_shape check (
    (allocation_method='one_time' and effective_at is not null and period_start is null and period_end is null)
    or (allocation_method='daily_proration_v1' and effective_at is null and period_start is not null
      and period_end is not null and period_end>=period_start)
  ),
  constraint machine_operating_costs_void_shape check (
    (status='posted' and voided_by is null and voided_at is null and void_reason is null and void_client_request_id is null)
    or (status='voided' and voided_by is not null and voided_at is not null
      and nullif(btrim(void_reason),'') is not null and void_client_request_id is not null)
  )
);

create unique index machine_operating_costs_account_request_key on public.machine_operating_costs(account_id,client_request_id);
create unique index machine_operating_costs_void_request_key on public.machine_operating_costs(account_id,void_client_request_id) where void_client_request_id is not null;
create index machine_operating_costs_machine_effective_idx on public.machine_operating_costs(account_id,machine_id,status,effective_at) where allocation_method='one_time';
create index machine_operating_costs_machine_period_idx on public.machine_operating_costs(account_id,machine_id,status,period_start,period_end) where allocation_method='daily_proration_v1';
create index machine_operating_costs_category_idx on public.machine_operating_costs(account_id,branch_id,category,status);

create or replace function public.protect_machine_operating_cost_history()
returns trigger language plpgsql set search_path='' as $$
begin
  if tg_op='DELETE' then raise exception 'machine operating cost evidence cannot be deleted' using errcode='42501'; end if;
  if old.status='voided' then raise exception 'voided machine operating cost is immutable' using errcode='42501'; end if;
  if new.status<>'voided' or (to_jsonb(new)-'status'-'voided_by'-'voided_at'-'void_reason'-'void_client_request_id')
      is distinct from (to_jsonb(old)-'status'-'voided_by'-'voided_at'-'void_reason'-'void_client_request_id') then
    raise exception 'posted machine operating cost is immutable; void it instead' using errcode='42501';
  end if;
  return new;
end; $$;

create trigger machine_operating_costs_protect before update or delete on public.machine_operating_costs
for each row execute function public.protect_machine_operating_cost_history();

alter table public.machine_operating_costs enable row level security;
create policy machine_operating_costs_select_members on public.machine_operating_costs for select to authenticated
using (public.is_account_member(account_id));

create or replace function public.create_machine_operating_cost(
  target_account_id uuid,target_machine_id uuid,target_category public.machine_operating_cost_category,
  target_amount numeric,target_allocation_method public.machine_operating_cost_allocation,
  target_description text,target_client_request_id uuid,target_effective_at timestamptz default null,
  target_period_start date default null,target_period_end date default null,
  target_operational_person_id uuid default null,target_external_reference text default null,
  target_notes text default null,target_source_type public.machine_operating_cost_source default 'manual'
) returns public.machine_operating_costs language plpgsql security definer set search_path='' as $$
declare
  actor_id uuid := (select auth.uid()); machine_record public.machines%rowtype;
  person_record public.operational_people%rowtype; existing_record public.machine_operating_costs%rowtype;
  result_record public.machine_operating_costs%rowtype;
  clean_description text:=nullif(btrim(target_description),''); clean_reference text:=nullif(btrim(target_external_reference),'');
  clean_notes text:=nullif(btrim(target_notes),''); pic_snapshot text;
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required to manage machine operating costs' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if target_amount is null or target_amount<=0 or round(target_amount,2)<>target_amount then
    raise exception 'amount must be positive with at most two decimal places' using errcode='22003'; end if;
  if clean_description is null then raise exception 'description is required' using errcode='22023'; end if;
  if target_source_type<>'manual' then raise exception 'manual entry cannot claim imported, integrated, or derived provenance' using errcode='23514'; end if;
  if target_allocation_method='one_time' then
    if target_effective_at is null or target_period_start is not null or target_period_end is not null then raise exception 'one-time cost requires only effective date and time' using errcode='22023'; end if;
    if target_effective_at>statement_timestamp()+interval '5 minutes' then raise exception 'one-time cost cannot be in the future' using errcode='22007'; end if;
  elsif target_effective_at is not null or target_period_start is null or target_period_end is null or target_period_end<target_period_start then
    raise exception 'period cost requires a valid inclusive start and end date' using errcode='22007';
  end if;

  select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active machine not found in account' using errcode='P0002'; end if;
  if target_operational_person_id is not null then
    select * into person_record from public.operational_people where id=target_operational_person_id and account_id=target_account_id and is_active;
    if not found then raise exception 'active operational person not found in account' using errcode='P0002'; end if;
    pic_snapshot:=person_record.name;
  end if;

  select * into existing_record from public.machine_operating_costs where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing_record.machine_id=target_machine_id and existing_record.category=target_category
      and existing_record.amount=target_amount and existing_record.allocation_method=target_allocation_method
      and existing_record.description=clean_description and existing_record.effective_at is not distinct from target_effective_at
      and existing_record.period_start is not distinct from target_period_start and existing_record.period_end is not distinct from target_period_end
      and existing_record.operational_person_id is not distinct from target_operational_person_id
      and existing_record.external_reference is not distinct from clean_reference and existing_record.notes is not distinct from clean_notes
      and existing_record.source_type=target_source_type then return existing_record; end if;
    raise exception 'client request id was already used for a different operating cost' using errcode='23505';
  end if;

  insert into public.machine_operating_costs(account_id,branch_id,machine_id,category,amount,currency,effective_at,period_start,period_end,
    allocation_method,description,notes,operational_person_id,pic_name_snapshot,external_reference,source_type,client_request_id,created_by)
  values(target_account_id,machine_record.branch_id,target_machine_id,target_category,target_amount,'IDR',target_effective_at,target_period_start,target_period_end,
    target_allocation_method,clean_description,clean_notes,target_operational_person_id,pic_snapshot,clean_reference,target_source_type,target_client_request_id,actor_id)
  returning * into result_record; return result_record;
end; $$;

create or replace function public.void_machine_operating_cost(target_cost_id uuid,target_reason text,target_client_request_id uuid)
returns public.machine_operating_costs language plpgsql security definer set search_path='' as $$
declare actor_id uuid:=(select auth.uid()); cost_record public.machine_operating_costs%rowtype; clean_reason text:=nullif(btrim(target_reason),'');
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  select * into cost_record from public.machine_operating_costs where id=target_cost_id for update;
  if not found then raise exception 'machine operating cost not found' using errcode='P0002'; end if;
  if not public.has_account_role(cost_record.account_id,array['owner','admin']::public.account_role[]) then raise exception 'owner or admin role required' using errcode='42501'; end if;
  if clean_reason is null or target_client_request_id is null then raise exception 'void reason and client request id are required' using errcode='22023'; end if;
  if cost_record.status='voided' then
    if cost_record.void_client_request_id=target_client_request_id and cost_record.void_reason=clean_reason then return cost_record; end if;
    raise exception 'machine operating cost is already voided' using errcode='40001';
  end if;
  update public.machine_operating_costs set status='voided',voided_by=actor_id,voided_at=statement_timestamp(),void_reason=clean_reason,void_client_request_id=target_client_request_id
  where id=target_cost_id returning * into cost_record; return cost_record;
end; $$;

create view public.machine_operating_cost_history with (security_invoker=true) as
select cost.*,machine.machine_code,machine.display_name as machine_name
from public.machine_operating_costs cost join public.machines machine on machine.id=cost.machine_id;

create or replace function public.get_machine_economics_period(target_account_id uuid,target_machine_id uuid,target_period_start date,target_period_end date)
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
  unknown_evidence_events integer,machine_economics_breakdown jsonb
) language plpgsql stable security definer set search_path='' as $$
declare base record; v_op_records integer:=0; v_op_cost numeric(30,2):=0; v_op_breakdown jsonb:='[]'::jsonb;
  v_error_events integer:=0; v_known_error_events integer:=0; v_unknown_error_events integer:=0; v_error_cost numeric(30,2):=0;
  v_machine_cost numeric(30,2):=0; v_machine_cpc numeric(30,4); v_status public.machine_economics_status; v_breakdown jsonb;
begin
  select * into base from public.get_machine_cost_period(target_account_id,target_machine_id,target_period_start,target_period_end);

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
    select category,count(*)::integer record_count,sum(allocated_amount)::numeric(30,2) allocated_cost from allocated group by category
  )
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

  v_machine_cost:=base.known_consumption_cost+v_op_cost+v_error_cost;
  if base.counter_status='COMPLETE' and base.total_clicks>0 then v_machine_cpc:=round(v_machine_cost/base.total_clicks,4); end if;
  v_status:=case
    when base.counter_status<>'COMPLETE' and base.total_consumption_events+v_op_records+v_error_events=0 and base.counter_status='NO_DATA' then 'NO_DATA'::public.machine_economics_status
    when base.counter_status<>'COMPLETE' then 'INSUFFICIENT_COUNTER_DATA'::public.machine_economics_status
    when base.unknown_consumption_events+v_unknown_error_events>0 then 'PARTIAL'::public.machine_economics_status
    else 'COMPLETE'::public.machine_economics_status end;
  v_breakdown:=jsonb_build_array(
    jsonb_build_object('layer','component_consumption','known_cost',base.known_consumption_cost,'unknown_events',base.unknown_consumption_events),
    jsonb_build_object('layer','operating_cost','known_cost',v_op_cost,'unknown_events',0),
    jsonb_build_object('layer','error_waste','known_cost',v_error_cost,'unknown_events',v_unknown_error_events));

  return query select base.account_id,base.branch_id,base.machine_id,base.machine_code,base.machine_name,base.resolved_timezone,
    base.period_start,base.period_end,base.period_start_at,base.period_end_at,base.start_counter,base.start_counter_at,base.end_counter,base.end_counter_at,
    base.total_clicks,base.counter_status,base.total_consumption_events,base.known_consumption_events,base.unknown_consumption_events,
    base.known_consumption_quantity,base.unknown_consumption_quantity,base.known_consumption_cost,base.consumption_event_coverage_percent,
    base.consumption_status,base.cost_status,base.known_component_cost_per_click,base.purchase_cost_context,base.ending_known_inventory_cost_context,
    base.ending_known_inventory_quantity_context,base.ending_unknown_inventory_quantity_context,base.component_breakdown,base.realized_lifecycle_evidence,
    v_op_records,v_op_cost,v_op_breakdown,v_error_events,v_known_error_events,v_unknown_error_events,v_error_cost,v_machine_cost,v_machine_cpc,v_status,
    base.unknown_consumption_events+v_unknown_error_events,v_breakdown;
end; $$;

revoke all on table public.machine_operating_costs,public.machine_operating_cost_history from public,anon,authenticated,service_role;
grant select on table public.machine_operating_costs,public.machine_operating_cost_history to authenticated,service_role;
revoke all on function public.create_machine_operating_cost(uuid,uuid,public.machine_operating_cost_category,numeric,public.machine_operating_cost_allocation,text,uuid,timestamptz,date,date,uuid,text,text,public.machine_operating_cost_source) from public,anon,authenticated,service_role;
grant execute on function public.create_machine_operating_cost(uuid,uuid,public.machine_operating_cost_category,numeric,public.machine_operating_cost_allocation,text,uuid,timestamptz,date,date,uuid,text,text,public.machine_operating_cost_source) to authenticated,service_role;
revoke all on function public.void_machine_operating_cost(uuid,text,uuid) from public,anon,authenticated,service_role;
grant execute on function public.void_machine_operating_cost(uuid,text,uuid) to authenticated,service_role;
revoke all on function public.get_machine_economics_period(uuid,uuid,date,date) from public,anon,authenticated,service_role;
grant execute on function public.get_machine_economics_period(uuid,uuid,date,date) to authenticated,service_role;
revoke all on function public.protect_machine_operating_cost_history() from public;

comment on table public.machine_operating_costs is 'Posted, machine-attributed non-inventory operating cost evidence. Component parts consumed through Inventory/Replacement must not be duplicated here.';
comment on function public.get_machine_economics_period(uuid,uuid,date,date) is 'M2.5B Machine Economics for inclusive operational dates. Reuses M2.5A consumption, daily-prorates period costs, and includes explicit non-voided machine incident loss.';
