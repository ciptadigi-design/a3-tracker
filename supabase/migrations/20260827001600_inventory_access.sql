-- A3 Tracker V2 - M2.4A inventory RLS and transactional mutation boundary.

alter table public.inventory_items enable row level security;
alter table public.inventory_locations enable row level security;
alter table public.inventory_movements enable row level security;

create policy inventory_items_select_members on public.inventory_items for select to authenticated
using (public.is_account_member(account_id));
create policy inventory_items_insert_owner_admin on public.inventory_items for insert to authenticated
with check (public.has_account_role(account_id, array['owner','admin']::public.account_role[]));
create policy inventory_items_update_owner_admin on public.inventory_items for update to authenticated
using (public.has_account_role(account_id, array['owner','admin']::public.account_role[]))
with check (public.has_account_role(account_id, array['owner','admin']::public.account_role[]));
create policy inventory_items_delete_owner_admin on public.inventory_items for delete to authenticated
using (public.has_account_role(account_id, array['owner','admin']::public.account_role[]));

create policy inventory_locations_select_members on public.inventory_locations for select to authenticated
using (public.is_account_member(account_id));
create policy inventory_locations_insert_owner_admin on public.inventory_locations for insert to authenticated
with check (public.has_account_role(account_id, array['owner','admin']::public.account_role[]));
create policy inventory_locations_update_owner_admin on public.inventory_locations for update to authenticated
using (public.has_account_role(account_id, array['owner','admin']::public.account_role[]))
with check (public.has_account_role(account_id, array['owner','admin']::public.account_role[]));
create policy inventory_locations_delete_owner_admin on public.inventory_locations for delete to authenticated
using (public.has_account_role(account_id, array['owner','admin']::public.account_role[]));

create policy inventory_movements_select_members on public.inventory_movements for select to authenticated
using (public.is_account_member(account_id));

create or replace function public.inventory_actor_name(actor_id uuid)
returns text language sql stable security definer set search_path = '' as $$
  select coalesce(nullif(btrim(profile.display_name), ''), 'Authenticated user')
  from public.profiles profile where profile.user_id = actor_id;
$$;

create or replace function public.initialize_inventory_stock(
  target_account_id uuid, target_inventory_item_id uuid, target_location_id uuid,
  target_quantity numeric, target_occurred_at timestamptz, target_operational_person_id uuid,
  target_notes text, target_client_request_id uuid
) returns public.inventory_movements
language plpgsql security definer set search_path = '' as $$
declare
  actor_id uuid := (select auth.uid()); item public.inventory_items%rowtype;
  location public.inventory_locations%rowtype; person public.operational_people%rowtype;
  existing public.inventory_movements%rowtype; result public.inventory_movements%rowtype;
  actor_name text; normalized_notes text := nullif(btrim(target_notes), '');
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required for inventory mutation' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if target_quantity is null or target_quantity <= 0 or round(target_quantity,4) <> target_quantity then
    raise exception 'opening quantity must be positive with at most four decimal places' using errcode='22003'; end if;
  if target_occurred_at is null or target_occurred_at > statement_timestamp() + interval '5 minutes' then
    raise exception 'effective date and time are invalid' using errcode='22007'; end if;

  select * into existing from public.inventory_movements
  where account_id=target_account_id and client_request_id=target_client_request_id limit 1;
  if found then
    if existing.movement_type='opening_balance' and existing.inventory_item_id=target_inventory_item_id
      and existing.location_id=target_location_id and existing.quantity=target_quantity
      and existing.occurred_at=target_occurred_at and existing.operational_person_id=target_operational_person_id
      and existing.notes is not distinct from normalized_notes then return existing; end if;
    raise exception 'client request id was already used for a different inventory operation' using errcode='23505';
  end if;

  select * into item from public.inventory_items
  where id=target_inventory_item_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active inventory item not found in this account' using errcode='P0002'; end if;
  select * into existing from public.inventory_movements
  where account_id=target_account_id and client_request_id=target_client_request_id limit 1;
  if found then
    if existing.movement_type='opening_balance' and existing.inventory_item_id=target_inventory_item_id
      and existing.location_id=target_location_id and existing.quantity=target_quantity
      and existing.occurred_at=target_occurred_at and existing.operational_person_id=target_operational_person_id
      and existing.notes is not distinct from normalized_notes then return existing; end if;
    raise exception 'client request id was already used for a different inventory operation' using errcode='23505';
  end if;
  select * into location from public.inventory_locations
  where id=target_location_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active inventory location not found in this account' using errcode='P0002'; end if;
  select * into person from public.operational_people
  where id=target_operational_person_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active PIC / Operator not found in this account' using errcode='P0002'; end if;
  if exists (select 1 from public.inventory_movements where account_id=target_account_id
    and inventory_item_id=target_inventory_item_id and location_id=target_location_id and movement_type='opening_balance') then
    raise exception 'opening balance already exists for this item and location' using errcode='23505'; end if;
  actor_name := public.inventory_actor_name(actor_id);
  insert into public.inventory_movements(account_id,inventory_item_id,location_id,movement_type,quantity,
    unit_snapshot,occurred_at,operational_person_id,operational_person_name_snapshot,reference_type,
    notes,client_request_id,created_by,created_by_name_snapshot)
  values(target_account_id,item.id,location.id,'opening_balance',target_quantity,item.unit,target_occurred_at,
    person.id,person.name,'opening_balance',normalized_notes,target_client_request_id,actor_id,actor_name)
  returning * into result;
  return result;
