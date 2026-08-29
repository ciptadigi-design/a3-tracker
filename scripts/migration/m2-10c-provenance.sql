with candidate_purchases as (
  select purchase.*, supplier.name as supplier_name
  from public.inventory_purchases purchase
  join public.inventory_suppliers supplier on supplier.id = purchase.supplier_id
  -- The UI screenshots used five digits, while the stored purchase-number
  -- sequence uses four digits. The exact hosted rows are 0001 and 0002.
  where purchase.purchase_number in ('PUR-202608-0001', 'PUR-202608-0002')
), candidate_purchase_lines as (
  select line.*, item.name as item_name, item.component_id
  from public.inventory_purchase_lines line
  join candidate_purchases purchase on purchase.id = line.purchase_id
  join public.inventory_items item on item.id = line.inventory_item_id
), candidate_receipts as (
  select receipt.*
  from public.inventory_receipts receipt
  join candidate_purchases purchase on purchase.id = receipt.purchase_id
), candidate_receipt_lines as (
  select line.*
  from public.inventory_receipt_lines line
  join candidate_receipts receipt on receipt.id = line.receipt_id
), candidate_replacements as (
  select replacement.*
  from public.component_replacement_events replacement
  where replacement.id in (
    '392ffdb5-7ccf-4304-9d9c-db9727a73cfd',
    'd063cc89-ec0d-4164-8746-bf2618cdedb5'
  )
), candidate_allocations as (
  select allocation.*
  from public.inventory_cost_allocations allocation
  where allocation.outbound_movement_id in (
    select inventory_movement_id from candidate_replacements
  )
), candidate_lots as (
  select lot.*
  from public.inventory_cost_lots lot
  where lot.inbound_movement_id in (select inventory_movement_id from candidate_receipt_lines)
     or lot.source_receipt_line_id in (select id from candidate_receipt_lines)
     or lot.origin_receipt_line_id in (select id from candidate_receipt_lines)
     or lot.id in (select source_cost_lot_id from candidate_allocations)
), candidate_movements as (
  select movement.*
  from public.inventory_movements movement
  where movement.id in (select inventory_movement_id from candidate_receipt_lines)
     or movement.id in (select outbound_movement_id from candidate_allocations)
     or movement.id in (select inbound_movement_id from candidate_lots)
), candidate_lifecycles as (
  select lifecycle.*
  from public.machine_component_lifecycles lifecycle
  where lifecycle.id in (
    select previous_lifecycle_id from candidate_replacements
    union
    select new_lifecycle_id from candidate_replacements
  )
), affected_items as (
  select distinct inventory_item_id from candidate_purchase_lines
), related_item_movements as (
  select movement.*
  from public.inventory_movements movement
  where movement.inventory_item_id in (select inventory_item_id from affected_items)
)
select jsonb_build_object(
  'purchases', (select coalesce(jsonb_agg(to_jsonb(row_value) order by purchase_number), '[]') from candidate_purchases row_value),
  'purchase_lines', (select coalesce(jsonb_agg(to_jsonb(row_value) order by purchase_id, id), '[]') from candidate_purchase_lines row_value),
  'receipts', (select coalesce(jsonb_agg(to_jsonb(row_value) order by received_at, id), '[]') from candidate_receipts row_value),
  'receipt_lines', (select coalesce(jsonb_agg(to_jsonb(row_value) order by receipt_id, id), '[]') from candidate_receipt_lines row_value),
  'movements', (select coalesce(jsonb_agg(to_jsonb(row_value) order by occurred_at, id), '[]') from candidate_movements row_value),
  'related_item_movements', (select coalesce(jsonb_agg(to_jsonb(row_value) order by occurred_at, id), '[]') from related_item_movements row_value),
  'cost_lots', (select coalesce(jsonb_agg(to_jsonb(row_value) order by effective_at, id), '[]') from candidate_lots row_value),
  'cost_allocations', (select coalesce(jsonb_agg(to_jsonb(row_value) order by created_at, id), '[]') from candidate_allocations row_value),
  'replacements', (select coalesce(jsonb_agg(to_jsonb(row_value) order by replaced_at, id), '[]') from candidate_replacements row_value),
  'lifecycles', (select coalesce(jsonb_agg(to_jsonb(row_value) order by installed_at, id), '[]') from candidate_lifecycles row_value)
) as graph;
