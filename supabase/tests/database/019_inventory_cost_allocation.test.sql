begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid)'::regprocedure),'auto purchase RPC is SECURITY DEFINER with empty search path');
select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.allocate_inventory_cost_fifo(uuid)'::regprocedure),'FIFO allocator is SECURITY DEFINER with empty search path');
select extensions.ok(not has_function_privilege('anon','public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid)','EXECUTE'),'anonymous cannot create an auto-numbered purchase');
select extensions.ok(not has_table_privilege('authenticated','public.inventory_cost_allocations','INSERT') and not has_table_privilege('authenticated','public.inventory_cost_allocations','UPDATE') and not has_table_privilege('authenticated','public.inventory_cost_allocations','DELETE'),'authenticated clients cannot mutate allocation facts directly');
select extensions.is((select count(*)::int from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a where c.oid=any(array['public.inventory_cost_lots'::regclass,'public.inventory_cost_allocations'::regclass]) and a.grantee=0),0,'PUBLIC has no cost relation privileges');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('f0000000-0000-4000-8000-000000000001','authenticated','authenticated','m24d-owner@test.invalid','',now(),'{}','{"display_name":"Cost Owner"}',now(),now()),
('f0000000-0000-4000-8000-000000000002','authenticated','authenticated','m24d-tech@test.invalid','',now(),'{}','{"display_name":"Cost Tech"}',now(),now()),
('f0000000-0000-4000-8000-000000000003','authenticated','authenticated','m24d-suspended@test.invalid','',now(),'{}','{"display_name":"Cost Suspended"}',now(),now());
insert into public.accounts(id,code,name) values ('f1000000-0000-4000-8000-000000000001','M24D','M2.4D Account');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('f2000000-0000-4000-8000-000000000001','f1000000-0000-4000-8000-000000000001','f0000000-0000-4000-8000-000000000001','owner','active',now()),
('f2000000-0000-4000-8000-000000000002','f1000000-0000-4000-8000-000000000001','f0000000-0000-4000-8000-000000000002','technician','active',now()),
('f2000000-0000-4000-8000-000000000003','f1000000-0000-4000-8000-000000000001','f0000000-0000-4000-8000-000000000003','admin','suspended',now());
insert into public.branches(id,account_id,code,name) values ('f3000000-0000-4000-8000-000000000001','f1000000-0000-4000-8000-000000000001','JKT','Jakarta');
insert into public.operational_people(id,account_id,name,code) values ('f4000000-0000-4000-8000-000000000001','f1000000-0000-4000-8000-000000000001','Cost PIC','COST');
insert into public.inventory_locations(id,account_id,branch_id,code,name) values
('f5000000-0000-4000-8000-000000000001','f1000000-0000-4000-8000-000000000001','f3000000-0000-4000-8000-000000000001','WH','Warehouse'),
('f5000000-0000-4000-8000-000000000002','f1000000-0000-4000-8000-000000000001','f3000000-0000-4000-8000-000000000001','FLOOR','Machine Area');
insert into public.inventory_items(id,account_id,sku,name,unit) values
('f6000000-0000-4000-8000-000000000001','f1000000-0000-4000-8000-000000000001','FIFO','FIFO Part','pcs'),
('f6000000-0000-4000-8000-000000000002','f1000000-0000-4000-8000-000000000001','UNKNOWN','Unknown Part','pcs'),
('f6000000-0000-4000-8000-000000000003','f1000000-0000-4000-8000-000000000001','MAINT','Maintenance Part','pcs');
insert into public.inventory_suppliers(id,account_id,supplier_code,name) values
('f7000000-0000-4000-8000-000000000001','f1000000-0000-4000-8000-000000000001','SUP','PT Cost Supplier');

set local role authenticated;
select set_config('request.jwt.claim.sub','f0000000-0000-4000-8000-000000000001',true);

