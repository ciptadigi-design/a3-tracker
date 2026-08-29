-- M2.7C: one global Branch is the operational projection context.
-- Authorization may span many Branches; normal operational projections never do.

-- Purchases acquire immutable Branch ownership when they are created. Legacy rows
-- are claimed only when all of their immutable receipts prove one non-null Branch.
alter table public.inventory_purchases add column branch_id uuid;
alter table public.inventory_purchases add constraint inventory_purchases_branch_account_fkey
  foreign key(branch_id,account_id) references public.branches(id,account_id) on delete restrict;
create index inventory_purchases_branch_date_idx on public.inventory_purchases(account_id,branch_id,purchase_date desc);

create or replace function public.protect_inventory_purchase_history()
returns trigger language plpgsql set search_path='' as $$
begin
  if tg_op='DELETE' then
    raise exception 'purchase history cannot be deleted; cancel an eligible purchase instead' using errcode='42501';
  end if;
  if old.branch_id is not null and new.branch_id is distinct from old.branch_id then
    raise exception 'purchase Branch ownership is immutable' using errcode='42501';
  end if;
  if new.branch_id is null and old.branch_id is not null then
    raise exception 'purchase Branch ownership is immutable' using errcode='42501';
  end if;
  if (to_jsonb(new)-array['status','updated_at','updated_by','cancelled_at','cancelled_by','cancellation_reason','cancellation_request_id','branch_id'])
    is distinct from
    (to_jsonb(old)-array['status','updated_at','updated_by','cancelled_at','cancelled_by','cancellation_reason','cancellation_request_id','branch_id']) then
    raise exception 'posted purchase facts are immutable' using errcode='42501';
  end if;
  return new;
end $$;

with receipt_scope as (
  select receipt.purchase_id,(array_agg(distinct location.branch_id))[1] branch_id
  from public.inventory_receipts receipt
  join public.inventory_locations location on location.id=receipt.location_id and location.account_id=receipt.account_id
  group by receipt.purchase_id
  having count(*)>0 and count(location.branch_id)=count(*) and count(distinct location.branch_id)=1
)
update public.inventory_purchases purchase set branch_id=scope.branch_id
from receipt_scope scope where scope.purchase_id=purchase.id and purchase.branch_id is null;

create or replace function public.apply_inventory_purchase_branch()
returns trigger language plpgsql security definer set search_path='' as $$
declare configured text:=nullif(current_setting('a3.inventory_purchase_branch_id',true),'');
begin
  if new.branch_id is null and configured is not null then new.branch_id:=configured::uuid; end if;
  if new.branch_id is not null and not exists(select 1 from public.branches branch
    where branch.id=new.branch_id and branch.account_id=new.account_id and branch.is_active) then
    raise exception 'active purchase Branch not found in account' using errcode='23503';
  end if;
  return new;
end $$;
create trigger m27c_apply_inventory_purchase_branch before insert on public.inventory_purchases
for each row execute function public.apply_inventory_purchase_branch();
revoke all on function public.apply_inventory_purchase_branch() from public,anon,authenticated,service_role;

alter function public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid)
  rename to create_inventory_purchase_auto_m27c_base;
create function public.create_inventory_purchase(
  target_account_id uuid,target_branch_id uuid,target_supplier_id uuid,target_purchase_number text,target_purchase_date date,
  target_supplier_reference text,target_currency_code text,target_notes text,target_lines jsonb,target_client_request_id uuid
) returns public.inventory_purchases language plpgsql security definer set search_path='' as $$
begin
  if not public.can_access_branch(target_account_id,target_branch_id) then raise exception 'Branch access required' using errcode='42501'; end if;
  perform set_config('a3.inventory_purchase_branch_id',target_branch_id::text,true);
  return public.create_inventory_purchase(target_account_id,target_supplier_id,target_purchase_number,target_purchase_date,
    target_supplier_reference,target_currency_code,target_notes,target_lines,target_client_request_id);