end;
$$;

create or replace function public.adjust_inventory_stock(
  target_account_id uuid, target_inventory_item_id uuid, target_location_id uuid,
  target_quantity_delta numeric, target_occurred_at timestamptz, target_operational_person_id uuid,
  target_reason text, target_notes text, target_client_request_id uuid
) returns public.inventory_movements
language plpgsql security definer set search_path = '' as $$
declare
  actor_id uuid := (select auth.uid()); item public.inventory_items%rowtype;
  location public.inventory_locations%rowtype; person public.operational_people%rowtype;
  existing public.inventory_movements%rowtype; result public.inventory_movements%rowtype;
  current_quantity numeric(20,4); actor_name text;
  normalized_reason text := nullif(btrim(target_reason), ''); normalized_notes text := nullif(btrim(target_notes), '');
  resolved_type public.inventory_movement_type;
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required for inventory mutation' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if target_quantity_delta is null or target_quantity_delta=0 or round(target_quantity_delta,4)<>target_quantity_delta then
    raise exception 'adjustment must be non-zero with at most four decimal places' using errcode='22003'; end if;
  if normalized_reason is null then raise exception 'adjustment reason is required' using errcode='22023'; end if;
  if target_occurred_at is null or target_occurred_at > statement_timestamp()+interval '5 minutes' then
    raise exception 'effective date and time are invalid' using errcode='22007'; end if;
  resolved_type := case when target_quantity_delta>0 then 'adjustment_in' else 'adjustment_out' end;

  select * into existing from public.inventory_movements
  where account_id=target_account_id and client_request_id=target_client_request_id limit 1;
  if found then
    if existing.movement_type=resolved_type and existing.inventory_item_id=target_inventory_item_id
      and existing.location_id=target_location_id and existing.quantity=target_quantity_delta
      and existing.occurred_at=target_occurred_at and existing.operational_person_id=target_operational_person_id
      and existing.reason=normalized_reason and existing.notes is not distinct from normalized_notes then return existing; end if;
    raise exception 'client request id was already used for a different inventory operation' using errcode='23505';
  end if;
  select * into item from public.inventory_items
  where id=target_inventory_item_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active inventory item not found in this account' using errcode='P0002'; end if;
  select * into existing from public.inventory_movements
  where account_id=target_account_id and client_request_id=target_client_request_id limit 1;
  if found then
    if existing.movement_type=resolved_type and existing.inventory_item_id=target_inventory_item_id
      and existing.location_id=target_location_id and existing.quantity=target_quantity_delta
      and existing.occurred_at=target_occurred_at and existing.operational_person_id=target_operational_person_id
      and existing.reason=normalized_reason and existing.notes is not distinct from normalized_notes then return existing; end if;
    raise exception 'client request id was already used for a different inventory operation' using errcode='23505';
  end if;
  select * into location from public.inventory_locations
  where id=target_location_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active inventory location not found in this account' using errcode='P0002'; end if;
  select * into person from public.operational_people
  where id=target_operational_person_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active PIC / Operator not found in this account' using errcode='P0002'; end if;
  select coalesce(sum(quantity),0) into current_quantity from public.inventory_movements
  where account_id=target_account_id and inventory_item_id=item.id and location_id=location.id;
  if current_quantity+target_quantity_delta < 0 then raise exception 'insufficient stock for adjustment' using errcode='22003'; end if;
  actor_name := public.inventory_actor_name(actor_id);
  insert into public.inventory_movements(account_id,inventory_item_id,location_id,movement_type,quantity,
    unit_snapshot,occurred_at,operational_person_id,operational_person_name_snapshot,reference_type,
    reason,notes,client_request_id,created_by,created_by_name_snapshot)
  values(target_account_id,item.id,location.id,resolved_type,target_quantity_delta,item.unit,target_occurred_at,
    person.id,person.name,'manual_adjustment',normalized_reason,normalized_notes,target_client_request_id,actor_id,actor_name)
  returning * into result;
  return result;
