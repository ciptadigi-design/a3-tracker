-- A3 Tracker V2 - M2.4D inventory cost allocation and lifecycle cost foundation.
-- FIFO policy is fixed and historically versioned as fifo_v1.

create table public.inventory_cost_lots (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  inventory_item_id uuid not null,
  location_id uuid not null,
  inbound_movement_id uuid not null,
  source_receipt_line_id uuid,
  origin_receipt_line_id uuid,
  source_transfer_allocation_id uuid,
  source_type text not null,
  source_quantity numeric(20,4) not null,
  unit_cost numeric(20,2),
  effective_at timestamptz not null,
  created_at timestamptz not null default statement_timestamp(),
  constraint inventory_cost_lots_id_account_key unique (id, account_id),
  constraint inventory_cost_lots_item_account_fkey foreign key (inventory_item_id, account_id)
    references public.inventory_items(id, account_id) on delete restrict,
  constraint inventory_cost_lots_location_account_fkey foreign key (location_id, account_id)
    references public.inventory_locations(id, account_id) on delete restrict,
  constraint inventory_cost_lots_movement_account_fkey foreign key (inbound_movement_id, account_id)
    references public.inventory_movements(id, account_id) on delete restrict,
  constraint inventory_cost_lots_receipt_line_account_fkey foreign key (source_receipt_line_id, account_id)
    references public.inventory_receipt_lines(id, account_id) on delete restrict,
  constraint inventory_cost_lots_origin_receipt_line_account_fkey foreign key (origin_receipt_line_id, account_id)
    references public.inventory_receipt_lines(id, account_id) on delete restrict,
  constraint inventory_cost_lots_source_type_check check (source_type in ('receipt','opening_balance','adjustment_in','transfer_in')),
  constraint inventory_cost_lots_quantity_positive check (source_quantity > 0 and round(source_quantity,4)=source_quantity),
  constraint inventory_cost_lots_unit_cost_valid check (unit_cost is null or (unit_cost >= 0 and round(unit_cost,2)=unit_cost)),
  constraint inventory_cost_lots_receipt_consistent check (
    (source_type='receipt' and source_receipt_line_id is not null and origin_receipt_line_id=source_receipt_line_id and source_transfer_allocation_id is null)
    or (source_type in ('opening_balance','adjustment_in') and source_receipt_line_id is null and origin_receipt_line_id is null and source_transfer_allocation_id is null)
    or (source_type='transfer_in' and source_receipt_line_id is null and source_transfer_allocation_id is not null)
  )
);

create unique index inventory_cost_lots_direct_movement_key
  on public.inventory_cost_lots (inbound_movement_id) where source_transfer_allocation_id is null;
create unique index inventory_cost_lots_transfer_allocation_key
  on public.inventory_cost_lots (source_transfer_allocation_id) where source_transfer_allocation_id is not null;
create index inventory_cost_lots_fifo_idx
  on public.inventory_cost_lots (account_id,inventory_item_id,location_id,effective_at,created_at,id);

create table public.inventory_cost_allocations (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  outbound_movement_id uuid not null,
  source_cost_lot_id uuid not null,
  quantity numeric(20,4) not null,
  unit_cost numeric(20,2),
  allocated_cost numeric(30,2) generated always as (
    case when unit_cost is null then null else (quantity*unit_cost)::numeric(30,2) end
  ) stored,
  allocation_policy text not null default 'FIFO',
  algorithm_version text not null default 'fifo_v1',
  allocation_order integer not null,
  created_at timestamptz not null default statement_timestamp(),
  constraint inventory_cost_allocations_id_account_key unique (id, account_id),
  constraint inventory_cost_allocations_movement_account_fkey foreign key (outbound_movement_id, account_id)
    references public.inventory_movements(id, account_id) on delete restrict,
  constraint inventory_cost_allocations_lot_account_fkey foreign key (source_cost_lot_id, account_id)
    references public.inventory_cost_lots(id, account_id) on delete restrict,
  constraint inventory_cost_allocations_quantity_positive check (quantity > 0 and round(quantity,4)=quantity),
  constraint inventory_cost_allocations_unit_cost_valid check (unit_cost is null or (unit_cost >= 0 and round(unit_cost,2)=unit_cost)),
  constraint inventory_cost_allocations_policy_check check (allocation_policy='FIFO' and algorithm_version='fifo_v1'),
  constraint inventory_cost_allocations_order_positive check (allocation_order > 0),
  constraint inventory_cost_allocations_movement_order_key unique (outbound_movement_id,allocation_order),
  constraint inventory_cost_allocations_movement_lot_key unique (outbound_movement_id,source_cost_lot_id)
);