end $$;
create function public.create_inventory_purchase_auto(
  target_account_id uuid,target_branch_id uuid,target_supplier_id uuid,target_purchase_date date,
  target_external_reference text,target_currency_code text,target_notes text,target_lines jsonb,target_client_request_id uuid
) returns public.inventory_purchases language plpgsql security definer set search_path='' as $$
begin
  if not public.can_access_branch(target_account_id,target_branch_id) then raise exception 'Branch access required' using errcode='42501'; end if;
  perform set_config('a3.inventory_purchase_branch_id',target_branch_id::text,true);
  return public.create_inventory_purchase_auto_m27c_base(target_account_id,target_supplier_id,target_purchase_date,
    target_external_reference,target_currency_code,target_notes,target_lines,target_client_request_id);
end $$;

alter function public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid)
  rename to receive_inventory_purchase_m27c_base;
create function public.receive_inventory_purchase(
  target_account_id uuid,target_purchase_id uuid,target_location_id uuid,target_received_at timestamptz,
  target_operational_person_id uuid,target_notes text,target_lines jsonb,target_client_request_id uuid
) returns public.inventory_receipts language plpgsql security definer set search_path='' as $$
declare purchase_branch uuid; location_branch uuid;
begin
  select branch_id into purchase_branch from public.inventory_purchases where id=target_purchase_id and account_id=target_account_id for update;
  if not found then raise exception 'purchase not found in this account' using errcode='P0002'; end if;
  select branch_id into location_branch from public.inventory_locations where id=target_location_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active receiving location not found in account' using errcode='P0002'; end if;
  if location_branch is null then raise exception 'active Branch-owned receiving location required' using errcode='23503'; end if;
  if purchase_branch is null then
    update public.inventory_purchases set branch_id=location_branch where id=target_purchase_id returning branch_id into purchase_branch;
  end if;
  if purchase_branch<>location_branch then raise exception 'receiving location is outside purchase Branch' using errcode='22023'; end if;
  if not public.can_access_branch(target_account_id,purchase_branch) then raise exception 'Branch access required' using errcode='42501'; end if;
  return public.receive_inventory_purchase_m27c_base(target_account_id,target_purchase_id,target_location_id,target_received_at,
    target_operational_person_id,target_notes,target_lines,target_client_request_id);
end $$;

alter function public.cancel_inventory_purchase(uuid,uuid,text,uuid) rename to cancel_inventory_purchase_m27c_base;
create function public.cancel_inventory_purchase(target_account_id uuid,target_purchase_id uuid,target_reason text,target_client_request_id uuid)
returns public.inventory_purchases language plpgsql security definer set search_path='' as $$
declare purchase_branch uuid;
begin
  select branch_id into purchase_branch from public.inventory_purchases where id=target_purchase_id and account_id=target_account_id;
  if not found then raise exception 'purchase not found in this account' using errcode='P0002'; end if;
  if purchase_branch is null or not public.can_access_branch(target_account_id,purchase_branch) then raise exception 'Branch access required' using errcode='42501'; end if;
  return public.cancel_inventory_purchase_m27c_base(target_account_id,target_purchase_id,target_reason,target_client_request_id);
end $$;

revoke all on function public.create_inventory_purchase_auto_m27c_base(uuid,uuid,date,text,text,text,jsonb,uuid),
  public.receive_inventory_purchase_m27c_base(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid),
  public.cancel_inventory_purchase_m27c_base(uuid,uuid,text,uuid) from public,anon,authenticated,service_role;
revoke all on function public.create_inventory_purchase(uuid,uuid,text,date,text,text,text,jsonb,uuid),
  public.create_inventory_purchase(uuid,uuid,uuid,text,date,text,text,text,jsonb,uuid)
  from public,anon,authenticated,service_role;
revoke all on function public.create_inventory_purchase_auto(uuid,uuid,uuid,date,text,text,text,jsonb,uuid),
  public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid),
  public.cancel_inventory_purchase(uuid,uuid,text,uuid) from public,anon,authenticated,service_role;
