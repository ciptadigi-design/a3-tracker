begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.create_inventory_purchase(uuid,uuid,text,date,text,text,text,jsonb,uuid)'::regprocedure),'purchase RPC is SECURITY DEFINER with empty search_path');
select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid)'::regprocedure),'receiving RPC is SECURITY DEFINER with empty search_path');
select extensions.ok(not has_function_privilege('anon','public.create_inventory_purchase(uuid,uuid,text,date,text,text,text,jsonb,uuid)','EXECUTE') and not has_function_privilege('anon','public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid)','EXECUTE'),'anonymous cannot execute purchasing RPCs');
select extensions.ok(not has_table_privilege('authenticated','public.inventory_receipts','INSERT') and not has_table_privilege('authenticated','public.inventory_receipt_lines','INSERT') and not has_table_privilege('authenticated','public.inventory_movements','INSERT'),'authenticated clients cannot directly post receiving or ledger facts');
select extensions.is((select count(*)::int from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a where c.oid=any(array['public.inventory_purchases'::regclass,'public.inventory_purchase_lines'::regclass,'public.inventory_receipts'::regclass,'public.inventory_receipt_lines'::regclass]) and a.grantee=0),0,'PUBLIC has no procurement relation privileges');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('e0000000-0000-4000-8000-000000000001','authenticated','authenticated','m24c-owner@test.invalid','',now(),'{}','{"display_name":"Purchasing Owner"}',now(),now()),
('e0000000-0000-4000-8000-000000000002','authenticated','authenticated','m24c-admin@test.invalid','',now(),'{}','{"display_name":"Purchasing Admin"}',now(),now()),
('e0000000-0000-4000-8000-000000000003','authenticated','authenticated','m24c-tech@test.invalid','',now(),'{}','{"display_name":"Purchasing Tech"}',now(),now()),
('e0000000-0000-4000-8000-000000000004','authenticated','authenticated','m24c-operator@test.invalid','',now(),'{}','{"display_name":"Purchasing Operator"}',now(),now()),
('e0000000-0000-4000-8000-000000000005','authenticated','authenticated','m24c-suspended@test.invalid','',now(),'{}','{"display_name":"Purchasing Suspended"}',now(),now()),
('e0000000-0000-4000-8000-000000000006','authenticated','authenticated','m24c-other@test.invalid','',now(),'{}','{"display_name":"Purchasing Other"}',now(),now());

insert into public.accounts(id,code,name) values
('e1000000-0000-4000-8000-000000000001','M24C-A','M2.4C Account A'),
('e1000000-0000-4000-8000-000000000002','M24C-B','M2.4C Account B');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('e2000000-0000-4000-8000-000000000001','e1000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000001','owner','active',now()),
('e2000000-0000-4000-8000-000000000002','e1000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000002','admin','active',now()),
('e2000000-0000-4000-8000-000000000003','e1000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000003','technician','active',now()),
('e2000000-0000-4000-8000-000000000004','e1000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000004','operator','active',now()),
('e2000000-0000-4000-8000-000000000005','e1000000-0000-4000-8000-000000000001','e0000000-0000-4000-8000-000000000005','admin','suspended',now()),
('e2000000-0000-4000-8000-000000000006','e1000000-0000-4000-8000-000000000002','e0000000-0000-4000-8000-000000000006','owner','active',now());
insert into public.branches(id,account_id,code,name) values
('e3000000-0000-4000-8000-000000000001','e1000000-0000-4000-8000-000000000001','A','Branch A'),
('e3000000-0000-4000-8000-000000000002','e1000000-0000-4000-8000-000000000002','B','Branch B');
insert into public.operational_people(id,account_id,name,code) values
('e4000000-0000-4000-8000-000000000001','e1000000-0000-4000-8000-000000000001','Receiving PIC','RECV'),
('e4000000-0000-4000-8000-000000000002','e1000000-0000-4000-8000-000000000002','Other PIC','OTHER');
insert into public.inventory_locations(id,account_id,branch_id,code,name) values
('e5000000-0000-4000-8000-000000000001','e1000000-0000-4000-8000-000000000001','e3000000-0000-4000-8000-000000000001','WH','Warehouse'),
('e5000000-0000-4000-8000-000000000002','e1000000-0000-4000-8000-000000000001',null,'FLOOR','Machine Floor'),
('e5000000-0000-4000-8000-000000000003','e1000000-0000-4000-8000-000000000002','e3000000-0000-4000-8000-000000000002','OTHER','Other Warehouse');
insert into public.inventory_items(id,account_id,sku,name,unit) values
('e6000000-0000-4000-8000-000000000001','e1000000-0000-4000-8000-000000000001','TON-C','Toner Cyan','bottle'),
('e6000000-0000-4000-8000-000000000002','e1000000-0000-4000-8000-000000000001','DRUM-C','Drum Cyan','pcs'),
('e6000000-0000-4000-8000-000000000003','e1000000-0000-4000-8000-000000000001','ARCH','Archived Part','pcs'),
('e6000000-0000-4000-8000-000000000004','e1000000-0000-4000-8000-000000000002','OTHER','Other Item','pcs');
update public.inventory_items set is_active=false where id='e6000000-0000-4000-8000-000000000003';