end;
$$;

create or replace function public.transfer_inventory_stock(
  target_account_id uuid, target_inventory_item_id uuid, target_source_location_id uuid,
  target_destination_location_id uuid, target_quantity numeric, target_occurred_at timestamptz,
  target_operational_person_id uuid, target_notes text, target_client_request_id uuid
) returns table(transfer_id uuid, transfer_out_id uuid, transfer_in_id uuid)
language plpgsql security definer set search_path = '' as $$
declare
  actor_id uuid := (select auth.uid()); item public.inventory_items%rowtype;
  source_location public.inventory_locations%rowtype; destination_location public.inventory_locations%rowtype;
  person public.operational_people%rowtype; existing_out public.inventory_movements%rowtype;
  existing_in public.inventory_movements%rowtype; out_row public.inventory_movements%rowtype;
  in_row public.inventory_movements%rowtype; current_quantity numeric(20,4); actor_name text;
  resolved_transfer_id uuid := gen_random_uuid(); normalized_notes text := nullif(btrim(target_notes), '');
begin
  if actor_id is null then raise exception 'authentication required' using errcode='42501'; end if;
  if not public.has_account_role(target_account_id,array['owner','admin']::public.account_role[]) then
    raise exception 'owner or admin role required for inventory mutation' using errcode='42501'; end if;
  if target_client_request_id is null then raise exception 'client request id is required' using errcode='22023'; end if;
  if target_source_location_id=target_destination_location_id then raise exception 'source and destination must differ' using errcode='22023'; end if;
  if target_quantity is null or target_quantity<=0 or round(target_quantity,4)<>target_quantity then
    raise exception 'transfer quantity must be positive with at most four decimal places' using errcode='22003'; end if;
  if target_occurred_at is null or target_occurred_at>statement_timestamp()+interval '5 minutes' then
    raise exception 'effective date and time are invalid' using errcode='22007'; end if;
  select * into existing_out from public.inventory_movements where account_id=target_account_id
    and client_request_id=target_client_request_id and movement_type='transfer_out';
  if found then
    select * into existing_in from public.inventory_movements where account_id=target_account_id
      and client_request_id=target_client_request_id and movement_type='transfer_in';
    if existing_in.id is not null and existing_out.inventory_item_id=target_inventory_item_id
      and existing_out.location_id=target_source_location_id and existing_in.location_id=target_destination_location_id
      and existing_out.quantity=-target_quantity and existing_in.quantity=target_quantity
      and existing_out.occurred_at=target_occurred_at and existing_out.operational_person_id=target_operational_person_id
      and existing_out.notes is not distinct from normalized_notes then
      return query select existing_out.transfer_id, existing_out.id, existing_in.id; return;
    end if;
    raise exception 'client request id was already used for a different inventory operation' using errcode='23505';
  end if;
  select * into item from public.inventory_items
  where id=target_inventory_item_id and account_id=target_account_id and is_active for update;
  if not found then raise exception 'active inventory item not found in this account' using errcode='P0002'; end if;
  select * into existing_out from public.inventory_movements where account_id=target_account_id
    and client_request_id=target_client_request_id and movement_type='transfer_out';
  if found then
    select * into existing_in from public.inventory_movements where account_id=target_account_id
      and client_request_id=target_client_request_id and movement_type='transfer_in';
    if existing_in.id is not null and existing_out.inventory_item_id=target_inventory_item_id
      and existing_out.location_id=target_source_location_id and existing_in.location_id=target_destination_location_id
      and existing_out.quantity=-target_quantity and existing_in.quantity=target_quantity
      and existing_out.occurred_at=target_occurred_at and existing_out.operational_person_id=target_operational_person_id
      and existing_out.notes is not distinct from normalized_notes then
      return query select existing_out.transfer_id, existing_out.id, existing_in.id; return;
    end if;
    raise exception 'client request id was already used for a different inventory operation' using errcode='23505';
  end if;
  select * into source_location from public.inventory_locations
  where id=target_source_location_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active source location not found in this account' using errcode='P0002'; end if;
  select * into destination_location from public.inventory_locations
  where id=target_destination_location_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active destination location not found in this account' using errcode='P0002'; end if;
  select * into person from public.operational_people
  where id=target_operational_person_id and account_id=target_account_id and is_active;
  if not found then raise exception 'active PIC / Operator not found in this account' using errcode='P0002'; end if;
  select coalesce(sum(quantity),0) into current_quantity from public.inventory_movements
  where account_id=target_account_id and inventory_item_id=item.id and location_id=source_location.id;
  if current_quantity<target_quantity then raise exception 'insufficient stock for transfer' using errcode='22003'; end if;
  actor_name := public.inventory_actor_name(actor_id);
  insert into public.inventory_movements(account_id,inventory_item_id,location_id,movement_type,quantity,
    unit_snapshot,occurred_at,operational_person_id,operational_person_name_snapshot,reference_type,
    reference_id,notes,client_request_id,transfer_id,created_by,created_by_name_snapshot)
  values(target_account_id,item.id,source_location.id,'transfer_out',-target_quantity,item.unit,target_occurred_at,
    person.id,person.name,'stock_transfer',resolved_transfer_id,normalized_notes,target_client_request_id,
    resolved_transfer_id,actor_id,actor_name) returning * into out_row;
  insert into public.inventory_movements(account_id,inventory_item_id,location_id,movement_type,quantity,
    unit_snapshot,occurred_at,operational_person_id,operational_person_name_snapshot,reference_type,
    reference_id,notes,client_request_id,transfer_id,created_by,created_by_name_snapshot)
  values(target_account_id,item.id,destination_location.id,'transfer_in',target_quantity,item.unit,target_occurred_at,
    person.id,person.name,'stock_transfer',resolved_transfer_id,normalized_notes,target_client_request_id,
    resolved_transfer_id,actor_id,actor_name) returning * into in_row;
  return query select resolved_transfer_id, out_row.id, in_row.id;