grant execute on function public.create_inventory_purchase_auto(uuid,uuid,uuid,date,text,text,text,jsonb,uuid),
  public.create_inventory_purchase(uuid,uuid,uuid,text,date,text,text,text,jsonb,uuid),
  public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid),
  public.cancel_inventory_purchase(uuid,uuid,text,uuid) to authenticated;

-- Branch-aware purchasing histories preserve their established columns and append
-- Branch ownership for server-side projection filters.
create or replace view public.inventory_purchase_summary with (security_invoker=true) as
select purchase.id as purchase_id,purchase.account_id,purchase.supplier_id,purchase.purchase_number,
  purchase.purchase_date,purchase.supplier_reference,purchase.currency_code,purchase.status,purchase.notes,
  purchase.supplier_code_snapshot,purchase.supplier_name_snapshot,purchase.client_request_id,
  purchase.created_by,purchase.created_by_name_snapshot,purchase.created_at,purchase.updated_at,
  purchase.cancelled_at,purchase.cancelled_by,purchase.cancellation_reason,
  count(line.purchase_line_id)::integer as line_count,coalesce(sum(line.line_total),0)::numeric(30,2) as purchase_total,
  coalesce(round(avg(line.progress_percent),2),0)::numeric as receiving_progress_percent,
  count(*) filter(where line.remaining_quantity=0)::integer as fully_received_line_count,purchase.branch_id
from public.inventory_purchases purchase
left join public.inventory_purchase_line_status line on line.purchase_id=purchase.id
group by purchase.id;

create or replace view public.inventory_receipt_history with (security_invoker=true) as
select receipt.id as receipt_id,receipt.account_id,receipt.receipt_number,receipt.purchase_id,
  receipt.purchase_number_snapshot,receipt.supplier_id,receipt.supplier_code_snapshot,receipt.supplier_name_snapshot,
  receipt.location_id,location.code as location_code,location.name as location_name,receipt.received_at,
  receipt.operational_person_id,receipt.operational_person_name_snapshot,receipt.currency_code,receipt.notes,
  receipt.client_request_id,receipt.created_by,receipt.created_by_name_snapshot,receipt.created_at,
  line.id as receipt_line_id,line.purchase_line_id,line.inventory_item_id,line.inventory_movement_id,
  line.quantity,line.unit_price_snapshot,line.acquisition_value,line.item_sku_snapshot,line.item_name_snapshot,line.unit_snapshot,
  location.branch_id
from public.inventory_receipts receipt join public.inventory_receipt_lines line on line.receipt_id=receipt.id
join public.inventory_locations location on location.id=receipt.location_id;

create or replace view public.inventory_purchase_cost_history with (security_invoker=true) as
select line.account_id,line.inventory_item_id,line.purchase_id,purchase.purchase_number,purchase.purchase_date,
  purchase.supplier_id,purchase.supplier_code_snapshot,purchase.supplier_name_snapshot,purchase.currency_code,
  line.id as purchase_line_id,line.ordered_quantity,line.unit_price,line.line_total,line.unit_snapshot,
  status.received_quantity,status.remaining_quantity,receipt_line.receipt_id,receipt.receipt_number,
  receipt.received_at,receipt.location_id,location.name as location_name,receipt_line.quantity as receipt_quantity,
  receipt_line.unit_price_snapshot as receipt_unit_price,receipt_line.acquisition_value,purchase.branch_id
from public.inventory_purchase_lines line join public.inventory_purchases purchase on purchase.id=line.purchase_id
join public.inventory_purchase_line_status status on status.purchase_line_id=line.id
left join public.inventory_receipt_lines receipt_line on receipt_line.purchase_line_id=line.id
left join public.inventory_receipts receipt on receipt.id=receipt_line.receipt_id
left join public.inventory_locations location on location.id=receipt.location_id;

create or replace view public.inventory_item_last_purchase_prices with (security_invoker=true) as
select distinct on (line.account_id,purchase.branch_id,line.inventory_item_id)
  line.account_id,line.inventory_item_id,purchase.purchase_id,purchase.purchase_number,purchase.purchase_date,
  purchase.supplier_id,purchase.supplier_name_snapshot,purchase.currency_code,line.unit_price,line.unit_snapshot,purchase.branch_id
