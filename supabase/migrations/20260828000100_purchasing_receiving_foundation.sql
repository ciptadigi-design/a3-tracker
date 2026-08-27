-- A3 Tracker V2 - M2.4C purchasing, receiving, and immutable acquisition-cost evidence.

create type public.inventory_purchase_status as enum (
  'draft',
  'partially_received',
  'received',
  'cancelled'
);

create table public.inventory_suppliers (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  supplier_code text not null,
  name text not null,
  contact_person text,
  phone text,
  email text,
  address text,
  notes text,
  is_active boolean not null default true,
  created_at timestamptz not null default statement_timestamp(),
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid default auth.uid() references auth.users(id) on delete set null,
  archived_at timestamptz,
  archived_by uuid references auth.users(id) on delete set null,
  constraint inventory_suppliers_id_account_key unique (id, account_id),
  constraint inventory_suppliers_code_not_blank check (btrim(supplier_code) <> ''),
  constraint inventory_suppliers_name_not_blank check (btrim(name) <> ''),
  constraint inventory_suppliers_contact_not_blank check (contact_person is null or btrim(contact_person) <> ''),
  constraint inventory_suppliers_phone_not_blank check (phone is null or btrim(phone) <> ''),
  constraint inventory_suppliers_email_not_blank check (email is null or btrim(email) <> ''),
  constraint inventory_suppliers_address_not_blank check (address is null or btrim(address) <> ''),
  constraint inventory_suppliers_archive_consistent check (
    (is_active and archived_at is null and archived_by is null)
    or (not is_active and archived_at is not null)
  )
);

create unique index inventory_suppliers_account_code_normalized_key
  on public.inventory_suppliers (account_id, lower(btrim(supplier_code)));
create index inventory_suppliers_account_active_name_idx
  on public.inventory_suppliers (account_id, is_active, name);

create table public.inventory_purchases (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  supplier_id uuid not null,
  purchase_number text not null,
  purchase_date date not null,
  supplier_reference text,
  currency_code text not null default 'IDR',
  status public.inventory_purchase_status not null default 'draft',
  notes text,
  supplier_code_snapshot text not null,
  supplier_name_snapshot text not null,
  client_request_id uuid not null,
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  created_by_name_snapshot text not null,
  created_at timestamptz not null default statement_timestamp(),
  updated_at timestamptz not null default statement_timestamp(),
  updated_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  cancelled_at timestamptz,
  cancelled_by uuid references auth.users(id) on delete restrict,
  cancellation_reason text,
  cancellation_request_id uuid,
  constraint inventory_purchases_id_account_key unique (id, account_id),
  constraint inventory_purchases_supplier_account_fkey foreign key (supplier_id, account_id)
    references public.inventory_suppliers(id, account_id) on delete restrict,
  constraint inventory_purchases_number_not_blank check (btrim(purchase_number) <> ''),
  constraint inventory_purchases_supplier_reference_not_blank check (supplier_reference is null or btrim(supplier_reference) <> ''),
  constraint inventory_purchases_currency_idr check (currency_code = 'IDR'),
  constraint inventory_purchases_supplier_code_snapshot_not_blank check (btrim(supplier_code_snapshot) <> ''),
  constraint inventory_purchases_supplier_name_snapshot_not_blank check (btrim(supplier_name_snapshot) <> ''),
  constraint inventory_purchases_actor_snapshot_not_blank check (btrim(created_by_name_snapshot) <> ''),
  constraint inventory_purchases_cancellation_consistent check (
    (status = 'cancelled' and cancelled_at is not null and cancelled_by is not null
      and nullif(btrim(cancellation_reason), '') is not null and cancellation_request_id is not null)
    or (status <> 'cancelled' and cancelled_at is null and cancelled_by is null
      and cancellation_reason is null and cancellation_request_id is null)
  )
);

create unique index inventory_purchases_account_number_normalized_key
  on public.inventory_purchases (account_id, lower(btrim(purchase_number)));
create unique index inventory_purchases_account_request_key
  on public.inventory_purchases (account_id, client_request_id);
create unique index inventory_purchases_account_cancellation_request_key
  on public.inventory_purchases (account_id, cancellation_request_id)
  where cancellation_request_id is not null;