set local role authenticated;
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000001',true);
select extensions.lives_ok($$insert into public.inventory_suppliers(id,account_id,supplier_code,name,contact_person,email) values
('e7000000-0000-4000-8000-000000000001','e1000000-0000-4000-8000-000000000001','SUP-01','PT Supplier Utama','Budi','buy@supplier.invalid'),
('e7000000-0000-4000-8000-000000000002','e1000000-0000-4000-8000-000000000001','TEMP','Temporary Supplier',null,null)$$,'owner creates suppliers');
select extensions.throws_ok($$insert into public.inventory_suppliers(account_id,supplier_code,name) values('e1000000-0000-4000-8000-000000000001',' sup-01 ','Duplicate')$$,'23505',null,'supplier code uniqueness is normalized per account');
select extensions.lives_ok($$update public.inventory_suppliers set phone='021-555' where id='e7000000-0000-4000-8000-000000000001'$$,'owner edits supplier');
select extensions.lives_ok($$update public.inventory_suppliers set is_active=false where id='e7000000-0000-4000-8000-000000000002'$$,'owner archives supplier');
select extensions.lives_ok($$update public.inventory_suppliers set is_active=true where id='e7000000-0000-4000-8000-000000000002'$$,'owner reactivates supplier');
select extensions.lives_ok($$delete from public.inventory_suppliers where id='e7000000-0000-4000-8000-000000000002'$$,'unreferenced supplier can be deleted');

select extensions.lives_ok($$select public.create_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-2026-0001','2026-08-27',
  'INV-100','IDR','Initial purchase',
  '[{"inventory_item_id":"e6000000-0000-4000-8000-000000000001","quantity":"10","unit_price":"2850000","notes":"Toner"},{"inventory_item_id":"e6000000-0000-4000-8000-000000000002","quantity":"2","unit_price":"1200000"}]'::jsonb,
  'e8000000-0000-4000-8000-000000000001')$$,'owner creates purchase atomically');
select extensions.is((select status::text from public.inventory_purchases where purchase_number='PUR-2026-0001'),'draft','new purchase is draft and receivable');
select extensions.is((select line_count from public.inventory_purchase_summary where purchase_number='PUR-2026-0001'),2,'purchase has both lines');
select extensions.is((select purchase_total from public.inventory_purchase_summary where purchase_number='PUR-2026-0001'),30900000::numeric,'database derives purchase total');
select extensions.is((select count(*)::int from public.inventory_movements where reference_type='purchase_receipt' and account_id='e1000000-0000-4000-8000-000000000001'),0,'purchase creation does not change stock');
select extensions.lives_ok($$select public.create_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-2026-0001','2026-08-27',
  'INV-100','IDR','Initial purchase',
  '[{"inventory_item_id":"e6000000-0000-4000-8000-000000000001","quantity":"10","unit_price":"2850000","notes":"Toner"},{"inventory_item_id":"e6000000-0000-4000-8000-000000000002","quantity":"2","unit_price":"1200000"}]'::jsonb,
  'e8000000-0000-4000-8000-000000000001')$$,'identical purchase retry is idempotent');