alter table public.inventory_cost_lots
  add constraint inventory_cost_lots_transfer_allocation_fkey
  foreign key (source_transfer_allocation_id,account_id)
  references public.inventory_cost_allocations(id,account_id) on delete restrict;

create index inventory_cost_allocations_lot_idx on public.inventory_cost_allocations (source_cost_lot_id,created_at);
create index inventory_cost_allocations_movement_idx on public.inventory_cost_allocations (outbound_movement_id,allocation_order);

create table public.inventory_cost_inputs (
  account_id uuid not null references public.accounts(id) on delete restrict,
  client_request_id uuid not null,
  operation_type text not null check (operation_type in ('opening_balance','adjustment_in')),
  unit_cost numeric(20,2),
  created_at timestamptz not null default statement_timestamp(),
  primary key (account_id,client_request_id),
  constraint inventory_cost_inputs_unit_cost_valid check (unit_cost is null or (unit_cost >= 0 and round(unit_cost,2)=unit_cost))
);

create table public.inventory_purchase_number_sequences (
  account_id uuid not null references public.accounts(id) on delete restrict,
  period_start date not null,
  last_value integer not null check (last_value > 0),
  updated_at timestamptz not null default statement_timestamp(),
  primary key (account_id,period_start),
  constraint inventory_purchase_number_sequences_month_check check (period_start=date_trunc('month',period_start)::date)
);

create or replace function public.protect_inventory_cost_history()
returns trigger language plpgsql set search_path='' as $$
begin
  raise exception 'inventory cost history is immutable' using errcode='42501';
end;
$$;

create trigger inventory_cost_lots_immutable before update or delete on public.inventory_cost_lots
for each row execute function public.protect_inventory_cost_history();
create trigger inventory_cost_allocations_immutable before update or delete on public.inventory_cost_allocations
for each row execute function public.protect_inventory_cost_history();
create trigger inventory_cost_inputs_immutable before update or delete on public.inventory_cost_inputs
for each row execute function public.protect_inventory_cost_history();

create or replace function public.allocate_inventory_cost_fifo(target_movement_id uuid)
returns void language plpgsql security definer set search_path='' as $$
declare
  movement_record public.inventory_movements%rowtype;
  lot_record record;
  needed numeric(20,4);
  available numeric(20,4);
  take_quantity numeric(20,4);
  next_order integer := 1;
begin
  select * into movement_record from public.inventory_movements where id=target_movement_id;
  if not found or movement_record.movement_type not in ('issue','adjustment_out','transfer_out') then
    raise exception 'FIFO allocation requires an outbound inventory movement' using errcode='23514';
  end if;
  if exists (select 1 from public.inventory_cost_allocations where outbound_movement_id=movement_record.id) then
    return;
  end if;
  needed := -movement_record.quantity;
  for lot_record in
    select lot.*, (lot.source_quantity-coalesce(used.quantity,0))::numeric(20,4) as available_quantity
    from public.inventory_cost_lots lot
    left join lateral (
      select sum(allocation.quantity)::numeric(20,4) as quantity
      from public.inventory_cost_allocations allocation where allocation.source_cost_lot_id=lot.id
    ) used on true
    where lot.account_id=movement_record.account_id
      and lot.inventory_item_id=movement_record.inventory_item_id
      and lot.location_id=movement_record.location_id
      and lot.source_quantity-coalesce(used.quantity,0)>0
    order by lot.effective_at,lot.created_at,lot.id
    for update of lot
  loop
    available := lot_record.available_quantity;
    take_quantity := least(needed,available);
    insert into public.inventory_cost_allocations(
      account_id,outbound_movement_id,source_cost_lot_id,quantity,unit_cost,
      allocation_policy,algorithm_version,allocation_order
    ) values (
      movement_record.account_id,movement_record.id,lot_record.id,take_quantity,lot_record.unit_cost,
      'FIFO','fifo_v1',next_order
    );
    needed := needed-take_quantity;
    next_order := next_order+1;
    exit when needed=0;
  end loop;
  if needed<>0 then
    raise exception 'inventory quantity has no complete FIFO cost basis at this location' using errcode='23514';
  end if;