create index inventory_purchases_account_date_idx
  on public.inventory_purchases (account_id, purchase_date desc, created_at desc);
create index inventory_purchases_supplier_idx
  on public.inventory_purchases (supplier_id, purchase_date desc);

create table public.inventory_purchase_lines (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  purchase_id uuid not null,
  inventory_item_id uuid not null,
  ordered_quantity numeric(20,4) not null,
  unit_price numeric(20,2) not null,
  line_total numeric(30,2) generated always as ((ordered_quantity * unit_price)::numeric(30,2)) stored,
  item_sku_snapshot text not null,
  item_name_snapshot text not null,
  unit_snapshot public.inventory_unit not null,
  notes text,
  created_at timestamptz not null default statement_timestamp(),
  constraint inventory_purchase_lines_id_account_key unique (id, account_id),
  constraint inventory_purchase_lines_purchase_account_fkey foreign key (purchase_id, account_id)
    references public.inventory_purchases(id, account_id) on delete restrict,
  constraint inventory_purchase_lines_item_account_fkey foreign key (inventory_item_id, account_id)
    references public.inventory_items(id, account_id) on delete restrict,
  constraint inventory_purchase_lines_purchase_item_key unique (purchase_id, inventory_item_id),
  constraint inventory_purchase_lines_quantity_positive check (ordered_quantity > 0),
  constraint inventory_purchase_lines_quantity_scale check (round(ordered_quantity, 4) = ordered_quantity),
  constraint inventory_purchase_lines_unit_price_nonnegative check (unit_price >= 0),
  constraint inventory_purchase_lines_unit_price_scale check (round(unit_price, 2) = unit_price),
  constraint inventory_purchase_lines_item_sku_snapshot_not_blank check (btrim(item_sku_snapshot) <> ''),
  constraint inventory_purchase_lines_item_name_snapshot_not_blank check (btrim(item_name_snapshot) <> '')
);

create index inventory_purchase_lines_purchase_idx on public.inventory_purchase_lines (purchase_id, id);
create index inventory_purchase_lines_item_idx on public.inventory_purchase_lines (inventory_item_id, created_at desc);

create table public.inventory_receipts (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  purchase_id uuid not null,
  supplier_id uuid not null,
  location_id uuid not null,
  receipt_number text not null,
  received_at timestamptz not null,
  operational_person_id uuid not null,
  operational_person_name_snapshot text not null,
  purchase_number_snapshot text not null,
  supplier_code_snapshot text not null,
  supplier_name_snapshot text not null,
  currency_code text not null,
  notes text,
  client_request_id uuid not null,
  created_by uuid not null default auth.uid() references auth.users(id) on delete restrict,
  created_by_name_snapshot text not null,
  created_at timestamptz not null default statement_timestamp(),
  constraint inventory_receipts_id_account_key unique (id, account_id),
  constraint inventory_receipts_purchase_account_fkey foreign key (purchase_id, account_id)
    references public.inventory_purchases(id, account_id) on delete restrict,
  constraint inventory_receipts_supplier_account_fkey foreign key (supplier_id, account_id)
    references public.inventory_suppliers(id, account_id) on delete restrict,
  constraint inventory_receipts_location_account_fkey foreign key (location_id, account_id)
    references public.inventory_locations(id, account_id) on delete restrict,
  constraint inventory_receipts_person_account_fkey foreign key (operational_person_id, account_id)
    references public.operational_people(id, account_id) on delete restrict,
  constraint inventory_receipts_number_not_blank check (btrim(receipt_number) <> ''),
  constraint inventory_receipts_pic_snapshot_not_blank check (btrim(operational_person_name_snapshot) <> ''),
  constraint inventory_receipts_purchase_snapshot_not_blank check (btrim(purchase_number_snapshot) <> ''),
  constraint inventory_receipts_supplier_code_snapshot_not_blank check (btrim(supplier_code_snapshot) <> ''),
  constraint inventory_receipts_supplier_name_snapshot_not_blank check (btrim(supplier_name_snapshot) <> ''),
  constraint inventory_receipts_currency_idr check (currency_code = 'IDR'),
  constraint inventory_receipts_actor_snapshot_not_blank check (btrim(created_by_name_snapshot) <> '')
);