from public.inventory_purchase_line_status line join public.inventory_purchase_summary purchase on purchase.purchase_id=line.purchase_id
where purchase.status<>'cancelled' and purchase.branch_id is not null
order by line.account_id,purchase.branch_id,line.inventory_item_id,purchase.purchase_date desc,purchase.created_at desc,line.purchase_line_id desc;

-- Direct data access remains authorization-scoped. UI projection still names one
-- selected Branch explicitly, including for Platform Superusers.
drop policy if exists inventory_locations_select_members on public.inventory_locations;
create policy inventory_locations_select_branch_access on public.inventory_locations for select to authenticated
  using(branch_id is not null and public.can_access_branch(account_id,branch_id));
drop policy if exists inventory_movements_select_members on public.inventory_movements;
create policy inventory_movements_select_branch_access on public.inventory_movements for select to authenticated using(exists(
  select 1 from public.inventory_locations location where location.id=inventory_movements.location_id
    and location.account_id=inventory_movements.account_id and public.can_access_branch(location.account_id,location.branch_id)));
drop policy if exists inventory_purchases_select_members on public.inventory_purchases;
create policy inventory_purchases_select_branch_access on public.inventory_purchases for select to authenticated
  using(branch_id is not null and public.can_access_branch(account_id,branch_id));
drop policy if exists inventory_purchase_lines_select_members on public.inventory_purchase_lines;
create policy inventory_purchase_lines_select_branch_access on public.inventory_purchase_lines for select to authenticated using(exists(
  select 1 from public.inventory_purchases purchase where purchase.id=inventory_purchase_lines.purchase_id
    and purchase.account_id=inventory_purchase_lines.account_id and public.can_access_branch(purchase.account_id,purchase.branch_id)));
drop policy if exists inventory_receipts_select_members on public.inventory_receipts;
create policy inventory_receipts_select_branch_access on public.inventory_receipts for select to authenticated using(exists(
  select 1 from public.inventory_locations location where location.id=inventory_receipts.location_id
    and location.account_id=inventory_receipts.account_id and public.can_access_branch(location.account_id,location.branch_id)));
drop policy if exists inventory_receipt_lines_select_members on public.inventory_receipt_lines;
create policy inventory_receipt_lines_select_branch_access on public.inventory_receipt_lines for select to authenticated using(exists(
  select 1 from public.inventory_receipts receipt join public.inventory_locations location on location.id=receipt.location_id
  where receipt.id=inventory_receipt_lines.receipt_id and receipt.account_id=inventory_receipt_lines.account_id
    and public.can_access_branch(location.account_id,location.branch_id)));

-- Physical-location mutations must honor the caller's Branch authorization too.
-- Existing ledger implementations, locks, idempotency, and FIFO triggers stay
-- behind these authorization wrappers.
drop policy if exists inventory_locations_insert_owner_admin on public.inventory_locations;
drop policy if exists inventory_locations_update_owner_admin on public.inventory_locations;
drop policy if exists inventory_locations_delete_owner_admin on public.inventory_locations;
create policy inventory_locations_insert_branch_access on public.inventory_locations for insert to authenticated with check(
  branch_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[])
    and public.can_access_branch(account_id,branch_id));
create policy inventory_locations_update_branch_access on public.inventory_locations for update to authenticated using(
  branch_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[])
    and public.can_access_branch(account_id,branch_id)) with check(
  branch_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[])
    and public.can_access_branch(account_id,branch_id));
create policy inventory_locations_delete_branch_access on public.inventory_locations for delete to authenticated using(
  branch_id is not null and public.has_account_role(account_id,array['owner','admin']::public.account_role[])
    and public.can_access_branch(account_id,branch_id));