end;
$$;

create or replace function public.create_inventory_transfer_cost_lots(target_transfer_in_movement_id uuid)
returns void language plpgsql security definer set search_path='' as $$
declare
  incoming public.inventory_movements%rowtype;
  outgoing public.inventory_movements%rowtype;
  allocation_record record;
  transferred numeric(20,4) := 0;
begin
  select * into incoming from public.inventory_movements where id=target_transfer_in_movement_id;
  if not found or incoming.movement_type<>'transfer_in' then
    raise exception 'transfer-in movement required' using errcode='23514';
  end if;
  select * into outgoing from public.inventory_movements
  where account_id=incoming.account_id and transfer_id=incoming.transfer_id and movement_type='transfer_out';
  if not found then raise exception 'matching transfer-out movement is required' using errcode='23514'; end if;
  for allocation_record in
    select allocation.*,lot.origin_receipt_line_id
    from public.inventory_cost_allocations allocation
    join public.inventory_cost_lots lot on lot.id=allocation.source_cost_lot_id
    where allocation.outbound_movement_id=outgoing.id order by allocation.allocation_order
  loop
    insert into public.inventory_cost_lots(
      account_id,inventory_item_id,location_id,inbound_movement_id,origin_receipt_line_id,
      source_transfer_allocation_id,source_type,source_quantity,unit_cost,effective_at
    ) values (
      incoming.account_id,incoming.inventory_item_id,incoming.location_id,incoming.id,
      allocation_record.origin_receipt_line_id,allocation_record.id,'transfer_in',
      allocation_record.quantity,allocation_record.unit_cost,incoming.occurred_at
    ) on conflict (source_transfer_allocation_id) where source_transfer_allocation_id is not null do nothing;
    transferred := transferred+allocation_record.quantity;
  end loop;
  if transferred<>incoming.quantity then
    raise exception 'transfer cost layers do not match transferred quantity' using errcode='23514';
  end if;
end;
$$;

create or replace function public.capture_inventory_movement_cost()
returns trigger language plpgsql security definer set search_path='' as $$
declare input_record public.inventory_cost_inputs%rowtype;
begin
  if new.movement_type in ('opening_balance','adjustment_in') then
    select * into input_record from public.inventory_cost_inputs
    where account_id=new.account_id and client_request_id=new.client_request_id;
    insert into public.inventory_cost_lots(
      account_id,inventory_item_id,location_id,inbound_movement_id,source_type,
      source_quantity,unit_cost,effective_at
    ) values (
      new.account_id,new.inventory_item_id,new.location_id,new.id,new.movement_type::text,
      new.quantity,input_record.unit_cost,new.occurred_at
    );
  elsif new.movement_type in ('issue','adjustment_out','transfer_out') then
    perform public.allocate_inventory_cost_fifo(new.id);
  elsif new.movement_type='transfer_in' then
    perform public.create_inventory_transfer_cost_lots(new.id);
  end if;
  return new;
end;
$$;

create or replace function public.capture_inventory_receipt_cost()
returns trigger language plpgsql security definer set search_path='' as $$
declare movement_record public.inventory_movements%rowtype;
begin
  select * into movement_record from public.inventory_movements where id=new.inventory_movement_id;
  insert into public.inventory_cost_lots(
    account_id,inventory_item_id,location_id,inbound_movement_id,source_receipt_line_id,
    origin_receipt_line_id,source_type,source_quantity,unit_cost,effective_at
  ) values (
    new.account_id,new.inventory_item_id,movement_record.location_id,movement_record.id,new.id,
    new.id,'receipt',new.quantity,new.unit_price_snapshot,movement_record.occurred_at
  );
  return new;
end;
$$;