create unique index inventory_receipts_account_number_normalized_key
  on public.inventory_receipts (account_id, lower(btrim(receipt_number)));
create unique index inventory_receipts_account_request_key
  on public.inventory_receipts (account_id, client_request_id);
create index inventory_receipts_purchase_time_idx
  on public.inventory_receipts (purchase_id, received_at desc, created_at desc);

create table public.inventory_receipt_lines (
  id uuid primary key default gen_random_uuid(),
  account_id uuid not null references public.accounts(id) on delete restrict,
  receipt_id uuid not null,
  purchase_line_id uuid not null,
  inventory_item_id uuid not null,
  inventory_movement_id uuid not null,
  quantity numeric(20,4) not null,
  unit_price_snapshot numeric(20,2) not null,
  acquisition_value numeric(30,2) generated always as ((quantity * unit_price_snapshot)::numeric(30,2)) stored,
  item_sku_snapshot text not null,
  item_name_snapshot text not null,
  unit_snapshot public.inventory_unit not null,
  created_at timestamptz not null default statement_timestamp(),
  constraint inventory_receipt_lines_id_account_key unique (id, account_id),
  constraint inventory_receipt_lines_receipt_account_fkey foreign key (receipt_id, account_id)
    references public.inventory_receipts(id, account_id) on delete restrict,
  constraint inventory_receipt_lines_purchase_line_account_fkey foreign key (purchase_line_id, account_id)
    references public.inventory_purchase_lines(id, account_id) on delete restrict,
  constraint inventory_receipt_lines_item_account_fkey foreign key (inventory_item_id, account_id)
    references public.inventory_items(id, account_id) on delete restrict,
  constraint inventory_receipt_lines_movement_account_fkey foreign key (inventory_movement_id, account_id)
    references public.inventory_movements(id, account_id) on delete restrict,
  constraint inventory_receipt_lines_receipt_purchase_line_key unique (receipt_id, purchase_line_id),
  constraint inventory_receipt_lines_movement_key unique (inventory_movement_id),
  constraint inventory_receipt_lines_quantity_positive check (quantity > 0),
  constraint inventory_receipt_lines_quantity_scale check (round(quantity, 4) = quantity),
  constraint inventory_receipt_lines_unit_price_nonnegative check (unit_price_snapshot >= 0),
  constraint inventory_receipt_lines_item_sku_snapshot_not_blank check (btrim(item_sku_snapshot) <> ''),
  constraint inventory_receipt_lines_item_name_snapshot_not_blank check (btrim(item_name_snapshot) <> '')
);

create index inventory_receipt_lines_purchase_line_idx on public.inventory_receipt_lines (purchase_line_id, created_at desc);
create index inventory_receipt_lines_item_idx on public.inventory_receipt_lines (inventory_item_id, created_at desc);

create or replace function public.set_inventory_supplier_audit_fields()
returns trigger language plpgsql set search_path = '' as $$
declare actor_id uuid := (select auth.uid());
begin
  if tg_op = 'INSERT' then
    if not new.is_active then
      new.archived_at := coalesce(new.archived_at, statement_timestamp());
      new.archived_by := coalesce(actor_id, new.archived_by);
    end if;
    return new;
  end if;
  new.updated_at := statement_timestamp();
  new.updated_by := coalesce(actor_id, new.updated_by, old.updated_by);
  if old.is_active and not new.is_active then
    new.archived_at := statement_timestamp(); new.archived_by := coalesce(actor_id, new.archived_by);
  elsif not old.is_active and new.is_active then
    new.archived_at := null; new.archived_by := null;
  else
    new.archived_at := old.archived_at; new.archived_by := old.archived_by;
  end if;
  return new;
end;
$$;

create or replace function public.protect_inventory_procurement_history()
returns trigger language plpgsql set search_path = '' as $$
begin
  raise exception 'posted purchasing and receiving history is immutable' using errcode = '42501';
end;
$$;

create or replace function public.protect_inventory_item_purchase_uom()
returns trigger language plpgsql set search_path = '' as $$
begin
  if old.unit is distinct from new.unit and exists (
    select 1 from public.inventory_purchase_lines line where line.inventory_item_id=old.id
  ) then
    raise exception 'unit cannot change after purchase evidence exists' using errcode='23503';
  end if;
  return new;
end;
$$;