create or replace function public.protect_inventory_location_branch()
returns trigger language plpgsql set search_path='' as $$
begin
  if new.branch_id is null then raise exception 'Inventory Location requires a Branch' using errcode='23514'; end if;
  if new.branch_id is distinct from old.branch_id and (
    exists(select 1 from public.inventory_movements movement where movement.location_id=old.id)
    or exists(select 1 from public.inventory_receipts receipt where receipt.location_id=old.id)
  ) then raise exception 'Inventory Location Branch is immutable after operational evidence exists' using errcode='42501'; end if;
  return new;
end $$;
create trigger m27c_protect_inventory_location_branch before update of branch_id on public.inventory_locations
for each row execute function public.protect_inventory_location_branch();
revoke all on function public.protect_inventory_location_branch() from public,anon,authenticated,service_role;

create or replace function public.require_inventory_location_branch(target_account_id uuid,target_location_id uuid)
returns uuid language plpgsql stable security definer set search_path='' as $$
declare resolved_branch uuid;
begin
  select branch_id into resolved_branch from public.inventory_locations
  where id=target_location_id and account_id=target_account_id and is_active;
  if not found or resolved_branch is null then raise exception 'active Branch-owned inventory location not found in account' using errcode='P0002'; end if;
  if not public.can_access_branch(target_account_id,resolved_branch) then raise exception 'Branch access required' using errcode='42501'; end if;
  return resolved_branch;
end $$;
revoke all on function public.require_inventory_location_branch(uuid,uuid) from public,anon,authenticated,service_role;

alter function public.initialize_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid)
  rename to initialize_inventory_stock_m27c_base;
create function public.initialize_inventory_stock(
  target_account_id uuid,target_inventory_item_id uuid,target_location_id uuid,target_quantity numeric,
  target_occurred_at timestamptz,target_operational_person_id uuid,target_notes text,target_client_request_id uuid
) returns public.inventory_movements language plpgsql security definer set search_path='' as $$
begin
  perform public.require_inventory_location_branch(target_account_id,target_location_id);
  return public.initialize_inventory_stock_m27c_base(target_account_id,target_inventory_item_id,target_location_id,target_quantity,
    target_occurred_at,target_operational_person_id,target_notes,target_client_request_id);
end $$;

alter function public.adjust_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid)
  rename to adjust_inventory_stock_m27c_base;
create function public.adjust_inventory_stock(
  target_account_id uuid,target_inventory_item_id uuid,target_location_id uuid,target_quantity_delta numeric,
  target_occurred_at timestamptz,target_operational_person_id uuid,target_reason text,target_notes text,target_client_request_id uuid
) returns public.inventory_movements language plpgsql security definer set search_path='' as $$
begin
  perform public.require_inventory_location_branch(target_account_id,target_location_id);
  return public.adjust_inventory_stock_m27c_base(target_account_id,target_inventory_item_id,target_location_id,target_quantity_delta,
    target_occurred_at,target_operational_person_id,target_reason,target_notes,target_client_request_id);
end $$;

alter function public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid)
  rename to transfer_inventory_stock_m27c_base;
create function public.transfer_inventory_stock(
  target_account_id uuid,target_inventory_item_id uuid,target_source_location_id uuid,target_destination_location_id uuid,
  target_quantity numeric,target_occurred_at timestamptz,target_operational_person_id uuid,target_notes text,target_client_request_id uuid
) returns table(transfer_id uuid,transfer_out_id uuid,transfer_in_id uuid)
language plpgsql security definer set search_path='' as $$
declare source_branch uuid; destination_branch uuid;
begin
  source_branch:=public.require_inventory_location_branch(target_account_id,target_source_location_id);
  destination_branch:=public.require_inventory_location_branch(target_account_id,target_destination_location_id);
  if not exists(select 1 from public.operational_people person
    where person.id=target_operational_person_id and person.account_id=target_account_id and person.is_active) then
    raise exception 'active PIC / Operator not found in this account' using errcode='P0002';
  end if;
  if not public.is_operational_person_valid_for_branch(target_account_id,target_operational_person_id,source_branch)
    or not public.is_operational_person_valid_for_branch(target_account_id,target_operational_person_id,destination_branch) then
    raise exception 'PIC / Operator must be assigned to both transfer Branches' using errcode='23514';
  end if;
  return query select * from public.transfer_inventory_stock_m27c_base(target_account_id,target_inventory_item_id,
    target_source_location_id,target_destination_location_id,target_quantity,target_occurred_at,
    target_operational_person_id,target_notes,target_client_request_id);
