begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('f4000000-0000-4000-8000-000000000001','authenticated','authenticated','m24a-owner@test.invalid','',now(),'{}','{"display_name":"Inventory Owner"}',now(),now()),
('f4000000-0000-4000-8000-000000000002','authenticated','authenticated','m24a-admin@test.invalid','',now(),'{}','{"display_name":"Inventory Admin"}',now(),now()),
('f4000000-0000-4000-8000-000000000003','authenticated','authenticated','m24a-tech@test.invalid','',now(),'{}','{"display_name":"Inventory Tech"}',now(),now()),
('f4000000-0000-4000-8000-000000000004','authenticated','authenticated','m24a-operator@test.invalid','',now(),'{}','{"display_name":"Inventory Operator"}',now(),now()),
('f4000000-0000-4000-8000-000000000005','authenticated','authenticated','m24a-suspended@test.invalid','',now(),'{}','{"display_name":"Inventory Suspended"}',now(),now()),
('f4000000-0000-4000-8000-000000000006','authenticated','authenticated','m24a-owner-b@test.invalid','',now(),'{}','{"display_name":"Inventory Owner B"}',now(),now());

insert into public.accounts(id,code,name) values
('f4100000-0000-4000-8000-000000000001','M24A-A','M2.4A Account A'),
('f4100000-0000-4000-8000-000000000002','M24A-B','M2.4A Account B');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('f4200000-0000-4000-8000-000000000001','f4100000-0000-4000-8000-000000000001','f4000000-0000-4000-8000-000000000001','owner','active',now()),
('f4200000-0000-4000-8000-000000000002','f4100000-0000-4000-8000-000000000001','f4000000-0000-4000-8000-000000000002','admin','active',now()),
('f4200000-0000-4000-8000-000000000003','f4100000-0000-4000-8000-000000000001','f4000000-0000-4000-8000-000000000003','technician','active',now()),
('f4200000-0000-4000-8000-000000000004','f4100000-0000-4000-8000-000000000001','f4000000-0000-4000-8000-000000000004','operator','active',now()),
('f4200000-0000-4000-8000-000000000005','f4100000-0000-4000-8000-000000000001','f4000000-0000-4000-8000-000000000005','admin','suspended',now()),
('f4200000-0000-4000-8000-000000000006','f4100000-0000-4000-8000-000000000002','f4000000-0000-4000-8000-000000000006','owner','active',now());
insert into public.branches(id,account_id,code,name) values
('f4300000-0000-4000-8000-000000000001','f4100000-0000-4000-8000-000000000001','A','Branch A'),
('f4300000-0000-4000-8000-000000000002','f4100000-0000-4000-8000-000000000002','B','Branch B');
insert into public.operational_people(id,account_id,name,code) values
('f4400000-0000-4000-8000-000000000001','f4100000-0000-4000-8000-000000000001','Akmal Fauzan','AKMAL'),
('f4400000-0000-4000-8000-000000000002','f4100000-0000-4000-8000-000000000002','Other PIC','OTHER');
insert into public.inventory_locations(id,account_id,branch_id,code,name) values
('f4600000-0000-4000-8000-000000000003','f4100000-0000-4000-8000-000000000002','f4300000-0000-4000-8000-000000000002','OTHER','Other Account Location');

set local role anon;
select extensions.throws_ok('select * from public.inventory_items','42501',null,'anonymous cannot read inventory items');
select extensions.throws_ok($$select public.initialize_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',1,now(),'f4400000-0000-4000-8000-000000000001',null,'f4700000-0000-4000-8000-000000000001')$$,'42501',null,'anonymous cannot initialize stock');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','f4000000-0000-4000-8000-000000000001',true);
select extensions.lives_ok($$insert into public.inventory_locations(id,account_id,branch_id,code,name) values
('f4600000-0000-4000-8000-000000000001','f4100000-0000-4000-8000-000000000001','f4300000-0000-4000-8000-000000000001','WH','Warehouse'),
('f4600000-0000-4000-8000-000000000002','f4100000-0000-4000-8000-000000000001',null,'FLOOR','Machine Floor')$$,'owner creates multiple locations');
select extensions.lives_ok($$insert into public.inventory_items(id,account_id,component_id,sku,name,category,unit,minimum_stock) values
('f4500000-0000-4000-8000-000000000001','f4100000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000025','TON-C','Toner Cyan','Toner','bottle',2),
('f4500000-0000-4000-8000-000000000002','f4100000-0000-4000-8000-000000000001',null,'BLADE','Cleaning Blade','Maintenance','pcs',0)$$,'owner creates linked and generic items');
select extensions.throws_ok($$insert into public.inventory_items(account_id,sku,name) values ('f4100000-0000-4000-8000-000000000001',' ton-c ','Duplicate')$$,'23505',null,'SKU uniqueness is normalized within account');
select extensions.throws_ok($$insert into public.inventory_locations(account_id,code,name) values ('f4100000-0000-4000-8000-000000000001',' wh ','Duplicate')$$,'23505',null,'location code uniqueness is normalized within account');
select extensions.throws_ok($$insert into public.inventory_locations(account_id,branch_id,code,name) values ('f4100000-0000-4000-8000-000000000001','f4300000-0000-4000-8000-000000000002','CROSS','Cross branch')$$,'23503',null,'cross-account branch reference is rejected');
select extensions.throws_ok($$insert into public.inventory_items(account_id,component_id,sku,name) values ('f4100000-0000-4000-8000-000000000002','f4500000-0000-4000-8000-000000000001','BAD','Bad')$$,'23503',null,'invalid component reference is rejected');