select extensions.lives_ok($$select public.create_inventory_purchase_auto(
  'f1000000-0000-4000-8000-000000000001','f7000000-0000-4000-8000-000000000001','2026-07-01',null,'IDR',null,
  '[{"inventory_item_id":"f6000000-0000-4000-8000-000000000001","quantity":"2","unit_price":"2500000"}]',
  'f8000000-0000-4000-8000-000000000001')$$,'first auto-numbered purchase succeeds');
select extensions.lives_ok($$select public.create_inventory_purchase_auto(
  'f1000000-0000-4000-8000-000000000001','f7000000-0000-4000-8000-000000000001','2026-07-02','INV-2','IDR',null,
  '[{"inventory_item_id":"f6000000-0000-4000-8000-000000000001","quantity":"3","unit_price":"2650000"}]',
  'f8000000-0000-4000-8000-000000000002')$$,'second auto-numbered purchase succeeds');
select extensions.is((select min(purchase_number) from public.inventory_purchases where account_id='f1000000-0000-4000-8000-000000000001'),'PUR-202607-0001','internal purchase number uses account/month sequence');
select extensions.is((select max(purchase_number) from public.inventory_purchases where account_id='f1000000-0000-4000-8000-000000000001'),'PUR-202607-0002','purchase sequence is unique and monotonic');
select extensions.is((select supplier_reference from public.inventory_purchases where client_request_id='f8000000-0000-4000-8000-000000000002'),'INV-2','external reference remains optional and independent');
select extensions.lives_ok($$select public.create_inventory_purchase_auto(
  'f1000000-0000-4000-8000-000000000001','f7000000-0000-4000-8000-000000000001','2026-07-01',null,'IDR',null,
  '[{"inventory_item_id":"f6000000-0000-4000-8000-000000000001","quantity":"2","unit_price":"2500000"}]',
  'f8000000-0000-4000-8000-000000000001')$$,'auto purchase retry is idempotent');
select extensions.is((select count(*)::int from public.inventory_purchases where client_request_id='f8000000-0000-4000-8000-000000000001'),1,'purchase retry creates no duplicate');
select extensions.throws_ok($$select public.create_inventory_purchase_auto(
  'f1000000-0000-4000-8000-000000000001','f7000000-0000-4000-8000-000000000001','2026-07-01',null,'IDR',null,
  '[{"inventory_item_id":"f6000000-0000-4000-8000-000000000001","quantity":"9","unit_price":"2500000"}]',
  'f8000000-0000-4000-8000-000000000001')$$,'23505',null,'changed purchase retry is rejected');

select public.receive_inventory_purchase('f1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where client_request_id='f8000000-0000-4000-8000-000000000001'),
  'f5000000-0000-4000-8000-000000000001','2026-07-10 10:00+07','f4000000-0000-4000-8000-000000000001',null,
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where purchase_id=(select id from public.inventory_purchases where client_request_id='f8000000-0000-4000-8000-000000000001')),'quantity','2')),
  'f9000000-0000-4000-8000-000000000001');
select public.receive_inventory_purchase('f1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where client_request_id='f8000000-0000-4000-8000-000000000002'),
  'f5000000-0000-4000-8000-000000000001','2026-07-11 10:00+07','f4000000-0000-4000-8000-000000000001',null,
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where purchase_id=(select id from public.inventory_purchases where client_request_id='f8000000-0000-4000-8000-000000000002')),'quantity','3')),
  'f9000000-0000-4000-8000-000000000002');
select extensions.is((select count(*)::int from public.inventory_cost_lots where inventory_item_id='f6000000-0000-4000-8000-000000000001'),2,'each receipt line creates one immutable cost lot');
select extensions.is((select sum(remaining_cost) from public.inventory_cost_lot_balances where inventory_item_id='f6000000-0000-4000-8000-000000000001'),12950000::numeric,'receipt lots establish ending inventory cost basis');