end $$;

alter function public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,
  public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  rename to replace_machine_component_m27c_base;
create function public.replace_machine_component(target_account_id uuid,target_machine_id uuid,target_lifecycle_id uuid,
  target_replacement_counter numeric,target_replaced_at timestamptz,target_replacement_reason public.component_replacement_reason,
  target_condition_at_removal public.component_removal_condition,target_include_in_adaptive_learning boolean,target_performed_by_user_id uuid,
  target_performed_by_name_snapshot text,target_notes text,target_client_request_id uuid,target_inventory_source public.component_replacement_inventory_source,
  target_inventory_item_id uuid,target_inventory_location_id uuid,target_inventory_quantity numeric,target_external_inventory_reason text)
returns public.component_replacement_events language plpgsql security definer set search_path='' as $$
declare machine_branch uuid; location_branch uuid;
begin
  select branch_id into machine_branch from public.machines where id=target_machine_id and account_id=target_account_id;
  if found and target_inventory_location_id is not null then
    location_branch:=public.require_inventory_location_branch(target_account_id,target_inventory_location_id);
    if location_branch<>machine_branch then raise exception 'replacement Inventory Location is outside the machine Branch' using errcode='22023'; end if;
  end if;
  return public.replace_machine_component_m27c_base(target_account_id,target_machine_id,target_lifecycle_id,target_replacement_counter,
    target_replaced_at,target_replacement_reason,target_condition_at_removal,target_include_in_adaptive_learning,target_performed_by_user_id,
    target_performed_by_name_snapshot,target_notes,target_client_request_id,target_inventory_source,target_inventory_item_id,
    target_inventory_location_id,target_inventory_quantity,target_external_inventory_reason);
end $$;