create or replace function public.validate_inventory_receipt_line()
returns trigger language plpgsql set search_path = '' as $$
declare
  receipt_record public.inventory_receipts%rowtype;
  purchase_line_record public.inventory_purchase_lines%rowtype;
  movement_record public.inventory_movements%rowtype;
begin
  select * into receipt_record from public.inventory_receipts where id = new.receipt_id;
  select * into purchase_line_record from public.inventory_purchase_lines where id = new.purchase_line_id;
  select * into movement_record from public.inventory_movements where id = new.inventory_movement_id;
  if receipt_record.id is null or purchase_line_record.id is null or movement_record.id is null
    or purchase_line_record.purchase_id <> receipt_record.purchase_id
    or purchase_line_record.inventory_item_id <> new.inventory_item_id
    or purchase_line_record.unit_price <> new.unit_price_snapshot
    or purchase_line_record.unit_snapshot <> new.unit_snapshot
    or purchase_line_record.item_sku_snapshot <> new.item_sku_snapshot
    or purchase_line_record.item_name_snapshot <> new.item_name_snapshot
    or movement_record.account_id <> new.account_id
    or movement_record.inventory_item_id <> new.inventory_item_id
    or movement_record.location_id <> receipt_record.location_id
    or movement_record.movement_type <> 'receipt'
    or movement_record.reference_type <> 'purchase_receipt'
    or movement_record.reference_id <> receipt_record.id
    or movement_record.quantity <> new.quantity
    or movement_record.unit_snapshot <> new.unit_snapshot
    or movement_record.occurred_at <> receipt_record.received_at
    or movement_record.operational_person_id <> receipt_record.operational_person_id
    or movement_record.operational_person_name_snapshot <> receipt_record.operational_person_name_snapshot then
    raise exception 'receipt line does not match purchase, receipt, and inventory movement facts' using errcode = '23514';
  end if;
  return new;
end;
$$;

create trigger inventory_suppliers_set_audit_fields
before insert or update on public.inventory_suppliers
for each row execute function public.set_inventory_supplier_audit_fields();
create trigger inventory_purchase_lines_immutable
before update or delete on public.inventory_purchase_lines
for each row execute function public.protect_inventory_procurement_history();
create trigger inventory_items_protect_purchase_uom
before update of unit on public.inventory_items
for each row execute function public.protect_inventory_item_purchase_uom();
create trigger inventory_receipts_immutable
before update or delete on public.inventory_receipts
for each row execute function public.protect_inventory_procurement_history();
create trigger inventory_receipt_lines_validate
before insert on public.inventory_receipt_lines
for each row execute function public.validate_inventory_receipt_line();
create trigger inventory_receipt_lines_immutable
before update or delete on public.inventory_receipt_lines
for each row execute function public.protect_inventory_procurement_history();

create view public.inventory_purchase_line_status
with (security_invoker = true) as
select line.id as purchase_line_id,line.account_id,line.purchase_id,line.inventory_item_id,
  line.ordered_quantity,coalesce(sum(receipt_line.quantity),0)::numeric(20,4) as received_quantity,
  (line.ordered_quantity-coalesce(sum(receipt_line.quantity),0))::numeric(20,4) as remaining_quantity,
  line.unit_price,line.line_total,line.item_sku_snapshot,line.item_name_snapshot,line.unit_snapshot,line.notes,
  case when line.ordered_quantity > 0 then round(coalesce(sum(receipt_line.quantity),0)/line.ordered_quantity*100,2) else 0 end as progress_percent
from public.inventory_purchase_lines line
left join public.inventory_receipt_lines receipt_line on receipt_line.purchase_line_id=line.id
group by line.id;

create view public.inventory_purchase_summary
with (security_invoker = true) as
select purchase.id as purchase_id,purchase.account_id,purchase.supplier_id,purchase.purchase_number,
  purchase.purchase_date,purchase.supplier_reference,purchase.currency_code,purchase.status,purchase.notes,
  purchase.supplier_code_snapshot,purchase.supplier_name_snapshot,purchase.client_request_id,
  purchase.created_by,purchase.created_by_name_snapshot,purchase.created_at,purchase.updated_at,
  purchase.cancelled_at,purchase.cancelled_by,purchase.cancellation_reason,
  count(line.purchase_line_id)::integer as line_count,
  coalesce(sum(line.line_total),0)::numeric(30,2) as purchase_total,
  coalesce(round(avg(line.progress_percent),2),0)::numeric as receiving_progress_percent,
  count(*) filter (where line.remaining_quantity=0)::integer as fully_received_line_count