select extensions.is((select count(*)::int from public.inventory_purchases where client_request_id='e8000000-0000-4000-8000-000000000001'),1,'purchase retry creates no duplicate');
select extensions.throws_ok($$select public.create_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-2026-0001','2026-08-27',null,'IDR',null,
  '[{"inventory_item_id":"e6000000-0000-4000-8000-000000000001","quantity":"9","unit_price":"2850000"}]'::jsonb,
  'e8000000-0000-4000-8000-000000000001')$$,'23505',null,'changed purchase payload under same request ID is rejected');
select extensions.throws_ok($$select public.create_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-DUP-LINE','2026-08-27',null,'IDR',null,
  '[{"inventory_item_id":"e6000000-0000-4000-8000-000000000001","quantity":"1","unit_price":"1"},{"inventory_item_id":"e6000000-0000-4000-8000-000000000001","quantity":"1","unit_price":"1"}]'::jsonb,
  'e8000000-0000-4000-8000-000000000002')$$,'23505',null,'duplicate purchase line item is rejected');
select extensions.throws_ok($$select public.create_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-ARCH','2026-08-27',null,'IDR',null,
  '[{"inventory_item_id":"e6000000-0000-4000-8000-000000000003","quantity":"1","unit_price":"1"}]'::jsonb,
  'e8000000-0000-4000-8000-000000000003')$$,'P0002',null,'archived inventory item is rejected from purchase');
select extensions.throws_ok($$delete from public.inventory_suppliers where id='e7000000-0000-4000-8000-000000000001'$$,'23503',null,'referenced supplier cannot be deleted');
select extensions.lives_ok($$update public.inventory_suppliers set is_active=false where id='e7000000-0000-4000-8000-000000000001'$$,'referenced supplier can be archived');
select extensions.is((select supplier_name_snapshot from public.inventory_purchase_summary where purchase_number='PUR-2026-0001'),'PT Supplier Utama','supplier snapshot survives archive');
select extensions.lives_ok($$update public.inventory_suppliers set is_active=true where id='e7000000-0000-4000-8000-000000000001'$$,'supplier restored for remaining tests');

select extensions.lives_ok($$select public.receive_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),
  'e5000000-0000-4000-8000-000000000001','2026-08-27 12:00+07','e4000000-0000-4000-8000-000000000001','First partial',
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','6')),
  'e9000000-0000-4000-8000-000000000001')$$,'partial receipt succeeds atomically');