-- Establish cost layers for all accepted pre-M2.4D movement facts without changing those facts.
do $$
declare movement_record public.inventory_movements%rowtype; receipt_line public.inventory_receipt_lines%rowtype;
begin
  for movement_record in
    select movement.* from public.inventory_movements movement
    order by movement.created_at,
      case movement.movement_type when 'transfer_out' then 1 when 'transfer_in' then 2 else 0 end,
      movement.id
  loop
    if movement_record.movement_type='receipt' then
      select * into receipt_line from public.inventory_receipt_lines where inventory_movement_id=movement_record.id;
      if found then
        insert into public.inventory_cost_lots(account_id,inventory_item_id,location_id,inbound_movement_id,
          source_receipt_line_id,origin_receipt_line_id,source_type,source_quantity,unit_cost,effective_at)
        values(movement_record.account_id,movement_record.inventory_item_id,movement_record.location_id,
          movement_record.id,receipt_line.id,receipt_line.id,'receipt',receipt_line.quantity,
          receipt_line.unit_price_snapshot,movement_record.occurred_at);
      end if;
    elsif movement_record.movement_type in ('opening_balance','adjustment_in') then
      insert into public.inventory_cost_lots(account_id,inventory_item_id,location_id,inbound_movement_id,
        source_type,source_quantity,unit_cost,effective_at)
      values(movement_record.account_id,movement_record.inventory_item_id,movement_record.location_id,
        movement_record.id,movement_record.movement_type::text,movement_record.quantity,null,movement_record.occurred_at);
    elsif movement_record.movement_type in ('issue','adjustment_out','transfer_out') then
      perform public.allocate_inventory_cost_fifo(movement_record.id);
    elsif movement_record.movement_type='transfer_in' then
      perform public.create_inventory_transfer_cost_lots(movement_record.id);
    end if;
  end loop;
end;
$$;

create trigger inventory_movements_capture_cost after insert on public.inventory_movements
for each row execute function public.capture_inventory_movement_cost();
create trigger inventory_receipt_lines_capture_cost after insert on public.inventory_receipt_lines
for each row execute function public.capture_inventory_receipt_cost();

create or replace function public.initialize_inventory_stock_costed(
  target_account_id uuid,target_inventory_item_id uuid,target_location_id uuid,target_quantity numeric,
  target_occurred_at timestamptz,target_operational_person_id uuid,target_notes text,
  target_client_request_id uuid,target_opening_unit_cost numeric default null
) returns public.inventory_movements language plpgsql security definer set search_path='' as $$
declare result public.inventory_movements%rowtype; existing_input public.inventory_cost_inputs%rowtype;
begin
  if target_opening_unit_cost is not null and (target_opening_unit_cost<0 or round(target_opening_unit_cost,2)<>target_opening_unit_cost) then
    raise exception 'opening unit cost must be nonnegative with at most two decimal places' using errcode='22003';
  end if;
  select * into existing_input from public.inventory_cost_inputs where account_id=target_account_id and client_request_id=target_client_request_id;
  if found and (existing_input.operation_type<>'opening_balance' or existing_input.unit_cost is distinct from target_opening_unit_cost) then
    raise exception 'client request id was already used with a different cost basis' using errcode='23505';
  end if;
  insert into public.inventory_cost_inputs(account_id,client_request_id,operation_type,unit_cost)
  values(target_account_id,target_client_request_id,'opening_balance',target_opening_unit_cost) on conflict do nothing;
  result := public.initialize_inventory_stock(target_account_id,target_inventory_item_id,target_location_id,target_quantity,
    target_occurred_at,target_operational_person_id,target_notes,target_client_request_id);
  if (select unit_cost from public.inventory_cost_lots where inbound_movement_id=result.id) is distinct from target_opening_unit_cost then
    raise exception 'existing opening balance has a different cost basis' using errcode='23505';
  end if;
  return result;
end;
$$;

create or replace function public.adjust_inventory_stock_costed(
  target_account_id uuid,target_inventory_item_id uuid,target_location_id uuid,target_quantity numeric,
  target_occurred_at timestamptz,target_operational_person_id uuid,target_reason text,target_notes text,
  target_client_request_id uuid,target_unit_cost numeric default null
) returns public.inventory_movements language plpgsql security definer set search_path='' as $$
declare result public.inventory_movements%rowtype; existing_input public.inventory_cost_inputs%rowtype;
begin
  if target_quantity<0 and target_unit_cost is not null then
    raise exception 'adjustment out uses FIFO cost and cannot accept a unit cost override' using errcode='22023';
  end if;
  if target_unit_cost is not null and (target_unit_cost<0 or round(target_unit_cost,2)<>target_unit_cost) then
    raise exception 'adjustment-in unit cost must be nonnegative with at most two decimal places' using errcode='22003';
  end if;
  if target_quantity>0 then
    select * into existing_input from public.inventory_cost_inputs where account_id=target_account_id and client_request_id=target_client_request_id;
    if found and (existing_input.operation_type<>'adjustment_in' or existing_input.unit_cost is distinct from target_unit_cost) then
      raise exception 'client request id was already used with a different cost basis' using errcode='23505';
    end if;
    insert into public.inventory_cost_inputs(account_id,client_request_id,operation_type,unit_cost)
    values(target_account_id,target_client_request_id,'adjustment_in',target_unit_cost) on conflict do nothing;
  end if;
  result := public.adjust_inventory_stock(target_account_id,target_inventory_item_id,target_location_id,target_quantity,
    target_occurred_at,target_operational_person_id,target_reason,target_notes,target_client_request_id);
  if target_quantity>0 and (select unit_cost from public.inventory_cost_lots where inbound_movement_id=result.id) is distinct from target_unit_cost then
    raise exception 'existing adjustment has a different cost basis' using errcode='23505';
  end if;
  return result;