revoke all on function public.initialize_inventory_stock_m27c_base(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.adjust_inventory_stock_m27c_base(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid),
  public.transfer_inventory_stock_m27c_base(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.replace_machine_component_m27c_base(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,
    public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  from public,anon,authenticated,service_role;
revoke all on function public.initialize_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.adjust_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid),
  public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,
    public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  from public,anon,authenticated,service_role;
grant execute on function public.initialize_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.adjust_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid),
  public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,
    public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  to authenticated,service_role;

-- Normal operational Reports require exactly one selected global Branch for every
-- role. Machine remains an optional subordinate filter.
create or replace function public.resolve_operational_report_scope(
  target_account_id uuid,target_branch_id uuid,target_machine_id uuid,target_period_start date,target_period_end date
) returns table(resolved_timezone text,period_start_at timestamptz,period_end_at timestamptz)
language plpgsql stable security definer set search_path='' as $$
declare account_record public.accounts%rowtype; branch_record public.branches%rowtype; machine_record public.machines%rowtype; v_timezone text;
begin
  if auth.uid() is null or not public.is_account_member(target_account_id) then raise exception 'active account membership required' using errcode='42501'; end if;
  if target_branch_id is null then raise exception 'selected Branch is required for operational Reports' using errcode='22023'; end if;
  if not public.can_access_branch(target_account_id,target_branch_id) then raise exception 'Branch access required' using errcode='42501'; end if;
  if target_period_start is null or target_period_end is null or target_period_end<target_period_start then raise exception 'valid report period is required' using errcode='22007'; end if;
  select * into account_record from public.accounts where id=target_account_id and status='active';
  if not found then raise exception 'active account not found' using errcode='P0002'; end if;
  select * into branch_record from public.branches where id=target_branch_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active branch not found in account' using errcode='P0002'; end if;
  if target_machine_id is not null then
    select * into machine_record from public.machines where id=target_machine_id and account_id=target_account_id and is_active;
    if not found or not public.can_access_branch(target_account_id,machine_record.branch_id) then raise exception 'active accessible machine not found' using errcode='P0002'; end if;
    if machine_record.branch_id<>target_branch_id then raise exception 'machine is outside selected branch' using errcode='22023'; end if;
  end if;
  v_timezone:=coalesce(machine_record.timezone,branch_record.timezone,account_record.default_timezone);
  return query select v_timezone,target_period_start::timestamp at time zone v_timezone,(target_period_end+1)::timestamp at time zone v_timezone;
end $$;

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
    where purchase.account_id=target_account_id and purchase.branch_id=target_branch_id
      and purchase.purchase_date between target_period_start and target_period_end
  ), receipt_totals as (
    select count(distinct receipt.id)::integer receipt_count,coalesce(sum(line.quantity),0)::numeric(20,4) quantity
    from public.inventory_receipts receipt join public.inventory_receipt_lines line on line.receipt_id=receipt.id
    join public.inventory_locations location on location.id=receipt.location_id join public.accounts account on account.id=receipt.account_id
    left join public.branches branch on branch.id=location.branch_id where receipt.account_id=target_account_id
      and location.branch_id=target_branch_id
      and (receipt.received_at at time zone coalesce(branch.timezone,account.default_timezone))::date between target_period_start and target_period_end
  ), movement_totals as (
    select count(*) filter(where movement.movement_type='issue')::integer issue_count,
      count(*) filter(where movement.reference_type='component_replacement' and movement.movement_type='issue')::integer replacement_count,
      count(*) filter(where movement.movement_type in ('adjustment_in','adjustment_out'))::integer adjustment_count,
      count(*) filter(where movement.movement_type in ('transfer_in','transfer_out'))::integer transfer_count
    from public.inventory_movements movement join public.inventory_locations location on location.id=movement.location_id
    join public.accounts account on account.id=movement.account_id left join public.branches branch on branch.id=location.branch_id
    where movement.account_id=target_account_id and location.branch_id=target_branch_id
      and (movement.occurred_at at time zone coalesce(branch.timezone,account.default_timezone))::date between target_period_start and target_period_end
  ), stock_quantities as (
    select item.id,item.is_active,item.minimum_stock,
      coalesce(sum(movement.quantity) filter(where location.branch_id=target_branch_id),0)::numeric quantity
    from public.inventory_items item left join public.inventory_movements movement on movement.inventory_item_id=item.id and movement.account_id=item.account_id
    left join public.inventory_locations location on location.id=movement.location_id
    where item.account_id=target_account_id group by item.id
  ), stock as (
    select count(*) filter(where is_active)::integer active_count,count(*) filter(where is_active and quantity<=0)::integer out_count,
      count(*) filter(where is_active and minimum_stock is not null and quantity>0 and quantity<=minimum_stock)::integer low_count
    from stock_quantities
  ) select procurement.purchase_count,procurement.purchase_value,receipt_totals.receipt_count,receipt_totals.quantity,
    movement_totals.issue_count,movement_totals.replacement_count,movement_totals.adjustment_count,movement_totals.transfer_count,
    stock.active_count,stock.out_count,stock.low_count,'BRANCH_PURCHASES' from procurement,receipt_totals,movement_totals,stock;
end $$;

create function public.get_report_purchase_lines(target_account_id uuid,target_branch_id uuid,target_period_start date,target_period_end date)
returns table(purchase_id uuid,purchase_number text,external_reference text,supplier_name text,purchase_date date,status public.inventory_purchase_status,
  item_name text,item_sku text,ordered_quantity numeric,unit_price numeric,line_total numeric,received_quantity numeric,remaining_quantity numeric,unit text)