select extensions.is((select status::text from public.inventory_purchases where purchase_number='PUR-2026-0001'),'partially_received','partial receipt updates purchase status');
select extensions.is((select received_quantity from public.inventory_purchase_line_status where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),6::numeric,'partial received quantity derives from receipt lines');
select extensions.is((select remaining_quantity from public.inventory_purchase_line_status where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),4::numeric,'partial remaining quantity is correct');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='e6000000-0000-4000-8000-000000000001' and location_id='e5000000-0000-4000-8000-000000000001'),6::numeric,'receipt increases physical location balance');
select extensions.is((select quantity from public.inventory_item_totals where inventory_item_id='e6000000-0000-4000-8000-000000000001'),6::numeric,'receipt increases account item total');
select extensions.is((select count(*)::int from public.inventory_receipt_lines line join public.inventory_movements movement on movement.id=line.inventory_movement_id where line.quantity=movement.quantity and movement.movement_type='receipt' and movement.reference_type='purchase_receipt'),1,'receipt line links exactly one matching positive movement');
select extensions.is((select unit_price_snapshot from public.inventory_receipt_history where inventory_item_id='e6000000-0000-4000-8000-000000000001'),2850000::numeric,'receipt snapshots unit acquisition price');
select extensions.is((select acquisition_value from public.inventory_receipt_history where inventory_item_id='e6000000-0000-4000-8000-000000000001'),17100000::numeric,'database derives receipt acquisition value');
select extensions.is((select operational_person_name_snapshot from public.inventory_receipt_history where inventory_item_id='e6000000-0000-4000-8000-000000000001'),'Receiving PIC','receipt snapshots operational PIC separately');
select extensions.is((select created_by_name_snapshot from public.inventory_receipt_history where inventory_item_id='e6000000-0000-4000-8000-000000000001'),'Purchasing Owner','receipt snapshots authenticated actor separately');
select extensions.lives_ok($$select public.receive_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),
  'e5000000-0000-4000-8000-000000000001','2026-08-27 12:00+07','e4000000-0000-4000-8000-000000000001','First partial',
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','6')),
  'e9000000-0000-4000-8000-000000000001')$$,'identical receipt retry is idempotent');
select extensions.is((select count(*)::int from public.inventory_receipts where client_request_id='e9000000-0000-4000-8000-000000000001'),1,'receipt retry creates no duplicate receipt');
select extensions.is((select count(*)::int from public.inventory_movements where reference_type='purchase_receipt' and reference_id=(select id from public.inventory_receipts where client_request_id='e9000000-0000-4000-8000-000000000001')),1,'receipt retry creates no duplicate movement');
select extensions.throws_ok($$select public.receive_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),
  'e5000000-0000-4000-8000-000000000001','2026-08-27 12:00+07','e4000000-0000-4000-8000-000000000001','First partial',
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','5')),
  'e9000000-0000-4000-8000-000000000001')$$,'23505',null,'changed receipt payload under same request ID is rejected');

select extensions.throws_ok($$select public.receive_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),
  'e5000000-0000-4000-8000-000000000001','2026-08-27 13:00+07','e4000000-0000-4000-8000-000000000001',null,
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','5')),
  'e9000000-0000-4000-8000-000000000002')$$,'22003',null,'over-receiving is rejected');
select extensions.is((select count(*)::int from public.inventory_receipts where client_request_id='e9000000-0000-4000-8000-000000000002'),0,'failed over-receipt leaves no receipt');
select extensions.is((select count(*)::int from public.inventory_movements where reference_type='purchase_receipt' and occurred_at='2026-08-27 13:00+07'),0,'failed over-receipt leaves no stock movement');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='e6000000-0000-4000-8000-000000000001' and location_id='e5000000-0000-4000-8000-000000000001'),6::numeric,'failed over-receipt rolls stock back completely');
select extensions.throws_ok($$select public.receive_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),
  'e5000000-0000-4000-8000-000000000003','2026-08-27 13:00+07','e4000000-0000-4000-8000-000000000001',null,
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','1')),
  'e9000000-0000-4000-8000-000000000003')$$,'P0002',null,'cross-account receipt location is rejected');
select extensions.throws_ok($$select public.receive_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),
  'e5000000-0000-4000-8000-000000000001','2026-08-27 13:00+07','e4000000-0000-4000-8000-000000000002',null,
  jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','1')),
  'e9000000-0000-4000-8000-000000000004')$$,'P0002',null,'cross-account receipt PIC is rejected');

select extensions.lives_ok($$select public.receive_inventory_purchase(
  'e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),
  'e5000000-0000-4000-8000-000000000002','2026-08-27 14:00+07','e4000000-0000-4000-8000-000000000001','Final delivery',
  jsonb_build_array(
    jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000001' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','4'),
    jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where inventory_item_id='e6000000-0000-4000-8000-000000000002' and purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')),'quantity','2')),
  'e9000000-0000-4000-8000-000000000005')$$,'multi-line final receipt succeeds');