end;
$$;

create or replace function public.next_inventory_purchase_number(target_account_id uuid,target_purchase_date date)
returns text language plpgsql security definer set search_path='' as $$
declare period date; sequence_value integer;
begin
  if target_purchase_date is null then raise exception 'purchase date is required' using errcode='22023'; end if;
  period := date_trunc('month',target_purchase_date)::date;
  insert into public.inventory_purchase_number_sequences(account_id,period_start,last_value)
  values(target_account_id,period,1)
  on conflict (account_id,period_start) do update set
    last_value=public.inventory_purchase_number_sequences.last_value+1,updated_at=statement_timestamp()
  returning last_value into sequence_value;
  return 'PUR-'||to_char(period,'YYYYMM')||'-'||lpad(sequence_value::text,4,'0');
end;
$$;

create or replace function public.create_inventory_purchase_auto(
  target_account_id uuid,target_supplier_id uuid,target_purchase_date date,target_external_reference text,
  target_currency_code text,target_notes text,target_lines jsonb,target_client_request_id uuid
) returns public.inventory_purchases language plpgsql security definer set search_path='' as $$
declare existing_purchase public.inventory_purchases%rowtype; generated_number text;
begin
  if (select auth.uid()) is null then raise exception 'authentication required' using errcode='42501'; end if;
  perform pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(target_account_id::text||':'||coalesce(target_client_request_id::text,''),0));
  select * into existing_purchase from public.inventory_purchases
  where account_id=target_account_id and client_request_id=target_client_request_id;
  if found then
    return public.create_inventory_purchase(target_account_id,target_supplier_id,existing_purchase.purchase_number,
      target_purchase_date,target_external_reference,target_currency_code,target_notes,target_lines,target_client_request_id);
  end if;
  generated_number := public.next_inventory_purchase_number(target_account_id,target_purchase_date);
  return public.create_inventory_purchase(target_account_id,target_supplier_id,generated_number,target_purchase_date,
    target_external_reference,target_currency_code,target_notes,target_lines,target_client_request_id);
end;
$$;

create view public.inventory_cost_lot_balances with (security_invoker=true) as
select lot.id as cost_lot_id,lot.account_id,lot.inventory_item_id,lot.location_id,lot.inbound_movement_id,
  lot.source_receipt_line_id,lot.origin_receipt_line_id,lot.source_transfer_allocation_id,lot.source_type,
  lot.source_quantity,coalesce(sum(allocation.quantity),0)::numeric(20,4) as allocated_quantity,
  (lot.source_quantity-coalesce(sum(allocation.quantity),0))::numeric(20,4) as remaining_quantity,
  lot.unit_cost,case when lot.unit_cost is null then null else
    ((lot.source_quantity-coalesce(sum(allocation.quantity),0))*lot.unit_cost)::numeric(30,2) end as remaining_cost,
  lot.effective_at,lot.created_at
from public.inventory_cost_lots lot
left join public.inventory_cost_allocations allocation on allocation.source_cost_lot_id=lot.id
group by lot.id;

create view public.inventory_cost_position with (security_invoker=true) as
select balance.account_id,balance.inventory_item_id,balance.location_id,location.branch_id,
  sum(balance.remaining_quantity)::numeric(20,4) as total_quantity,
  coalesce(sum(balance.remaining_quantity) filter (where balance.unit_cost is not null),0)::numeric(20,4) as known_cost_quantity,
  coalesce(sum(balance.remaining_quantity) filter (where balance.unit_cost is null),0)::numeric(20,4) as unknown_cost_quantity,
  coalesce(sum(balance.remaining_cost) filter (where balance.unit_cost is not null),0)::numeric(30,2) as known_inventory_cost,
  count(*) filter (where balance.remaining_quantity>0)::integer as cost_layer_count