select extensions.lives_ok($$select public.initialize_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',5,'2026-08-27 08:00+07','f4400000-0000-4000-8000-000000000001','Physical count','f4700000-0000-4000-8000-000000000001')$$,'owner initializes opening stock');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='f4500000-0000-4000-8000-000000000001' and location_id='f4600000-0000-4000-8000-000000000001'),5::numeric,'opening stock derives location balance');
select extensions.is((select operational_person_name_snapshot from public.inventory_movements where client_request_id='f4700000-0000-4000-8000-000000000001'),'Akmal Fauzan','movement snapshots operational PIC name');
select extensions.is((select created_by_name_snapshot from public.inventory_movements where client_request_id='f4700000-0000-4000-8000-000000000001'),'Inventory Owner','authenticated actor snapshot is separate');
select extensions.lives_ok($$select public.initialize_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',5,'2026-08-27 08:00+07','f4400000-0000-4000-8000-000000000001','Physical count','f4700000-0000-4000-8000-000000000001')$$,'identical opening retry is idempotent');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id='f4700000-0000-4000-8000-000000000001'),1,'opening retry creates one movement');
select extensions.throws_ok($$select public.initialize_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',6,'2026-08-27 08:00+07','f4400000-0000-4000-8000-000000000001',null,'f4700000-0000-4000-8000-000000000002')$$,'23505',null,'duplicate opening for same item and location is rejected');

select extensions.lives_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',2,'2026-08-27 09:00+07','f4400000-0000-4000-8000-000000000001','Found during count',null,'f4700000-0000-4000-8000-000000000003')$$,'positive adjustment succeeds');
select extensions.lives_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',-1,'2026-08-27 10:00+07','f4400000-0000-4000-8000-000000000001','Physical correction','One bottle missing','f4700000-0000-4000-8000-000000000004')$$,'negative adjustment succeeds');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='f4500000-0000-4000-8000-000000000001' and location_id='f4600000-0000-4000-8000-000000000001'),6::numeric,'adjustments derive correct location balance');
select extensions.throws_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',-7,now(),'f4400000-0000-4000-8000-000000000001','Too much',null,'f4700000-0000-4000-8000-000000000005')$$,'22003',null,'insufficient adjustment is rejected');
select extensions.throws_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001',1,now(),'f4400000-0000-4000-8000-000000000001','',null,'f4700000-0000-4000-8000-000000000006')$$,'22023',null,'adjustment reason is required');

select extensions.lives_ok($$select * from public.transfer_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',2,'2026-08-27 11:00+07','f4400000-0000-4000-8000-000000000001','Move to machine area','f4700000-0000-4000-8000-000000000007')$$,'transfer succeeds atomically');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='f4500000-0000-4000-8000-000000000001' and location_id='f4600000-0000-4000-8000-000000000001'),4::numeric,'transfer deducts source');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='f4500000-0000-4000-8000-000000000001' and location_id='f4600000-0000-4000-8000-000000000002'),2::numeric,'transfer adds destination');
select extensions.is((select quantity from public.inventory_item_totals where inventory_item_id='f4500000-0000-4000-8000-000000000001'),6::numeric,'transfer preserves total across locations');
select extensions.is((select count(distinct transfer_id)::int from public.inventory_movements where client_request_id='f4700000-0000-4000-8000-000000000007'),1,'paired transfer shares transaction reference');
select extensions.lives_ok($$select * from public.transfer_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',2,'2026-08-27 11:00+07','f4400000-0000-4000-8000-000000000001','Move to machine area','f4700000-0000-4000-8000-000000000007')$$,'transfer retry is idempotent');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id='f4700000-0000-4000-8000-000000000007'),2,'transfer retry keeps exactly two legs');
select extensions.throws_ok($$select * from public.transfer_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',5,now(),'f4400000-0000-4000-8000-000000000001',null,'f4700000-0000-4000-8000-000000000008')$$,'22003',null,'insufficient transfer is rejected');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id='f4700000-0000-4000-8000-000000000008'),0,'failed transfer leaves no partial leg');
select extensions.throws_ok($$select * from public.transfer_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',1,now(),'f4400000-0000-4000-8000-000000000002',null,'f4700000-0000-4000-8000-000000000009')$$,'P0002',null,'cross-account PIC is rejected');
select extensions.throws_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000003',1,now(),'f4400000-0000-4000-8000-000000000001','Wrong location',null,'f4700000-0000-4000-8000-000000000014')$$,'P0002',null,'cross-account location is rejected');
select extensions.throws_ok($$update public.inventory_movements set notes='tampered' where client_request_id='f4700000-0000-4000-8000-000000000004'$$,'42501',null,'posted movements cannot be updated');
select extensions.throws_ok($$delete from public.inventory_movements where client_request_id='f4700000-0000-4000-8000-000000000004'$$,'42501',null,'posted movements cannot be deleted');
select extensions.throws_ok($$delete from public.inventory_items where id='f4500000-0000-4000-8000-000000000001'$$,'23503',null,'referenced item cannot be deleted');
select extensions.throws_ok($$delete from public.inventory_locations where id='f4600000-0000-4000-8000-000000000001'$$,'23503',null,'referenced location cannot be deleted');
select extensions.lives_ok($$update public.inventory_items set is_active=false where id='f4500000-0000-4000-8000-000000000001'$$,'referenced item can be archived');
select extensions.lives_ok($$update public.inventory_locations set is_active=false where id='f4600000-0000-4000-8000-000000000001'$$,'referenced location can be archived');
select extensions.is((select count(*)::int from public.inventory_movement_history where inventory_item_id='f4500000-0000-4000-8000-000000000001'),5,'archiving preserves movement history');
select extensions.throws_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',1,now(),'f4400000-0000-4000-8000-000000000001','Denied archived',null,'f4700000-0000-4000-8000-000000000010')$$,'P0002',null,'archived item cannot receive movements');
select extensions.lives_ok($$delete from public.inventory_items where id='f4500000-0000-4000-8000-000000000002'$$,'unreferenced item can be deleted');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','f4000000-0000-4000-8000-000000000002',true);
select extensions.lives_ok($$insert into public.inventory_items(account_id,sku,name) values ('f4100000-0000-4000-8000-000000000001','ADMIN','Admin Item')$$,'admin can manage items');
select extensions.lives_ok($$insert into public.inventory_locations(account_id,code,name) values ('f4100000-0000-4000-8000-000000000001','ADMIN','Admin Location')$$,'admin can manage locations');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','f4000000-0000-4000-8000-000000000003',true);
select extensions.is((select count(*)::int from public.inventory_items),2,'technician can read inventory');
select extensions.throws_ok($$insert into public.inventory_items(account_id,sku,name) values ('f4100000-0000-4000-8000-000000000001','TECH','Denied')$$,'42501',null,'technician cannot manage items');
select extensions.throws_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',1,now(),'f4400000-0000-4000-8000-000000000001','Denied',null,'f4700000-0000-4000-8000-000000000011')$$,'42501',null,'technician cannot mutate stock');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','f4000000-0000-4000-8000-000000000004',true);
select extensions.is((select count(*)::int from public.inventory_movement_history),5,'operator can read movement history');
select extensions.throws_ok($$insert into public.inventory_locations(account_id,code,name) values ('f4100000-0000-4000-8000-000000000001','OP','Denied')$$,'42501',null,'operator cannot manage locations');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','f4000000-0000-4000-8000-000000000005',true);
select extensions.is((select count(*)::int from public.inventory_items),0,'suspended member cannot read inventory');
select extensions.throws_ok($$select public.initialize_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',1,now(),'f4400000-0000-4000-8000-000000000001',null,'f4700000-0000-4000-8000-000000000012')$$,'42501',null,'suspended member cannot mutate stock');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','f4000000-0000-4000-8000-000000000006',true);
select extensions.is((select count(*)::int from public.inventory_items),0,'other account cannot see inventory items');
select extensions.is((select count(*)::int from public.inventory_movements),0,'other account cannot see movements');
select extensions.throws_ok($$select public.adjust_inventory_stock('f4100000-0000-4000-8000-000000000001','f4500000-0000-4000-8000-000000000001','f4600000-0000-4000-8000-000000000002',1,now(),'f4400000-0000-4000-8000-000000000001','Cross account',null,'f4700000-0000-4000-8000-000000000013')$$,'42501',null,'cross-account mutation is rejected');
reset role;

select extensions.ok(position('for update' in lower(pg_get_functiondef('public.adjust_inventory_stock(uuid,uuid,uuid,numeric,timestamptz,uuid,text,text,uuid)'::regprocedure)))>0,'adjustment RPC contains a row lock');
select extensions.ok(position('for update' in lower(pg_get_functiondef('public.transfer_inventory_stock(uuid,uuid,uuid,uuid,numeric,timestamptz,uuid,text,uuid)'::regprocedure)))>0,'transfer RPC contains a row lock');
select extensions.is((select count(*)::int from public.inventory_items where sku='ADMIN'),1,'test account keeps isolated admin item');

select * from extensions.finish();
rollback;