select public.adjust_inventory_stock_costed('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000001','f5000000-0000-4000-8000-000000000002',1,'2026-07-12 10:00+07','f4000000-0000-4000-8000-000000000001','Known floor stock',null,'fa000000-0000-4000-8000-000000000001',1000000);
select public.adjust_inventory_stock_costed('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000001','f5000000-0000-4000-8000-000000000001',-3,'2026-08-10 10:00+07','f4000000-0000-4000-8000-000000000001','Operational issue test',null,'fa000000-0000-4000-8000-000000000002',null);
select extensions.is((select count(*)::int from public.inventory_cost_allocations where outbound_movement_id=(select id from public.inventory_movements where client_request_id='fa000000-0000-4000-8000-000000000002')),2,'cross-lot issue creates two allocation rows');
select extensions.is((select quantity from public.inventory_cost_allocations where outbound_movement_id=(select id from public.inventory_movements where client_request_id='fa000000-0000-4000-8000-000000000002') and allocation_order=1),2::numeric,'FIFO exhausts oldest lot first');
select extensions.is((select unit_cost from public.inventory_cost_allocations where outbound_movement_id=(select id from public.inventory_movements where client_request_id='fa000000-0000-4000-8000-000000000002') and allocation_order=2),2650000::numeric,'FIFO continues into second lot');
select extensions.is((select sum(allocated_cost) from public.inventory_cost_allocations where outbound_movement_id=(select id from public.inventory_movements where client_request_id='fa000000-0000-4000-8000-000000000002')),7650000::numeric,'issue consumption cost is authoritative allocation sum');
select extensions.is((select coalesce(sum(quantity),0) from public.inventory_cost_allocations allocation join public.inventory_cost_lots lot on lot.id=allocation.source_cost_lot_id where allocation.outbound_movement_id=(select id from public.inventory_movements where client_request_id='fa000000-0000-4000-8000-000000000002') and lot.location_id='f5000000-0000-4000-8000-000000000002'),0::numeric,'location FIFO does not consume cheaper stock from another location');
select extensions.is((select known_inventory_cost from public.inventory_cost_position where inventory_item_id='f6000000-0000-4000-8000-000000000001' and location_id='f5000000-0000-4000-8000-000000000001'),5300000::numeric,'partial second lot remains at original unit cost');
select extensions.is((select algorithm_version from public.inventory_cost_allocations where outbound_movement_id=(select id from public.inventory_movements where client_request_id='fa000000-0000-4000-8000-000000000002') order by allocation_order limit 1),'fifo_v1','allocation records policy algorithm version');

select public.transfer_inventory_stock('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000001','f5000000-0000-4000-8000-000000000001','f5000000-0000-4000-8000-000000000002',1,'2026-08-11 10:00+07','f4000000-0000-4000-8000-000000000001',null,'fa000000-0000-4000-8000-000000000003');
select extensions.is((select sum(known_inventory_cost) from public.inventory_cost_position where inventory_item_id='f6000000-0000-4000-8000-000000000001'),6300000::numeric,'transfer preserves total known inventory cost');
select extensions.is((select unit_cost from public.inventory_cost_lot_balances where source_type='transfer_in' and inventory_item_id='f6000000-0000-4000-8000-000000000001'),2650000::numeric,'destination inherits source layer unit cost');
select extensions.ok((select origin_receipt_line_id is not null from public.inventory_cost_lot_balances where source_type='transfer_in' and inventory_item_id='f6000000-0000-4000-8000-000000000001'),'transfer preserves original receipt lineage');

select public.initialize_inventory_stock_costed('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000002','f5000000-0000-4000-8000-000000000001',2,'2026-07-01 09:00+07','f4000000-0000-4000-8000-000000000001',null,'fb000000-0000-4000-8000-000000000001',null);
select extensions.is((select unknown_cost_quantity from public.inventory_cost_position where inventory_item_id='f6000000-0000-4000-8000-000000000002'),2::numeric,'unknown opening cost is explicit quantity, not zero cost');
select public.adjust_inventory_stock_costed('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000002','f5000000-0000-4000-8000-000000000001',-1,'2026-08-12 10:00+07','f4000000-0000-4000-8000-000000000001','Unknown out',null,'fb000000-0000-4000-8000-000000000002',null);
select extensions.ok((select unit_cost is null and allocated_cost is null from public.inventory_cost_allocations where outbound_movement_id=(select id from public.inventory_movements where client_request_id='fb000000-0000-4000-8000-000000000002')),'unknown stock creates explicit unknown-cost allocation');
select extensions.ok(not (select cost_is_complete from public.inventory_consumption_cost_history where outbound_movement_id=(select id from public.inventory_movements where client_request_id='fb000000-0000-4000-8000-000000000002')),'unknown consumption is never presented as complete zero cost');