from public.inventory_cost_lot_balances balance
join public.inventory_locations location on location.id=balance.location_id
where balance.remaining_quantity>0
group by balance.account_id,balance.inventory_item_id,balance.location_id,location.branch_id;

create view public.inventory_cost_allocation_history with (security_invoker=true) as
select allocation.id as allocation_id,allocation.account_id,allocation.outbound_movement_id,
  movement.inventory_item_id,movement.location_id,location.branch_id,movement.movement_type,movement.reference_type,
  movement.reference_id,movement.occurred_at,allocation.source_cost_lot_id,allocation.quantity,
  allocation.unit_cost,allocation.allocated_cost,allocation.allocation_policy,allocation.algorithm_version,
  allocation.allocation_order,lot.source_type,lot.origin_receipt_line_id,receipt_line.receipt_id,
  receipt.receipt_number,receipt.purchase_id,receipt.purchase_number_snapshot as purchase_number,
  receipt.supplier_id,receipt.supplier_name_snapshot,allocation.created_at
from public.inventory_cost_allocations allocation
join public.inventory_movements movement on movement.id=allocation.outbound_movement_id
join public.inventory_locations location on location.id=movement.location_id
join public.inventory_cost_lots lot on lot.id=allocation.source_cost_lot_id
left join public.inventory_receipt_lines receipt_line on receipt_line.id=lot.origin_receipt_line_id
left join public.inventory_receipts receipt on receipt.id=receipt_line.receipt_id;

create view public.inventory_consumption_cost_history with (security_invoker=true) as
select movement.id as outbound_movement_id,movement.account_id,movement.inventory_item_id,movement.location_id,
  location.branch_id,movement.reference_type,movement.reference_id,movement.occurred_at,
  (-movement.quantity)::numeric(20,4) as consumed_quantity,
  coalesce(sum(allocation.quantity) filter (where allocation.unit_cost is not null),0)::numeric(20,4) as known_cost_quantity,
  coalesce(sum(allocation.quantity) filter (where allocation.unit_cost is null),0)::numeric(20,4) as unknown_cost_quantity,
  coalesce(sum(allocation.allocated_cost),0)::numeric(30,2) as known_consumption_cost,
  bool_and(allocation.unit_cost is not null) as cost_is_complete,
  count(allocation.id)::integer as cost_layer_count
from public.inventory_movements movement
join public.inventory_locations location on location.id=movement.location_id
left join public.inventory_cost_allocations allocation on allocation.outbound_movement_id=movement.id
where movement.movement_type in ('issue','adjustment_out')
group by movement.id,location.branch_id;

create view public.component_lifecycle_costs with (security_invoker=true) as
select lifecycle.id as lifecycle_id,lifecycle.account_id,lifecycle.branch_id,lifecycle.machine_id,
  lifecycle.model_component_profile_id,lifecycle.component_id,lifecycle.slot_code,lifecycle.status,
  lifecycle.installed_at,lifecycle.removed_at,lifecycle.actual_usage,
  installation_event.id as installation_replacement_event_id,installation_event.inventory_source,
  installation_event.inventory_movement_id,
  consumption.known_consumption_cost as installed_component_cost,
  coalesce(consumption.unknown_cost_quantity,0)>0 or installation_event.inventory_source='external_untracked' as cost_is_unknown,
  case when lifecycle.status='closed' and lifecycle.actual_usage>0
    and installation_event.inventory_source='inventory' and consumption.cost_is_complete
    then consumption.known_consumption_cost end as realized_lifecycle_cost,
  case when lifecycle.status='closed' and lifecycle.actual_usage>0
    and installation_event.inventory_source='inventory' and consumption.cost_is_complete
    then round(consumption.known_consumption_cost/lifecycle.actual_usage,4) end as realized_cost_per_click
from public.machine_component_lifecycles lifecycle
left join public.component_replacement_events installation_event on installation_event.new_lifecycle_id=lifecycle.id
left join public.inventory_consumption_cost_history consumption
  on consumption.outbound_movement_id=installation_event.inventory_movement_id;