language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(target_account_id,target_branch_id,null,target_period_start,target_period_end);
  return query select purchase.purchase_id,purchase.purchase_number,purchase.supplier_reference,purchase.supplier_name_snapshot,purchase.purchase_date,purchase.status,
    line.item_name_snapshot,nullif(line.item_sku_snapshot,''),line.ordered_quantity,line.unit_price,line.line_total,line.received_quantity,line.remaining_quantity,line.unit_snapshot::text
  from public.inventory_purchase_summary purchase join public.inventory_purchase_line_status line on line.purchase_id=purchase.purchase_id
  where purchase.account_id=target_account_id and purchase.branch_id=target_branch_id
    and purchase.purchase_date between target_period_start and target_period_end
  order by purchase.purchase_date desc,purchase.purchase_number,line.item_name_snapshot;
end $$;
revoke all on function public.get_report_purchase_lines(uuid,date,date) from public,anon,authenticated,service_role;
revoke all on function public.get_report_purchase_lines(uuid,uuid,date,date) from public,anon,authenticated,service_role;
grant execute on function public.get_report_purchase_lines(uuid,uuid,date,date) to authenticated,service_role;

create or replace function public.get_report_inventory_analytics(
  target_account_id uuid,target_branch_id uuid,target_period_start date,target_period_end date
) returns table(
  purchases integer,purchase_value numeric,receipts integer,received_quantity numeric,received_value numeric,
  issues integer,replacement_issues integer,adjustments integer,transfer_legs integer,
  active_items integer,out_of_stock_items integer,low_stock_items integer,purchase_scope text
) language plpgsql stable security definer set search_path='' as $$
begin
  perform * from public.resolve_operational_report_scope(
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
    where receipt.account_id=target_account_id and location.branch_id=target_branch_id
      and (receipt.received_at at time zone coalesce(branch.timezone,account.default_timezone))::date
        between target_period_start and target_period_end
  )
  select base.purchases,base.purchase_value,base.receipts,base.received_quantity,received.received_value,
    base.issues,base.replacement_issues,base.adjustments,base.transfer_legs,
    base.active_items,base.out_of_stock_items,base.low_stock_items,base.purchase_scope
  from base cross join received;
end $$;

-- Temporary rollout policy: tenant Owner architecture remains intact, while the
-- Settings control plane is callable only through explicit platform privilege.
create or replace function public.can_manage_account_governance(target_account_id uuid)
returns boolean language sql stable security definer set search_path='' as $$
  select target_account_id is not null and public.is_platform_superuser()
$$;

drop policy if exists manufacturers_insert_owner_admin on public.manufacturers;
drop policy if exists manufacturers_update_owner_admin on public.manufacturers;
drop policy if exists manufacturers_delete_owner_admin on public.manufacturers;
create policy manufacturers_insert_platform_superuser on public.manufacturers for insert to authenticated with check(public.is_platform_superuser());
create policy manufacturers_update_platform_superuser on public.manufacturers for update to authenticated using(public.is_platform_superuser()) with check(public.is_platform_superuser());
create policy manufacturers_delete_platform_superuser on public.manufacturers for delete to authenticated using(public.is_platform_superuser());
drop policy if exists machine_models_insert_owner_admin on public.machine_models;
drop policy if exists machine_models_update_owner_admin on public.machine_models;
drop policy if exists machine_models_delete_owner_admin on public.machine_models;
create policy machine_models_insert_platform_superuser on public.machine_models for insert to authenticated with check(public.is_platform_superuser());
create policy machine_models_update_platform_superuser on public.machine_models for update to authenticated using(public.is_platform_superuser()) with check(public.is_platform_superuser());
create policy machine_models_delete_platform_superuser on public.machine_models for delete to authenticated using(public.is_platform_superuser());

comment on column public.inventory_purchases.branch_id is 'Immutable operational Branch ownership. Existing rows are backfilled only from one unambiguous receipt-location Branch.';
comment on function public.resolve_operational_report_scope(uuid,uuid,uuid,date,date) is 'Validates one selected global Branch and optional child Machine; authorization breadth never implies an all-Branch projection.';
comment on function public.can_manage_account_governance(uuid) is 'Temporary M2.7C Settings policy: explicit active Platform Superuser privilege only. Tenant Owner role is retained.';