from public.inventory_purchases purchase
left join public.inventory_purchase_line_status line on line.purchase_id=purchase.id
group by purchase.id;

create view public.inventory_receipt_history
with (security_invoker = true) as
select receipt.id as receipt_id,receipt.account_id,receipt.receipt_number,receipt.purchase_id,
  receipt.purchase_number_snapshot,receipt.supplier_id,receipt.supplier_code_snapshot,receipt.supplier_name_snapshot,
  receipt.location_id,location.code as location_code,location.name as location_name,receipt.received_at,
  receipt.operational_person_id,receipt.operational_person_name_snapshot,receipt.currency_code,receipt.notes,
  receipt.client_request_id,receipt.created_by,receipt.created_by_name_snapshot,receipt.created_at,
  line.id as receipt_line_id,line.purchase_line_id,line.inventory_item_id,line.inventory_movement_id,
  line.quantity,line.unit_price_snapshot,line.acquisition_value,line.item_sku_snapshot,line.item_name_snapshot,line.unit_snapshot
from public.inventory_receipts receipt
join public.inventory_receipt_lines line on line.receipt_id=receipt.id
join public.inventory_locations location on location.id=receipt.location_id;

create view public.inventory_purchase_cost_history
with (security_invoker = true) as
select line.account_id,line.inventory_item_id,line.purchase_id,purchase.purchase_number,purchase.purchase_date,
  purchase.supplier_id,purchase.supplier_code_snapshot,purchase.supplier_name_snapshot,purchase.currency_code,
  line.id as purchase_line_id,line.ordered_quantity,line.unit_price,line.line_total,line.unit_snapshot,
  status.received_quantity,status.remaining_quantity,receipt_line.receipt_id,receipt.receipt_number,
  receipt.received_at,receipt.location_id,location.name as location_name,receipt_line.quantity as receipt_quantity,
  receipt_line.unit_price_snapshot as receipt_unit_price,receipt_line.acquisition_value
from public.inventory_purchase_lines line
join public.inventory_purchases purchase on purchase.id=line.purchase_id
join public.inventory_purchase_line_status status on status.purchase_line_id=line.id
left join public.inventory_receipt_lines receipt_line on receipt_line.purchase_line_id=line.id
left join public.inventory_receipts receipt on receipt.id=receipt_line.receipt_id
left join public.inventory_locations location on location.id=receipt.location_id;

create view public.inventory_item_last_purchase_prices
with (security_invoker = true) as
select distinct on (line.account_id,line.inventory_item_id)
  line.account_id,line.inventory_item_id,purchase.purchase_id,purchase.purchase_number,purchase.purchase_date,
  purchase.supplier_id,purchase.supplier_name_snapshot,purchase.currency_code,line.unit_price,line.unit_snapshot
from public.inventory_purchase_line_status line
join public.inventory_purchase_summary purchase on purchase.purchase_id=line.purchase_id
where purchase.status <> 'cancelled'
order by line.account_id,line.inventory_item_id,purchase.purchase_date desc,purchase.created_at desc,line.purchase_line_id desc;

comment on table public.inventory_purchases is 'Commercial acquisition evidence. Creating a purchase never changes inventory stock.';
comment on table public.inventory_receipts is 'Immutable physical receiving headers posted only through receive_inventory_purchase.';
comment on table public.inventory_receipt_lines is 'Immutable receipt-cost snapshots linked one-to-one with positive inventory receipt movements.';
comment on column public.inventory_purchase_lines.unit_price is 'IDR acquisition-price evidence; not an inventory valuation or machine cost.';
comment on column public.inventory_receipt_lines.unit_price_snapshot is 'Immutable acquisition-price snapshot from the purchase line at receiving time.';

revoke all on function public.set_inventory_supplier_audit_fields(),
  public.protect_inventory_procurement_history(),public.protect_inventory_item_purchase_uom(),
  public.validate_inventory_receipt_line()
  from public,anon,authenticated,service_role;