create view public.monthly_inventory_cost_summary with (security_invoker=true) as
with periods as (
  select purchase.account_id,date_trunc('month',purchase.purchase_date)::date as period_start
  from public.inventory_purchases purchase where purchase.status<>'cancelled'
  union
  select consumption.account_id,date_trunc('month',consumption.occurred_at at time zone 'Asia/Jakarta')::date
  from public.inventory_consumption_cost_history consumption
), purchases as (
  select purchase.account_id,date_trunc('month',purchase.purchase_date)::date as period_start,
    sum(line.line_total)::numeric(30,2) as purchase_cost
  from public.inventory_purchases purchase join public.inventory_purchase_lines line on line.purchase_id=purchase.id
  where purchase.status<>'cancelled' group by purchase.account_id,date_trunc('month',purchase.purchase_date)::date
), consumption as (
  select history.account_id,date_trunc('month',history.occurred_at at time zone 'Asia/Jakarta')::date as period_start,
    coalesce(sum(history.known_consumption_cost),0)::numeric(30,2) as known_consumption_cost,
    coalesce(sum(history.unknown_cost_quantity),0)::numeric(20,4) as unknown_cost_quantity
  from public.inventory_consumption_cost_history history
  where history.reference_type in ('component_replacement','toner_refill','maintenance')
  group by history.account_id,date_trunc('month',history.occurred_at at time zone 'Asia/Jakarta')::date
)
select periods.account_id,periods.period_start,coalesce(purchases.purchase_cost,0)::numeric(30,2) as purchase_cost,
  coalesce(consumption.known_consumption_cost,0)::numeric(30,2) as known_consumption_cost,
  coalesce(consumption.unknown_cost_quantity,0)::numeric(20,4) as unknown_consumption_quantity
from periods left join purchases using(account_id,period_start) left join consumption using(account_id,period_start);

-- Add cost context to existing histories while retaining their established columns.
create or replace view public.inventory_movement_history with (security_invoker=true) as
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
  receipt.currency_code as receipt_currency_code,receipt_line.acquisition_value as receipt_acquisition_value,
  cost.known_cost_quantity,cost.unknown_cost_quantity,cost.known_consumption_cost as allocated_cost,
  cost.cost_is_complete,cost.cost_layer_count
from public.inventory_movements movement
join public.inventory_items item on item.id=movement.inventory_item_id
join public.inventory_locations location on location.id=movement.location_id
left join public.component_replacement_events replacement on movement.reference_type='component_replacement' and replacement.id=movement.reference_id
left join public.machines machine on machine.id=replacement.machine_id
left join public.components component on component.id=replacement.component_id
left join public.inventory_receipts receipt on movement.reference_type='purchase_receipt' and receipt.id=movement.reference_id
left join public.inventory_receipt_lines receipt_line on receipt_line.inventory_movement_id=movement.id
left join lateral (
  select coalesce(sum(allocation.quantity) filter (where allocation.unit_cost is not null),0)::numeric(20,4) as known_cost_quantity,
    coalesce(sum(allocation.quantity) filter (where allocation.unit_cost is null),0)::numeric(20,4) as unknown_cost_quantity,
    coalesce(sum(allocation.allocated_cost),0)::numeric(30,2) as known_consumption_cost,
    bool_and(allocation.unit_cost is not null) as cost_is_complete,count(allocation.id)::integer as cost_layer_count
  from public.inventory_cost_allocations allocation where allocation.outbound_movement_id=movement.id
) cost on movement.movement_type in ('issue','adjustment_out','transfer_out');

create or replace view public.component_replacement_history with (security_invoker=true) as
select event.id as replacement_event_id,event.account_id,event.branch_id,event.machine_id,
  machine.machine_code,machine.display_name as machine_name,event.model_component_profile_id,
  event.component_id,component.code as component_code,component.name as component_name,profile.tracking_method,
  event.slot_code_snapshot,event.previous_lifecycle_id,event.new_lifecycle_id,event.previous_installed_counter,
  event.replacement_counter,event.actual_usage,event.expected_at_install,event.baseline_expected_snapshot,
  event.adaptive_expected_snapshot,case when event.expected_at_install>0 then round(event.actual_usage/event.expected_at_install*100,2) end as performance_percent,
  event.replacement_reason,event.condition_at_removal,event.include_in_adaptive_learning,
  event.performed_by_user_id,event.performed_by_name_snapshot,event.replaced_at,event.notes,event.counter_reading_id,
  new_lifecycle.status as new_lifecycle_status,new_lifecycle.installed_counter as new_installed_counter,
  new_lifecycle.expected_at_install as new_expected_at_install,event.created_by,event.created_at,
  event.inventory_source,event.inventory_movement_id,event.external_inventory_reason,
  movement.inventory_item_id,inventory_item.sku as inventory_item_sku,inventory_item.name as inventory_item_name,
  movement.location_id as inventory_location_id,inventory_location.name as inventory_location_name,
  case when movement.quantity is null then null else -movement.quantity end as inventory_quantity,
  movement.unit_snapshot as inventory_unit,consumption.known_consumption_cost as inventory_consumption_cost,
  consumption.known_cost_quantity as inventory_known_cost_quantity,
  consumption.unknown_cost_quantity as inventory_unknown_cost_quantity,
  consumption.cost_is_complete as inventory_cost_is_complete,consumption.cost_layer_count as inventory_cost_layer_count,
  lifecycle_cost.realized_lifecycle_cost,lifecycle_cost.realized_cost_per_click