select extensions.is((select status::text from public.inventory_purchases where purchase_number='PUR-2026-0001'),'received','all completed lines produce received status');
select extensions.is((select fully_received_line_count from public.inventory_purchase_summary where purchase_number='PUR-2026-0001'),2,'all purchase lines are fully received');
select extensions.is((select quantity from public.inventory_item_totals where inventory_item_id='e6000000-0000-4000-8000-000000000001'),10::numeric,'partial and final receipts derive full item stock');
select extensions.is((select receipt_supplier_name from public.inventory_movement_history where reference_type='purchase_receipt' and inventory_item_id='e6000000-0000-4000-8000-000000000001' order by occurred_at limit 1),'PT Supplier Utama','movement history resolves receipt supplier without raw ID');
select extensions.is((select receipt_purchase_number from public.inventory_movement_history where reference_type='purchase_receipt' and inventory_item_id='e6000000-0000-4000-8000-000000000001' order by occurred_at limit 1),'PUR-2026-0001','movement history resolves purchase number');
select extensions.throws_ok($$select public.cancel_inventory_purchase('e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-2026-0001'),'No longer needed','ea000000-0000-4000-8000-000000000001')$$,'23514',null,'fully received purchase cannot be cancelled');

select extensions.lives_ok($$select public.create_inventory_purchase('e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-CANCEL','2026-08-27',null,'IDR',null,'[{"inventory_item_id":"e6000000-0000-4000-8000-000000000002","quantity":"3","unit_price":"100"}]'::jsonb,'e8000000-0000-4000-8000-000000000004')$$,'creates cancellable draft purchase');
select extensions.lives_ok($$select public.cancel_inventory_purchase('e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-CANCEL'),'Order cancelled','ea000000-0000-4000-8000-000000000002')$$,'owner cancels draft purchase');
select extensions.lives_ok($$select public.cancel_inventory_purchase('e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-CANCEL'),'Order cancelled','ea000000-0000-4000-8000-000000000002')$$,'identical cancellation retry is idempotent');
select extensions.is((select status::text from public.inventory_purchases where purchase_number='PUR-CANCEL'),'cancelled','cancelled status is durable');
select extensions.throws_ok($$select public.receive_inventory_purchase('e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-CANCEL'),'e5000000-0000-4000-8000-000000000001','2026-08-27 15:00+07','e4000000-0000-4000-8000-000000000001',null,jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-CANCEL')),'quantity','1')),'e9000000-0000-4000-8000-000000000006')$$,'23514',null,'cancelled purchase cannot be received');

select extensions.throws_ok($$update public.inventory_receipts set notes='tampered' where client_request_id='e9000000-0000-4000-8000-000000000001'$$,'42501',null,'receipt header is immutable');
select extensions.throws_ok($$delete from public.inventory_receipt_lines where receipt_id=(select id from public.inventory_receipts where client_request_id='e9000000-0000-4000-8000-000000000001')$$,'42501',null,'receipt lines are immutable');
select extensions.throws_ok($$update public.inventory_purchase_lines set unit_price=1 where purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-2026-0001')$$,'42501',null,'purchase lines are immutable');
select extensions.throws_ok($$update public.inventory_purchases set notes='tampered' where purchase_number='PUR-2026-0001'$$,'42501',null,'posted purchase facts are immutable');
select extensions.throws_ok($$delete from public.inventory_purchases where purchase_number='PUR-2026-0001'$$,'42501',null,'purchase history cannot be deleted');

reset role;
set local role authenticated;
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000002',true);
select extensions.lives_ok($$insert into public.inventory_suppliers(account_id,supplier_code,name) values('e1000000-0000-4000-8000-000000000001','ADMIN','Admin Supplier')$$,'admin manages supplier master');
select extensions.lives_ok($$select public.create_inventory_purchase('e1000000-0000-4000-8000-000000000001',(select id from public.inventory_suppliers where supplier_code='ADMIN'),'PUR-ADMIN','2026-08-27',null,'IDR',null,'[{"inventory_item_id":"e6000000-0000-4000-8000-000000000002","quantity":"1","unit_price":"500"}]'::jsonb,'e8000000-0000-4000-8000-000000000005')$$,'admin creates purchase');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000003',true);
select extensions.ok((select count(*) from public.inventory_purchase_summary)>=3,'technician reads purchasing evidence');
select extensions.throws_ok($$insert into public.inventory_suppliers(account_id,supplier_code,name) values('e1000000-0000-4000-8000-000000000001','TECH','Denied')$$,'42501',null,'technician cannot manage suppliers');
select extensions.throws_ok($$select public.create_inventory_purchase('e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-TECH','2026-08-27',null,'IDR',null,'[{"inventory_item_id":"e6000000-0000-4000-8000-000000000002","quantity":"1","unit_price":"1"}]'::jsonb,'e8000000-0000-4000-8000-000000000006')$$,'42501',null,'technician cannot create purchases');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000004',true);
select extensions.ok((select count(*) from public.inventory_receipt_history)>=3,'operator reads receiving evidence');
select extensions.throws_ok($$select public.receive_inventory_purchase('e1000000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='PUR-ADMIN'),'e5000000-0000-4000-8000-000000000001','2026-08-27 16:00+07','e4000000-0000-4000-8000-000000000001',null,jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where purchase_id=(select id from public.inventory_purchases where purchase_number='PUR-ADMIN')),'quantity','1')),'e9000000-0000-4000-8000-000000000007')$$,'42501',null,'operator cannot receive goods');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000005',true);
select extensions.is((select count(*)::int from public.inventory_suppliers),0,'suspended member cannot read suppliers');
select extensions.throws_ok($$select public.create_inventory_purchase('e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-SUSP','2026-08-27',null,'IDR',null,'[{"inventory_item_id":"e6000000-0000-4000-8000-000000000002","quantity":"1","unit_price":"1"}]'::jsonb,'e8000000-0000-4000-8000-000000000007')$$,'42501',null,'suspended member cannot create purchase');
reset role;

insert into public.inventory_suppliers(id,account_id,supplier_code,name) values('e7000000-0000-4000-8000-000000000003','e1000000-0000-4000-8000-000000000002','OTHER','Other Supplier');
set local role authenticated;
select set_config('request.jwt.claim.sub','e0000000-0000-4000-8000-000000000006',true);
select extensions.is((select count(*)::int from public.inventory_purchases),0,'other account cannot read purchases');
select extensions.throws_ok($$select public.create_inventory_purchase('e1000000-0000-4000-8000-000000000001','e7000000-0000-4000-8000-000000000001','PUR-CROSS','2026-08-27',null,'IDR',null,'[{"inventory_item_id":"e6000000-0000-4000-8000-000000000002","quantity":"1","unit_price":"1"}]'::jsonb,'e8000000-0000-4000-8000-000000000008')$$,'42501',null,'cross-account purchase mutation is denied');
reset role;

select extensions.ok(position('for update' in lower(pg_get_functiondef('public.receive_inventory_purchase(uuid,uuid,uuid,timestamptz,uuid,text,jsonb,uuid)'::regprocedure)))>0,'receiving RPC contains explicit row locks');
select extensions.ok((select bool_and(quantity>=0) from public.inventory_stock_balances where account_id='e1000000-0000-4000-8000-000000000001'),'receipt integration never creates negative stock');
select extensions.is((select unit_price from public.inventory_item_last_purchase_prices where inventory_item_id='e6000000-0000-4000-8000-000000000001'),2850000::numeric,'latest purchase price view exposes evidence without valuation');

select * from extensions.finish();
rollback;
