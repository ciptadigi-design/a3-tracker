-- A3 Tracker V2 - M2.4A inventory foundation.
-- Account-owned item/location masters and an immutable signed-quantity ledger.

create type public.inventory_unit as enum ('pcs', 'bottle', 'set', 'roll');

create type public.inventory_movement_type as enum (
  'opening_balance',
  'receipt',
  'issue',
  'adjustment_in',
  'adjustment_out',
  'transfer_in',
  'transfer_out'
);

create type public.inventory_reference_type as enum (
  'opening_balance',
  'manual_adjustment',
  'stock_transfer',
  'purchase_receipt',
  'component_replacement',
  'toner_refill',
  'maintenance'
);

create table public.inventory_items (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  component_id uuid references public.components(id) on delete restrict,
  sku text not null,
  name text not null,
  category text,
  unit public.inventory_unit not null default 'pcs',
  minimum_stock numeric(20,4),
  notes text,
  is_active boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint inventory_items_id_account_key unique (id, account_id),
  constraint inventory_items_sku_not_blank check (btrim(sku) <> ''),
  constraint inventory_items_name_not_blank check (btrim(name) <> ''),
  constraint inventory_items_category_not_blank check (category is null or btrim(category) <> ''),
  constraint inventory_items_minimum_stock_nonnegative check (minimum_stock is null or minimum_stock >= 0),
  constraint inventory_items_quantity_scale check (minimum_stock is null or round(minimum_stock, 4) = minimum_stock),
  constraint inventory_items_archive_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index inventory_items_account_sku_normalized_key
  on public.inventory_items (account_id, lower(btrim(sku)));
create index inventory_items_account_active_idx
  on public.inventory_items (account_id, is_active, name);
create index inventory_items_component_idx
  on public.inventory_items (component_id) where component_id is not null;

create table public.inventory_locations (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  branch_id uuid,
  code text not null,
  name text not null,
  notes text,
  is_active boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint inventory_locations_id_account_key unique (id, account_id),
  constraint inventory_locations_branch_account_fkey foreign key (branch_id, account_id)
    references public.branches(id, account_id) on delete restrict,
  constraint inventory_locations_code_not_blank check (btrim(code) <> ''),
  constraint inventory_locations_name_not_blank check (btrim(name) <> ''),
  constraint inventory_locations_archive_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index inventory_locations_account_code_normalized_key
  on public.inventory_locations (account_id, lower(btrim(code)));
create index inventory_locations_account_active_idx
  on public.inventory_locations (account_id, is_active, name);
create index inventory_locations_branch_idx
  on public.inventory_locations (branch_id) where branch_id is not null;

create table public.inventory_movements (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  inventory_item_id uuid not null,
  location_id uuid not null,
  movement_type public.inventory_movement_type not null,
  quantity numeric(20,4) not null,
  unit_snapshot public.inventory_unit not null,
  occurred_at timestamptz not null,
  operational_person_id uuid,
  operational_person_name_snapshot text not null,
  reference_type public.inventory_reference_type not null,
  reference_id uuid,
  reason text,
  notes text,
  client_request_id uuid not null,
  transfer_id uuid,
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  created_by_name_snapshot text not null,
  created_at timestamptz not null default statement_timestamp(),
  constraint inventory_movements_item_account_fkey foreign key (inventory_item_id, account_id)
    references public.inventory_items(id, account_id) on delete restrict,
  constraint inventory_movements_location_account_fkey foreign key (location_id, account_id)
    references public.inventory_locations(id, account_id) on delete restrict,
  constraint inventory_movements_person_account_fkey foreign key (operational_person_id, account_id)
    references public.operational_people(id, account_id) on delete restrict,
  constraint inventory_movements_quantity_nonzero check (quantity <> 0),
  constraint inventory_movements_quantity_scale check (round(quantity, 4) = quantity),
  constraint inventory_movements_person_snapshot_not_blank check (btrim(operational_person_name_snapshot) <> ''),
  constraint inventory_movements_actor_snapshot_not_blank check (btrim(created_by_name_snapshot) <> ''),
  constraint inventory_movements_reason_not_blank check (reason is null or btrim(reason) <> ''),
  constraint inventory_movements_sign_matches_type check (
    (movement_type in ('opening_balance', 'receipt', 'adjustment_in', 'transfer_in') and quantity > 0)
    or (movement_type in ('issue', 'adjustment_out', 'transfer_out') and quantity < 0)
  ),
  constraint inventory_movements_reference_matches_type check (
    (movement_type = 'opening_balance' and reference_type = 'opening_balance')
    or (movement_type in ('adjustment_in', 'adjustment_out') and reference_type = 'manual_adjustment')
    or (movement_type in ('transfer_in', 'transfer_out') and reference_type = 'stock_transfer')
    or movement_type in ('receipt', 'issue')
  ),
  constraint inventory_movements_adjustment_reason_required check (
    movement_type not in ('adjustment_in', 'adjustment_out') or reason is not null
  ),
  constraint inventory_movements_transfer_consistent check (
    (movement_type in ('transfer_in', 'transfer_out') and transfer_id is not null)
    or (movement_type not in ('transfer_in', 'transfer_out') and transfer_id is null)
  )
);

create unique index inventory_movements_request_leg_key
  on public.inventory_movements (account_id, client_request_id, movement_type, location_id);
create unique index inventory_movements_one_opening_key
  on public.inventory_movements (account_id, inventory_item_id, location_id)
  where movement_type = 'opening_balance';
create index inventory_movements_item_location_time_idx
  on public.inventory_movements (account_id, inventory_item_id, location_id, occurred_at desc, created_at desc);
create index inventory_movements_transfer_idx
  on public.inventory_movements (transfer_id) where transfer_id is not null;

create or replace function public.set_inventory_master_audit_fields()
returns trigger
language plpgsql
set search_path = ''
as $$
declare actor_id uuid := (select auth.uid());
begin
  if tg_op = 'INSERT' then
    if not new.is_active then
      new.archived_at := coalesce(new.archived_at, statement_timestamp());
      new.archived_by := coalesce(actor_id, new.archived_by);
    end if;
    return new;
  end if;
  if tg_table_name = 'inventory_items' and old.unit <> new.unit and exists (
    select 1 from public.inventory_movements movement where movement.inventory_item_id = old.id
  ) then
    raise exception 'unit cannot change after inventory movements exist' using errcode = '23503';
  end if;
  new.updated_at := statement_timestamp();
  new.updated_by := coalesce(actor_id, new.updated_by, old.updated_by);
  if old.is_active and not new.is_active then
    new.archived_at := statement_timestamp();
    new.archived_by := coalesce(actor_id, new.archived_by);
  elsif not old.is_active and new.is_active then
    new.archived_at := null;
    new.archived_by := null;
  else
    new.archived_at := old.archived_at;
    new.archived_by := old.archived_by;
  end if;
  return new;
end;
$$;

create or replace function public.validate_inventory_item_component()
returns trigger
language plpgsql
set search_path = ''
as $$
declare component_account_id uuid; component_active boolean;
begin
  if new.component_id is null then return new; end if;
  select component.account_id, component.is_active into component_account_id, component_active
  from public.components component where component.id = new.component_id;
  if not found or not component_active then
    raise exception 'active component not found' using errcode = '23503';
  end if;
  if component_account_id is not null and component_account_id <> new.account_id then
    raise exception 'component belongs to another account' using errcode = '23503';
  end if;
  return new;
end;
$$;

create or replace function public.protect_inventory_movement_history()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception 'inventory movement history is immutable' using errcode = '42501';
end;
$$;

create trigger inventory_items_set_audit_fields before insert or update on public.inventory_items
for each row execute function public.set_inventory_master_audit_fields();
create trigger inventory_locations_set_audit_fields before insert or update on public.inventory_locations
for each row execute function public.set_inventory_master_audit_fields();
create trigger inventory_items_validate_component before insert or update of account_id, component_id on public.inventory_items
for each row execute function public.validate_inventory_item_component();
create trigger inventory_movements_immutable before update or delete on public.inventory_movements
for each row execute function public.protect_inventory_movement_history();

create view public.inventory_stock_balances
with (security_invoker = true)
as
select movement.account_id, movement.inventory_item_id, movement.location_id,
  sum(movement.quantity)::numeric(20,4) as quantity
from public.inventory_movements movement
group by movement.account_id, movement.inventory_item_id, movement.location_id;

create view public.inventory_item_totals
with (security_invoker = true)
as
select item.id as inventory_item_id, item.account_id,
  coalesce(sum(movement.quantity), 0)::numeric(20,4) as quantity
from public.inventory_items item
left join public.inventory_movements movement
  on movement.inventory_item_id = item.id and movement.account_id = item.account_id
group by item.id, item.account_id;

create view public.inventory_movement_history
with (security_invoker = true)
as
select movement.id as movement_id, movement.account_id, movement.inventory_item_id,
  item.sku, item.name as item_name, item.component_id, movement.location_id,
  location.code as location_code, location.name as location_name, location.branch_id,
  movement.movement_type, movement.quantity, movement.unit_snapshot, movement.occurred_at,
  movement.operational_person_id, movement.operational_person_name_snapshot,
  movement.reference_type, movement.reference_id, movement.reason, movement.notes,
  movement.client_request_id, movement.transfer_id, movement.created_by,
  movement.created_by_name_snapshot, movement.created_at
from public.inventory_movements movement
join public.inventory_items item on item.id = movement.inventory_item_id
join public.inventory_locations location on location.id = movement.location_id;

comment on table public.inventory_items is 'Account-owned inventory definitions; physical quantity is never stored here.';
comment on table public.inventory_movements is 'Immutable signed-quantity inventory ledger. Positive adds stock; negative removes stock.';
comment on view public.inventory_stock_balances is 'Authoritative per-item, per-location stock derived only from immutable movements.';
comment on view public.inventory_item_totals is 'Authoritative account-wide item totals, including zero-stock inventory items.';
comment on column public.inventory_movements.quantity is 'Signed numeric(20,4): positive increases stock and negative decreases stock.';

revoke all on function public.set_inventory_master_audit_fields(), public.validate_inventory_item_component(),
  public.protect_inventory_movement_history() from public, anon, authenticated, service_role;