from public.component_replacement_events event
join public.machines machine on machine.id=event.machine_id
join public.components component on component.id=event.component_id
join public.machine_model_components profile on profile.id=event.model_component_profile_id
join public.machine_component_lifecycles new_lifecycle on new_lifecycle.id=event.new_lifecycle_id
left join public.inventory_movements movement on movement.id=event.inventory_movement_id
left join public.inventory_items inventory_item on inventory_item.id=movement.inventory_item_id
left join public.inventory_locations inventory_location on inventory_location.id=movement.location_id
left join public.inventory_consumption_cost_history consumption on consumption.outbound_movement_id=movement.id
left join public.component_lifecycle_costs lifecycle_cost on lifecycle_cost.lifecycle_id=event.previous_lifecycle_id;

alter table public.inventory_cost_lots enable row level security;
alter table public.inventory_cost_allocations enable row level security;
alter table public.inventory_cost_inputs enable row level security;
alter table public.inventory_purchase_number_sequences enable row level security;
create policy inventory_cost_lots_select_members on public.inventory_cost_lots for select to authenticated using (public.is_account_member(account_id));
create policy inventory_cost_allocations_select_members on public.inventory_cost_allocations for select to authenticated using (public.is_account_member(account_id));
create policy inventory_cost_inputs_select_members on public.inventory_cost_inputs for select to authenticated using (public.is_account_member(account_id));

revoke all on table public.inventory_cost_lots,public.inventory_cost_allocations,public.inventory_cost_inputs,
  public.inventory_purchase_number_sequences,public.inventory_cost_lot_balances,public.inventory_cost_position,
  public.inventory_cost_allocation_history,public.inventory_consumption_cost_history,public.component_lifecycle_costs,
  public.monthly_inventory_cost_summary from public,anon,authenticated,service_role;
grant select on table public.inventory_cost_lots,public.inventory_cost_allocations,public.inventory_cost_inputs,
  public.inventory_cost_lot_balances,public.inventory_cost_position,public.inventory_cost_allocation_history,
  public.inventory_consumption_cost_history,public.component_lifecycle_costs,public.monthly_inventory_cost_summary
  to authenticated,service_role;
grant select,insert on table public.inventory_cost_lots,public.inventory_cost_allocations,public.inventory_cost_inputs,
  public.inventory_purchase_number_sequences to service_role;

revoke all on function public.protect_inventory_cost_history(),public.allocate_inventory_cost_fifo(uuid),
  public.create_inventory_transfer_cost_lots(uuid),public.capture_inventory_movement_cost(),
  public.capture_inventory_receipt_cost(),public.next_inventory_purchase_number(uuid,date),
  public.initialize_inventory_stock_costed(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid,numeric),
  public.adjust_inventory_stock_costed(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid,numeric),
  public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid)
  from public,anon,authenticated,service_role;
grant execute on function
  public.initialize_inventory_stock_costed(uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid,numeric),
  public.adjust_inventory_stock_costed(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid,numeric),
  public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid)
  to authenticated;

comment on table public.inventory_cost_allocations is 'Immutable location-aware FIFO allocations. Policy FIFO; algorithm_version fifo_v1.';
comment on view public.inventory_cost_position is 'Current operational Inventory Cost Basis, explicitly separating known and unknown-cost quantities.';
comment on function public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)
  is 'Atomic replacement lock order: machine, lifecycle, inventory item, location, then FIFO cost lots ordered by effective_at + created_at + UUID.';
