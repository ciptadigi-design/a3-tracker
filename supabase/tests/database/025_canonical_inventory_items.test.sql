begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[]
  from pg_proc where oid='public.sync_canonical_inventory_items(uuid)'::regprocedure),
  'canonical sync RPC is SECURITY DEFINER with empty search path');
select extensions.ok(not has_function_privilege('authenticated','public.provision_canonical_inventory_items_for_account(uuid)','EXECUTE'),
  'internal canonical provisioner is not directly executable by authenticated users');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('d5000000-0000-4000-8000-000000000001','authenticated','authenticated','canonical-owner@test.invalid','',now(),'{}','{"display_name":"Canonical Owner"}',now(),now()),
('d5000000-0000-4000-8000-000000000002','authenticated','authenticated','canonical-tech@test.invalid','',now(),'{}','{"display_name":"Canonical Tech"}',now(),now()),
('d5000000-0000-4000-8000-000000000003','authenticated','authenticated','canonical-other@test.invalid','',now(),'{}','{"display_name":"Canonical Other"}',now(),now());

insert into public.accounts(id,code,name) values
('d5100000-0000-4000-8000-000000000001','CANA','Canonical Account A'),
('d5100000-0000-4000-8000-000000000002','CANB','Canonical Account B');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('d5200000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000001','owner','active',now()),
('d5200000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000002','technician','active',now()),
('d5200000-0000-4000-8000-000000000003','d5100000-0000-4000-8000-000000000002','d5000000-0000-4000-8000-000000000003','owner','active',now());
insert into public.branches(id,account_id,code,name) values
('d5300000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','A','Canonical Branch A');
insert into public.manufacturers(id,code,name) values ('d5400000-0000-4000-8000-000000000001','CANONICAL','Canonical Manufacturer');
insert into public.machine_models(id,manufacturer_id,model_code,name,machine_category,color_capability) values
('d5500000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','CANONICAL-MODEL','Canonical Model','digital_a3','color');

insert into public.components(id,account_id,code,name,category) values
('d5600000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','CAN-ONE','Canonical One','Test'),
('d5600000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000001','CAN-TWO','Canonical Two','Test'),
('d5600000-0000-4000-8000-000000000003','d5100000-0000-4000-8000-000000000001','CAN-THREE','Canonical Three','Test'),
('d5600000-0000-4000-8000-000000000099','d5100000-0000-4000-8000-000000000002','OTHER-COMP','Other Component','Test');

insert into public.inventory_items(id,account_id,component_id,sku,name,unit) values
('d5700000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000002','EXISTING','Existing Linked Item','bottle'),
('d5700000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000003','VARIANT','Variant With SKU','pcs'),
('d5700000-0000-4000-8000-000000000003','d5100000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000003',null,'Variant Without SKU','pcs');

insert into public.machine_model_components(id,account_id,machine_model_id,component_id,slot_code,tracking_method) values
('d5800000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000001','ONE','counter_based'),
('d5800000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000002','TWO','counter_based'),
('d5800000-0000-4000-8000-000000000003','d5100000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000003','THREE','counter_based');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('d5900000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000001','CAN-01','Canonical Machine');

select extensions.is((select count(*)::int from public.inventory_items where account_id='d5100000-0000-4000-8000-000000000001' and is_canonical),3,'active machine Components receive exactly one canonical item each');
select extensions.is((select is_canonical from public.inventory_items where id='d5700000-0000-4000-8000-000000000001'),true,'sole existing linked item is safely adopted as canonical');
select extensions.is((select count(*)::int from public.inventory_items where component_id='d5600000-0000-4000-8000-000000000003'),3,'multiple existing variants are preserved and receive a separate explicit canonical item');
select extensions.is((select sku from public.inventory_items where component_id='d5600000-0000-4000-8000-000000000001' and is_canonical),null,'provisioned canonical SKU is NULL');
select extensions.is((select count(*)::int from public.inventory_items where account_id='d5100000-0000-4000-8000-000000000001' and sku is null),3,'multiple NULL SKUs are valid across Components and variants');
select extensions.is((select count(*)::int from public.inventory_movements where account_id='d5100000-0000-4000-8000-000000000001'),0,'canonical provisioning creates no inventory movements or Opening Balance');
select extensions.is((select count(*)::int from public.inventory_cost_lots where account_id='d5100000-0000-4000-8000-000000000001'),0,'canonical provisioning creates no cost lots');
select extensions.is((select count(*)::int from public.inventory_purchases where account_id='d5100000-0000-4000-8000-000000000001'),0,'canonical provisioning creates no purchase');
select extensions.is((select count(*)::int from public.inventory_receipts where account_id='d5100000-0000-4000-8000-000000000001'),0,'canonical provisioning creates no receipt');
select extensions.is(coalesce((select quantity from public.inventory_item_totals where inventory_item_id=(select id from public.inventory_items where component_id='d5600000-0000-4000-8000-000000000001' and is_canonical)),0::numeric),0::numeric,'canonical item has derived zero stock');
select extensions.is((select is_active from public.inventory_items where component_id='d5600000-0000-4000-8000-000000000001' and is_canonical),true,'provisioned canonical item is active and therefore visible as Out of Stock');

set local role authenticated;
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000001',true);
select extensions.is(public.sync_canonical_inventory_items('d5100000-0000-4000-8000-000000000001'),3,'owner may run controlled canonical sync');
select extensions.is(public.sync_canonical_inventory_items('d5100000-0000-4000-8000-000000000001'),3,'repeated canonical sync is idempotent');
select extensions.throws_ok($$insert into public.inventory_items(account_id,sku,name) values ('d5100000-0000-4000-8000-000000000001',' existing ','Duplicate Real SKU')$$,'23505',null,'duplicate nonblank SKU remains rejected within the account');
reset role;

insert into public.components(id,account_id,code,name,category) values
('d5600000-0000-4000-8000-000000000004','d5100000-0000-4000-8000-000000000001','CAN-FOUR','Canonical Four','Test');
insert into public.machine_model_components(id,account_id,machine_model_id,component_id,slot_code,tracking_method) values
('d5800000-0000-4000-8000-000000000004','d5100000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000004','FOUR','counter_based');
select extensions.is((select count(*)::int from public.inventory_items where component_id='d5600000-0000-4000-8000-000000000004' and is_canonical),1,'future eligible Component profile automatically provisions its canonical item');

update public.components set is_active=false where id='d5600000-0000-4000-8000-000000000001';
select extensions.is((select count(*)::int from public.inventory_items where component_id='d5600000-0000-4000-8000-000000000001' and is_canonical),1,'Component archive preserves its canonical Inventory master and history');

set local role authenticated;
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000002',true);
select extensions.throws_ok($$select public.sync_canonical_inventory_items('d5100000-0000-4000-8000-000000000001')$$,'42501',null,'technician cannot run canonical master sync');
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000003',true);
select extensions.throws_ok($$select public.sync_canonical_inventory_items('d5100000-0000-4000-8000-000000000001')$$,'42501',null,'cross-account owner cannot run canonical sync');
reset role;

set local role anon;
select extensions.throws_ok($$select public.sync_canonical_inventory_items('d5100000-0000-4000-8000-000000000001')$$,'42501',null,'anonymous cannot run canonical sync');
reset role;

select * from extensions.finish();
rollback;