end;
$$;

revoke all on type public.inventory_unit, public.inventory_movement_type, public.inventory_reference_type from public;
grant usage on type public.inventory_unit, public.inventory_movement_type, public.inventory_reference_type to authenticated, service_role;

revoke all on table public.inventory_items, public.inventory_locations, public.inventory_movements,
  public.inventory_stock_balances, public.inventory_item_totals, public.inventory_movement_history
  from public, anon, authenticated, service_role;
grant select on public.inventory_items, public.inventory_locations, public.inventory_movements,
  public.inventory_stock_balances, public.inventory_item_totals, public.inventory_movement_history
  to authenticated, service_role;
grant insert (account_id,component_id,sku,name,category,unit,minimum_stock,notes,is_active) on public.inventory_items to authenticated;
grant insert (id) on public.inventory_items to authenticated;
grant update (component_id,sku,name,category,unit,minimum_stock,notes,is_active) on public.inventory_items to authenticated;
grant delete on public.inventory_items to authenticated;
grant insert (account_id,branch_id,code,name,notes,is_active) on public.inventory_locations to authenticated;
grant insert (id) on public.inventory_locations to authenticated;
grant update (branch_id,code,name,notes,is_active) on public.inventory_locations to authenticated;
grant delete on public.inventory_locations to authenticated;
grant select,insert,update,delete on public.inventory_items,public.inventory_locations to service_role;
grant select,insert on public.inventory_movements to service_role;

revoke all on function public.inventory_actor_name(uuid),
  public.initialize_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.adjust_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid),
  public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid)
  from public, anon, authenticated, service_role;
grant execute on function public.initialize_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid),
  public.adjust_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid),
  public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid)
  to authenticated;

comment on function public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid)
  is 'Atomic paired transfer. The item row lock serializes all balance-changing operations for that item.';
