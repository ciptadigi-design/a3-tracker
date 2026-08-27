-- A3 Tracker V2 - M2.4C RLS and transactional purchase/receiving boundaries.

alter table public.inventory_suppliers enable row level security;
alter table public.inventory_purchases enable row level security;
alter table public.inventory_purchase_lines enable row level security;
alter table public.inventory_receipts enable row level security;
alter table public.inventory_receipt_lines enable row level security;

create policy inventory_suppliers_select_members on public.inventory_suppliers for select to authenticated
using (public.is_account_member(account_id));
create policy inventory_suppliers_insert_owner_admin on public.inventory_suppliers for insert to authenticated
with check (public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy inventory_suppliers_update_owner_admin on public.inventory_suppliers for update to authenticated
using (public.has_account_role(account_id,array['owner','admin']::public.account_role[]))
with check (public.has_account_role(account_id,array['owner','admin']::public.account_role[]));
create policy inventory_suppliers_delete_owner_admin on public.inventory_suppliers for delete to authenticated
using (public.has_account_role(account_id,array['owner','admin']::public.account_role[]));

create policy inventory_purchases_select_members on public.inventory_purchases for select to authenticated
using (public.is_account_member(account_id));
create policy inventory_purchase_lines_select_members on public.inventory_purchase_lines for select to authenticated
using (public.is_account_member(account_id));
create policy inventory_receipts_select_members on public.inventory_receipts for select to authenticated
using (public.is_account_member(account_id));
create policy inventory_receipt_lines_select_members on public.inventory_receipt_lines for select to authenticated
using (public.is_account_member(account_id));

create or replace function public.protect_inventory_purchase_history()
returns trigger language plpgsql set search_path = '' as $$
begin
  if tg_op = 'DELETE' then
    raise exception 'purchase history cannot be deleted; cancel an eligible purchase instead' using errcode='42501';
  end if;
  if (to_jsonb(new)-array['status','updated_at','updated_by','cancelled_at','cancelled_by','cancellation_reason','cancellation_request_id'])
    is distinct from
    (to_jsonb(old)-array['status','updated_at','updated_by','cancelled_at','cancelled_by','cancellation_reason','cancellation_request_id']) then
    raise exception 'posted purchase facts are immutable' using errcode='42501';
  end if;
  if old.status in ('received','cancelled') and new.status <> old.status then
    raise exception 'completed or cancelled purchase status is immutable' using errcode='23514';
  end if;
  if old.status='draft' and new.status not in ('draft','partially_received','received','cancelled') then
    raise exception 'invalid purchase status transition' using errcode='23514';
  end if;
  if old.status='partially_received' and new.status not in ('partially_received','received','cancelled') then
    raise exception 'invalid purchase status transition' using errcode='23514';
  end if;
  return new;
end;
$$;

create trigger inventory_purchases_protect_history
before update or delete on public.inventory_purchases
for each row execute function public.protect_inventory_purchase_history();

create or replace function public.create_inventory_purchase(
  target_account_id uuid,
  target_supplier_id uuid,
  target_purchase_number text,
  target_purchase_date date,
  target_supplier_reference text,
  target_currency_code text,
  target_notes text,
  target_lines jsonb,
  target_client_request_id uuid
)
returns public.inventory_purchases
language plpgsql security definer set search_path = '' as $$
declare
  actor_id uuid := (select auth.uid());
  supplier_record public.inventory_suppliers%rowtype;
  item_record public.inventory_items%rowtype;
  existing_purchase public.inventory_purchases%rowtype;
  result public.inventory_purchases%rowtype;
  line_payload jsonb;
  normalized_number text := nullif(btrim(target_purchase_number),'');
  normalized_reference text := nullif(btrim(target_supplier_reference),'');
  normalized_currency text := upper(nullif(btrim(target_currency_code),''));
  normalized_notes text := nullif(btrim(target_notes),'');
  actor_name text;
  input_count integer;
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required for purchasing' using errcode='42501';
  end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if normalized_number is null then raise exception 'purchase number is required' using errcode='22023'; end if;
  if target_purchase_date is null or target_purchase_date > (statement_timestamp() at time zone 'Asia/Jakarta')::date then
    raise exception 'purchase date is required and cannot be in the future' using errcode='22007';
  end if;
  if normalized_currency <> 'IDR' then raise exception 'M2.4C supports IDR purchases only' using errcode='22023'; end if;
  if target_lines is null or jsonb_typeof(target_lines)<>'array' or jsonb_array_length(target_lines)=0 then
    raise exception 'at least one purchase line is required' using errcode='22023';
  end if;
  input_count := jsonb_array_length(target_lines);
  if (select count(distinct value->>'inventory_item_id') from jsonb_array_elements(target_lines)) <> input_count then
    raise exception 'an inventory item may appear only once per purchase' using errcode='23505';
  end if;

  select * into existing_purchase from public.inventory_purchases
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing_purchase.supplier_id=target_supplier_id
      and lower(btrim(existing_purchase.purchase_number))=lower(normalized_number)
      and existing_purchase.purchase_date=target_purchase_date
      and existing_purchase.supplier_reference is not distinct from normalized_reference
      and existing_purchase.currency_code=normalized_currency
      and existing_purchase.notes is not distinct from normalized_notes
      and (select count(*) from public.inventory_purchase_lines where purchase_id=existing_purchase.id)=input_count
      and not exists (
        select 1 from jsonb_array_elements(target_lines) payload
        left join public.inventory_purchase_lines line
          on line.purchase_id=existing_purchase.id and line.inventory_item_id=(payload->>'inventory_item_id')::uuid
          and line.ordered_quantity=(payload->>'quantity')::numeric
          and line.unit_price=(payload->>'unit_price')::numeric
          and line.notes is not distinct from nullif(btrim(payload->>'notes'),'')
        where line.id is null
      ) then return existing_purchase; end if;
    raise exception 'client request id was already used for a different purchase' using errcode='23505';
  end if;

  select * into supplier_record from public.inventory_suppliers
  where id=target_supplier_id and account_id=target_account_id and is_active for key share;
  if not found then raise exception 'active supplier not found in this account' using errcode='P0002'; end if;

  for line_payload in select value from jsonb_array_elements(target_lines) order by value->>'inventory_item_id' loop
    if nullif(line_payload->>'inventory_item_id','') is null then
      raise exception 'inventory item is required for every purchase line' using errcode='22023';
    end if;
    if (line_payload->>'quantity')::numeric <= 0
      or round((line_payload->>'quantity')::numeric,4)<>(line_payload->>'quantity')::numeric then
      raise exception 'ordered quantity must be positive with at most four decimal places' using errcode='22003';
    end if;
    if (line_payload->>'unit_price')::numeric < 0
      or round((line_payload->>'unit_price')::numeric,2)<>(line_payload->>'unit_price')::numeric then
      raise exception 'unit price must be nonnegative with at most two decimal places' using errcode='22003';
    end if;
    select * into item_record from public.inventory_items
    where id=(line_payload->>'inventory_item_id')::uuid and account_id=target_account_id and is_active for key share;
    if not found then raise exception 'active inventory item not found in this account' using errcode='P0002'; end if;
  end loop;

  actor_name := public.inventory_actor_name(actor_id);
  insert into public.inventory_purchases(account_id,supplier_id,purchase_number,purchase_date,supplier_reference,
    currency_code,status,notes,supplier_code_snapshot,supplier_name_snapshot,client_request_id,
    created_by,created_by_name_snapshot,updated_by)
  values(target_account_id,supplier_record.id,normalized_number,target_purchase_date,normalized_reference,
    normalized_currency,'draft',normalized_notes,supplier_record.supplier_code,supplier_record.name,
    target_client_request_id,actor_id,actor_name,actor_id)
  returning * into result;

  for line_payload in select value from jsonb_array_elements(target_lines) order by value->>'inventory_item_id' loop
    select * into item_record from public.inventory_items
    where id=(line_payload->>'inventory_item_id')::uuid and account_id=target_account_id;
    insert into public.inventory_purchase_lines(account_id,purchase_id,inventory_item_id,ordered_quantity,unit_price,
      item_sku_snapshot,item_name_snapshot,unit_snapshot,notes)
    values(target_account_id,result.id,item_record.id,(line_payload->>'quantity')::numeric,
      (line_payload->>'unit_price')::numeric,item_record.sku,item_record.name,item_record.unit,
      nullif(btrim(line_payload->>'notes'),''));
  end loop;
  return result;
end;
$$;

create or replace function public.receive_inventory_purchase(
  target_account_id uuid,
  target_purchase_id uuid,
  target_location_id uuid,
  target_received_at timestamptz,
  target_operational_person_id uuid,
  target_notes text,
  target_lines jsonb,
  target_client_request_id uuid
)
returns public.inventory_receipts
language plpgsql security definer set search_path = '' as $$
declare
  actor_id uuid := (select auth.uid());
  purchase_record public.inventory_purchases%rowtype;
  existing_receipt public.inventory_receipts%rowtype;
  result public.inventory_receipts%rowtype;
  location_record public.inventory_locations%rowtype;
  person_record public.operational_people%rowtype;
  line_record public.inventory_purchase_lines%rowtype;
  item_record public.inventory_items%rowtype;
  movement_record public.inventory_movements%rowtype;
  line_payload jsonb;
  normalized_notes text := nullif(btrim(target_notes),'');
  actor_name text;
  input_count integer;
  requested_quantity numeric(20,4);
  received_quantity numeric(20,4);
  receipt_id uuid := gen_random_uuid();
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required to receive inventory' using errcode='42501';
  end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if target_received_at is null or target_received_at>statement_timestamp()+interval '5 minutes' then
    raise exception 'received date and time are required and cannot be in the future' using errcode='22007';
  end if;
  if target_lines is null or jsonb_typeof(target_lines)<>'array' or jsonb_array_length(target_lines)=0 then
    raise exception 'at least one received line is required' using errcode='22023';
  end if;
  input_count := jsonb_array_length(target_lines);
  if (select count(distinct value->>'purchase_line_id') from jsonb_array_elements(target_lines))<>input_count then
    raise exception 'a purchase line may appear only once per receipt' using errcode='23505';
  end if;

  select * into existing_receipt from public.inventory_receipts
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing_receipt.purchase_id=target_purchase_id and existing_receipt.location_id=target_location_id
      and existing_receipt.received_at=target_received_at
      and existing_receipt.operational_person_id=target_operational_person_id
      and existing_receipt.notes is not distinct from normalized_notes
      and (select count(*) from public.inventory_receipt_lines where receipt_id=existing_receipt.id)=input_count
      and not exists (
        select 1 from jsonb_array_elements(target_lines) payload
        left join public.inventory_receipt_lines line
          on line.receipt_id=existing_receipt.id and line.purchase_line_id=(payload->>'purchase_line_id')::uuid
          and line.quantity=(payload->>'quantity')::numeric
        where line.id is null
      ) then return existing_receipt; end if;
    raise exception 'client request id was already used for a different receipt' using errcode='23505';
  end if;

  select * into purchase_record from public.inventory_purchases
  where id=target_purchase_id and account_id=target_account_id for update;
  if not found then raise exception 'purchase not found in this account' using errcode='P0002'; end if;
  if purchase_record.status='cancelled' then raise exception 'cancelled purchase cannot be received' using errcode='23514'; end if;
  if purchase_record.status='received' then raise exception 'purchase is already fully received' using errcode='22003'; end if;

  select * into existing_receipt from public.inventory_receipts
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    if existing_receipt.purchase_id=target_purchase_id and existing_receipt.location_id=target_location_id
      and existing_receipt.received_at=target_received_at
      and existing_receipt.operational_person_id=target_operational_person_id
      and existing_receipt.notes is not distinct from normalized_notes
      and (select count(*) from public.inventory_receipt_lines where receipt_id=existing_receipt.id)=input_count
      and not exists (
        select 1 from jsonb_array_elements(target_lines) payload
        left join public.inventory_receipt_lines line
          on line.receipt_id=existing_receipt.id and line.purchase_line_id=(payload->>'purchase_line_id')::uuid
          and line.quantity=(payload->>'quantity')::numeric where line.id is null
      ) then return existing_receipt; end if;
    raise exception 'client request id was already used for a different receipt' using errcode='23505';
  end if;

  perform line.id from public.inventory_purchase_lines line
  join jsonb_array_elements(target_lines) payload on line.id=(payload->>'purchase_line_id')::uuid
  where line.purchase_id=purchase_record.id and line.account_id=target_account_id
  order by line.id for update of line;
  if (select count(*) from public.inventory_purchase_lines line
      join jsonb_array_elements(target_lines) payload on line.id=(payload->>'purchase_line_id')::uuid
      where line.purchase_id=purchase_record.id and line.account_id=target_account_id)<>input_count then
    raise exception 'one or more purchase lines do not belong to this purchase' using errcode='23503';
  end if;

  perform item.id from public.inventory_items item
  join public.inventory_purchase_lines line on line.inventory_item_id=item.id
  join jsonb_array_elements(target_lines) payload on line.id=(payload->>'purchase_line_id')::uuid
  where item.account_id=target_account_id and item.is_active order by item.id for update of item;
  if (select count(*) from public.inventory_items item
      join public.inventory_purchase_lines line on line.inventory_item_id=item.id
      join jsonb_array_elements(target_lines) payload on line.id=(payload->>'purchase_line_id')::uuid
      where item.account_id=target_account_id and item.is_active)<>input_count then
    raise exception 'one or more inventory items are archived or unavailable' using errcode='P0002';
  end if;

  select * into location_record from public.inventory_locations
  where id=target_location_id and account_id=target_account_id and is_active for key share;
  if not found then raise exception 'active inventory location not found in this account' using errcode='P0002'; end if;
  select * into person_record from public.operational_people
  where id=target_operational_person_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active PIC / Operator not found in this account' using errcode='P0002'; end if;

  for line_payload in select value from jsonb_array_elements(target_lines) order by value->>'purchase_line_id' loop
    requested_quantity := (line_payload->>'quantity')::numeric;
    if requested_quantity<=0 or round(requested_quantity,4)<>requested_quantity then
      raise exception 'received quantity must be positive with at most four decimal places' using errcode='22003';
    end if;
    select * into line_record from public.inventory_purchase_lines
    where id=(line_payload->>'purchase_line_id')::uuid and purchase_id=purchase_record.id;
    select coalesce(sum(quantity),0) into received_quantity from public.inventory_receipt_lines
    where purchase_line_id=line_record.id;
    if received_quantity+requested_quantity>line_record.ordered_quantity then
      raise exception 'received quantity exceeds remaining quantity for %',line_record.item_name_snapshot using errcode='22003';
    end if;
  end loop;

  actor_name := public.inventory_actor_name(actor_id);
  insert into public.inventory_receipts(id,account_id,purchase_id,supplier_id,location_id,receipt_number,
    received_at,operational_person_id,operational_person_name_snapshot,purchase_number_snapshot,
    supplier_code_snapshot,supplier_name_snapshot,currency_code,notes,client_request_id,
    created_by,created_by_name_snapshot)
  values(receipt_id,target_account_id,purchase_record.id,purchase_record.supplier_id,location_record.id,
    'RCV-'||to_char(target_received_at at time zone 'Asia/Jakarta','YYYYMMDD')||'-'||upper(left(replace(receipt_id::text,'-',''),8)),
    target_received_at,person_record.id,person_record.name,purchase_record.purchase_number,
    purchase_record.supplier_code_snapshot,purchase_record.supplier_name_snapshot,purchase_record.currency_code,
    normalized_notes,target_client_request_id,actor_id,actor_name)
  returning * into result;

  for line_payload in select value from jsonb_array_elements(target_lines) order by value->>'purchase_line_id' loop
    requested_quantity := (line_payload->>'quantity')::numeric;
    select * into line_record from public.inventory_purchase_lines
    where id=(line_payload->>'purchase_line_id')::uuid and purchase_id=purchase_record.id;
    select * into item_record from public.inventory_items where id=line_record.inventory_item_id;
    insert into public.inventory_movements(account_id,inventory_item_id,location_id,movement_type,quantity,
      unit_snapshot,occurred_at,operational_person_id,operational_person_name_snapshot,reference_type,
      reference_id,reason,notes,client_request_id,created_by,created_by_name_snapshot)
    values(target_account_id,item_record.id,location_record.id,'receipt',requested_quantity,item_record.unit,
      target_received_at,person_record.id,person_record.name,'purchase_receipt',result.id,
      'Purchase receipt · '||purchase_record.purchase_number,normalized_notes,gen_random_uuid(),actor_id,actor_name)
    returning * into movement_record;
    insert into public.inventory_receipt_lines(account_id,receipt_id,purchase_line_id,inventory_item_id,
      inventory_movement_id,quantity,unit_price_snapshot,item_sku_snapshot,item_name_snapshot,unit_snapshot)
    values(target_account_id,result.id,line_record.id,line_record.inventory_item_id,movement_record.id,
      requested_quantity,line_record.unit_price,line_record.item_sku_snapshot,line_record.item_name_snapshot,
      line_record.unit_snapshot);
  end loop;

  update public.inventory_purchases set
    status=case when exists (
      select 1 from public.inventory_purchase_lines line
      where line.purchase_id=purchase_record.id and
        coalesce((select sum(receipt_line.quantity) from public.inventory_receipt_lines receipt_line
          where receipt_line.purchase_line_id=line.id),0)<line.ordered_quantity
    ) then 'partially_received'::public.inventory_purchase_status else 'received'::public.inventory_purchase_status end,
    updated_at=statement_timestamp(),updated_by=actor_id
  where id=purchase_record.id;
  return result;
end;
$$;

create or replace function public.cancel_inventory_purchase(
  target_account_id uuid,target_purchase_id uuid,target_reason text,target_client_request_id uuid
)
returns public.inventory_purchases
language plpgsql security definer set search_path = '' as $$
declare
  actor_id uuid := (select auth.uid());
  purchase_record public.inventory_purchases%rowtype;
  normalized_reason text := nullif(btrim(target_reason),'');
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required for purchasing' using errcode='42501';
  end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if normalized_reason is null then raise exception 'cancellation reason is required' using errcode='22023'; end if;
  select * into purchase_record from public.inventory_purchases
  where id=target_purchase_id and account_id=target_account_id for update;
  if not found then raise exception 'purchase not found in this account' using errcode='P0002'; end if;
  if purchase_record.status='cancelled' then
    if purchase_record.cancellation_request_id=target_client_request_id
      and purchase_record.cancellation_reason=normalized_reason then return purchase_record; end if;
    raise exception 'purchase is already cancelled with different request data' using errcode='23505';
  end if;
  if purchase_record.status='received' then
    raise exception 'fully received purchase cannot be cancelled' using errcode='23514';
  end if;
  if exists (select 1 from public.inventory_purchases where account_id=target_account_id
    and cancellation_request_id=target_client_request_id and id<>purchase_record.id) then
    raise exception 'client request id was already used for a different cancellation' using errcode='23505';
  end if;
  update public.inventory_purchases set status='cancelled',cancelled_at=statement_timestamp(),
    cancelled_by=actor_id,cancellation_reason=normalized_reason,cancellation_request_id=target_client_request_id,
    updated_at=statement_timestamp(),updated_by=actor_id
  where id=purchase_record.id returning * into purchase_record;
  return purchase_record;
end;
$$;

create or replace view public.inventory_movement_history
with (security_invoker = true) as
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
  receipt.currency_code as receipt_currency_code,receipt_line.acquisition_value as receipt_acquisition_value
from public.inventory_movements movement
join public.inventory_items item on item.id=movement.inventory_item_id
join public.inventory_locations location on location.id=movement.location_id
left join public.component_replacement_events replacement
  on movement.reference_type='component_replacement' and replacement.id=movement.reference_id
left join public.machines machine on machine.id=replacement.machine_id
left join public.components component on component.id=replacement.component_id
left join public.inventory_receipts receipt
  on movement.reference_type='purchase_receipt' and receipt.id=movement.reference_id
left join public.inventory_receipt_lines receipt_line on receipt_line.inventory_movement_id=movement.id;

revoke all on type public.inventory_purchase_status from public;
grant usage on type public.inventory_purchase_status to authenticated,service_role;

revoke all on table public.inventory_suppliers,public.inventory_purchases,public.inventory_purchase_lines,
  public.inventory_receipts,public.inventory_receipt_lines,public.inventory_purchase_line_status,
  public.inventory_purchase_summary,public.inventory_receipt_history,public.inventory_purchase_cost_history,
  public.inventory_item_last_purchase_prices from public,anon,authenticated,service_role;
grant select on table public.inventory_suppliers,public.inventory_purchases,public.inventory_purchase_lines,
  public.inventory_receipts,public.inventory_receipt_lines,public.inventory_purchase_line_status,
  public.inventory_purchase_summary,public.inventory_receipt_history,public.inventory_purchase_cost_history,
  public.inventory_item_last_purchase_prices to authenticated,service_role;
grant insert (id,account_id,supplier_code,name,contact_person,phone,email,address,notes,is_active)
  on public.inventory_suppliers to authenticated;
grant update (supplier_code,name,contact_person,phone,email,address,notes,is_active)
  on public.inventory_suppliers to authenticated;
grant delete on public.inventory_suppliers to authenticated;
grant insert,update,delete on public.inventory_suppliers to service_role;
grant insert,update on public.inventory_purchases to service_role;
grant insert on public.inventory_purchase_lines,public.inventory_receipts,public.inventory_receipt_lines to service_role;

revoke all on function public.protect_inventory_purchase_history(),
  public.create_inventory_purchase(uuid,uuid,text,date,text,text,text,jsonb,uuid),
  public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid),
  public.cancel_inventory_purchase(uuid,uuid,text,uuid)
  from public,anon,authenticated,service_role;
grant execute on function public.create_inventory_purchase(uuid,uuid,text,date,text,text,text,jsonb,uuid),
  public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid),
  public.cancel_inventory_purchase(uuid,uuid,text,uuid) to authenticated;

-- The replaced history view receives a fresh ACL; preserve read-only member access.
revoke all on table public.inventory_movement_history from public,anon,authenticated,service_role;
grant select on table public.inventory_movement_history to authenticated,service_role;

comment on function public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid)
  is 'Atomic M2.4C receipt. Lock order: purchase, purchase lines, inventory items, inventory location.';