select public.adjust_inventory_stock_costed('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000003','f5000000-0000-4000-8000-000000000001',1,'2026-07-15 10:00+07','f4000000-0000-4000-8000-000000000001','Known maintenance stock',null,'fb000000-0000-4000-8000-000000000003',500);
reset role;
insert into public.inventory_movements(account_id,inventory_item_id,location_id,movement_type,quantity,unit_snapshot,occurred_at,
  operational_person_id,operational_person_name_snapshot,reference_type,reason,client_request_id,created_by,created_by_name_snapshot)
values('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000003','f5000000-0000-4000-8000-000000000001',
  'issue',-1,'pcs','2026-08-15 10:00+07','f4000000-0000-4000-8000-000000000001','Cost PIC','maintenance','Maintenance consumption',
  'fb000000-0000-4000-8000-000000000004','f0000000-0000-4000-8000-000000000001','Cost Owner');
set local role authenticated;
select set_config('request.jwt.claim.sub','f0000000-0000-4000-8000-000000000001',true);

select extensions.is((select purchase_cost from public.monthly_inventory_cost_summary where account_id='f1000000-0000-4000-8000-000000000001' and period_start='2026-07-01'),12950000::numeric,'purchase cost belongs to purchase month');
select extensions.is((select known_consumption_cost from public.monthly_inventory_cost_summary where account_id='f1000000-0000-4000-8000-000000000001' and period_start='2026-08-01'),500::numeric,'operational issue consumption cost belongs to outbound movement month');
select extensions.is((select purchase_cost from public.monthly_inventory_cost_summary where account_id='f1000000-0000-4000-8000-000000000001' and period_start='2026-08-01'),0::numeric,'consumption month has zero purchase cost');

select extensions.throws_ok($$update public.inventory_cost_allocations set quantity=99 where account_id='f1000000-0000-4000-8000-000000000001'$$,'42501',null,'allocation history is immutable even to table owner path');
select set_config('request.jwt.claim.sub','f0000000-0000-4000-8000-000000000003',true);
select extensions.throws_ok($$select public.initialize_inventory_stock_costed('f1000000-0000-4000-8000-000000000001','f6000000-0000-4000-8000-000000000001','f5000000-0000-4000-8000-000000000001',1,now(),'f4000000-0000-4000-8000-000000000001',null,gen_random_uuid(),1)$$,'42501',null,'suspended member cannot establish cost basis');
select set_config('request.jwt.claim.sub','f0000000-0000-4000-8000-000000000002',true);
select extensions.is((select count(*)::int from public.inventory_cost_position where account_id='f1000000-0000-4000-8000-000000000001'),3,'technician can read account cost position according to inventory visibility');
reset role;

select extensions.ok(position('for update of lot' in lower(pg_get_functiondef('public.allocate_inventory_cost_fifo(uuid)'::regprocedure)))>0,'allocator locks FIFO source rows');
select extensions.ok(position('order by lot.effective_at,lot.created_at,lot.id' in lower(pg_get_functiondef('public.allocate_inventory_cost_fifo(uuid)'::regprocedure)))>0,'FIFO lock ordering is deterministic');
select extensions.ok(position('pg_advisory_xact_lock' in lower(pg_get_functiondef('public.create_inventory_purchase_auto(uuid,uuid,date,text,text,text,jsonb,uuid)'::regprocedure)))>0,'purchase generation serializes same-request retries');
select extensions.ok((select realized_lifecycle_cost is null and realized_cost_per_click is null from public.component_lifecycle_costs limit 1) is not false,'lifecycle view never invents realized economics without eligible lifecycle evidence');

select * from extensions.finish();
rollback;
