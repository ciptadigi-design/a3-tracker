begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[]
  from pg_proc where oid='public.get_machine_cost_period(uuid,uuid,date,date)'::regprocedure),
  'machine cost RPC is SECURITY DEFINER with empty search path');
select extensions.ok(not has_function_privilege('anon','public.get_machine_cost_period(uuid,uuid,date,date)','EXECUTE'),
  'anonymous cannot execute machine cost RPC');
select extensions.ok(has_function_privilege('authenticated','public.get_machine_cost_period(uuid,uuid,date,date)','EXECUTE'),
  'authenticated members can execute machine cost RPC');
select extensions.is((select count(*)::int from pg_catalog.pg_class relation
  cross join lateral pg_catalog.aclexplode(coalesce(relation.relacl,pg_catalog.acldefault('r',relation.relowner))) acl
  where relation.oid=any(array['public.machine_component_consumption_events'::regclass,'public.machine_lifecycle_cost_evidence'::regclass])
    and acl.grantee=0),0,'PUBLIC has no machine cost view privileges');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('a5000000-0000-4000-8000-000000000001','authenticated','authenticated','m25a-owner@test.invalid','',now(),'{}','{"display_name":"M25A Owner"}',now(),now()),
('a5000000-0000-4000-8000-000000000002','authenticated','authenticated','m25a-tech@test.invalid','',now(),'{}','{"display_name":"M25A Tech"}',now(),now()),
('a5000000-0000-4000-8000-000000000003','authenticated','authenticated','m25a-suspended@test.invalid','',now(),'{}','{"display_name":"M25A Suspended"}',now(),now()),
('a5000000-0000-4000-8000-000000000004','authenticated','authenticated','m25a-other@test.invalid','',now(),'{}','{"display_name":"M25A Other"}',now(),now());

insert into public.accounts(id,code,name,default_timezone,machine_economics_advanced_enabled) values
('a5100000-0000-4000-8000-000000000001','M25A','M2.5A Account','Asia/Jakarta',true),
('a5100000-0000-4000-8000-000000000002','M25B','Other Account','Asia/Jakarta',false);
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('a5200000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','a5000000-0000-4000-8000-000000000001','owner','active',now()),
('a5200000-0000-4000-8000-000000000002','a5100000-0000-4000-8000-000000000001','a5000000-0000-4000-8000-000000000002','technician','active',now()),
('a5200000-0000-4000-8000-000000000003','a5100000-0000-4000-8000-000000000001','a5000000-0000-4000-8000-000000000003','admin','suspended',now()),
('a5200000-0000-4000-8000-000000000004','a5100000-0000-4000-8000-000000000002','a5000000-0000-4000-8000-000000000004','owner','active',now());
insert into public.branches(id,account_id,code,name,timezone) values
('a5300000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','JKT','Jakarta Branch','Asia/Makassar'),
('a5300000-0000-4000-8000-000000000002','a5100000-0000-4000-8000-000000000002','OTH','Other Branch','Asia/Jakarta');

insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name,timezone) values
('a5400000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','COST-A','Cost Machine A','Asia/Jakarta'),
('a5400000-0000-4000-8000-000000000002','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','COST-B','Zero Click Machine',null),
('a5400000-0000-4000-8000-000000000003','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','COST-C','Missing Start Machine',null),
('a5400000-0000-4000-8000-000000000004','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','COST-D','Missing End Machine',null),
('a5400000-0000-4000-8000-000000000005','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','COST-E','No Data Machine',null),
('a5400000-0000-4000-8000-000000000006','a5100000-0000-4000-8000-000000000002','a5300000-0000-4000-8000-000000000002','51000000-0000-0000-0000-000000000001','COST-X','Other Machine',null),
('a5400000-0000-4000-8000-000000000007','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','COST-F','Zero Consumption Machine',null);

insert into public.operational_people(id,account_id,linked_user_id,name,code) values
('a5500000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','a5000000-0000-4000-8000-000000000001','M25A Owner','OWNER');
insert into public.inventory_locations(id,account_id,branch_id,code,name) values
('a5600000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','FLOOR','Machine Floor');
insert into public.inventory_items(id,account_id,component_id,sku,name,category,unit) values
('a5700000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000001','COR-C','Corona Cyan','Corona','pcs');
insert into public.inventory_suppliers(id,account_id,supplier_code,name) values
('a5800000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','SUP','PT Cost Supplier');

insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,
  installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('a5900000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',500,'2026-07-01 08:00+07','tracking_start',40000,40000),
('a5900000-0000-4000-8000-000000000002','a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','54000000-0000-0000-0000-000000000025','53000000-0000-0000-0000-000000000025','TONER_C','active',600,'2026-07-01 08:00+07','tracking_start',14000,14000);

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by,previous_reading_id) values
('a5a00000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000001','a5000000-0000-4000-8000-000000000001',null),
('a5a00000-0000-4000-8000-000000000002','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',700,'2026-07-31 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000002','a5000000-0000-4000-8000-000000000001',null),
('a5a00000-0000-4000-8000-000000000003','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',700,'2026-08-27 15:59+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000003','a5000000-0000-4000-8000-000000000001','a5a00000-0000-4000-8000-000000000002'),
('a5a00000-0000-4000-8000-000000000004','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000003','52000000-0000-0000-0000-000000000001',800,'2026-08-05 09:00+07','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000004','a5000000-0000-4000-8000-000000000001',null),
('a5a00000-0000-4000-8000-000000000005','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000004','52000000-0000-0000-0000-000000000001',900,'2026-07-31 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000005','a5000000-0000-4000-8000-000000000001',null),
('a5a00000-0000-4000-8000-000000000008','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','52000000-0000-0000-0000-000000000001',100,'2026-07-31 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000008','a5000000-0000-4000-8000-000000000001',null),
('a5a00000-0000-4000-8000-000000000009','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','52000000-0000-0000-0000-000000000001',300,'2026-08-27 15:59+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000009','a5000000-0000-4000-8000-000000000001','a5a00000-0000-4000-8000-000000000008');

set local role authenticated;
select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000001',true);

select public.create_inventory_purchase_auto('a5100000-0000-4000-8000-000000000001','a5800000-0000-4000-8000-000000000001',
  '2026-07-10',null,'IDR',null,'[{"inventory_item_id":"a5700000-0000-4000-8000-000000000001","quantity":"2","unit_price":"2650000"}]',
  'a5c00000-0000-4000-8000-000000000001');
select public.initialize_inventory_stock_costed('a5100000-0000-4000-8000-000000000001','a5700000-0000-4000-8000-000000000001',
  'a5600000-0000-4000-8000-000000000001',3,'2026-07-15 09:00+07','a5500000-0000-4000-8000-000000000001',null,
  'a5d00000-0000-4000-8000-000000000001',2650000);

select public.replace_machine_component('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001',
  'a5900000-0000-4000-8000-000000000001',1100,'2026-08-10 10:00+07','normal_eol','worn',true,
  'a5000000-0000-4000-8000-000000000001','M25A Owner',null,'a5e00000-0000-4000-8000-000000000001','inventory',
  'a5700000-0000-4000-8000-000000000001','a5600000-0000-4000-8000-000000000001',1,null);
select public.replace_machine_component('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001',
  'a5900000-0000-4000-8000-000000000002',1200,'2026-08-15 10:00+07','depleted','worn',true,
  'a5000000-0000-4000-8000-000000000001','M25A Owner',null,'a5e00000-0000-4000-8000-000000000002','external_untracked',
  null,null,null,'External toner with unavailable cost');
select public.replace_machine_component('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001',
  (select new_lifecycle_id from public.component_replacement_events where client_request_id='a5e00000-0000-4000-8000-000000000001'),
  1300,'2026-08-20 10:00+07','normal_eol','worn',true,'a5000000-0000-4000-8000-000000000001','M25A Owner',null,
  'a5e00000-0000-4000-8000-000000000003','inventory','a5700000-0000-4000-8000-000000000001',
  'a5600000-0000-4000-8000-000000000001',1,null);
reset role;

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,source,client_request_id,created_by,previous_reading_id) values
('a5a00000-0000-4000-8000-000000000006','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1350,'2026-08-20 15:00+07','a5000000-0000-4000-8000-000000000001','manual','a5b00000-0000-4000-8000-000000000006','a5000000-0000-4000-8000-000000000001',(select id from public.counter_readings where client_request_id='a5e00000-0000-4000-8000-000000000003')),
('a5a00000-0000-4000-8000-000000000007','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1500,'2026-08-27 16:59+00','a5000000-0000-4000-8000-000000000001','manual','a5b00000-0000-4000-8000-000000000007','a5000000-0000-4000-8000-000000000001','a5a00000-0000-4000-8000-000000000006');

set local role authenticated;
select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000001',true);

select public.create_machine_operating_cost('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001',
  'external_technician',500000,'one_time','Technician labor only','b5b00000-0000-4000-8000-000000000001','2026-08-10 09:00+07',null,null,
  'a5500000-0000-4000-8000-000000000001','SERVICE-10','Inventory parts excluded','manual');
select public.create_machine_operating_cost('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001',
  'service_contract',3100000,'daily_proration_v1','August service contract','b5b00000-0000-4000-8000-000000000002',null,'2026-08-01','2026-08-31',
  null,'CONTRACT-AUG',null,'manual');
select public.create_machine_operating_cost('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002',
  'electricity',10000,'one_time','Timezone boundary allocation','b5b00000-0000-4000-8000-000000000003','2026-07-31 17:30+00',null,null,null,null,null,'manual');
select public.create_machine_operating_cost('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001',
  'calibration',50000,'one_time','Voided correction fixture','b5b00000-0000-4000-8000-000000000004','2026-08-12 09:00+07',null,null,null,null,null,'manual');
select public.void_machine_operating_cost((select id from public.machine_operating_costs where client_request_id='b5b00000-0000-4000-8000-000000000004'),
  'Duplicate invoice','b5b00000-0000-4000-8000-000000000005');

select public.create_operational_incident('a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001',
  '2026-08-11 11:00+07','kualitas','human','Explicit material waste','b5c00000-0000-4000-8000-000000000001',
  'a5400000-0000-4000-8000-000000000001',null,null,null,5,null,'Operator',100000,0,null,null,null);
select public.create_operational_incident('a5100000-0000-4000-8000-000000000001','a5300000-0000-4000-8000-000000000001',
  '2026-08-12 11:00+07','prosedur','machine_operation','Unpriced reprint evidence','b5c00000-0000-4000-8000-000000000002',
  'a5400000-0000-4000-8000-000000000001',null,null,null,2,null,'Operator',0,0,null,null,null);

select extensions.is((select known_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-10')),1500000::numeric,'partial period daily-prorates 10 of 31 days and includes one-time cost');
select extensions.is((select known_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),3600000::numeric,'full period includes full service contract and one-time cost');
select extensions.is((select known_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),3200000::numeric,'voided record is excluded from period operating cost');
select extensions.is((select operating_cost_records from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),2,'only posted overlapping operating records count');
select extensions.is((select jsonb_array_length(operating_cost_breakdown) from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),2,'category breakdown remains composable');
select extensions.is((select known_error_waste_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),100000::numeric,'only explicit incident monetary evidence becomes error/waste cost');
select extensions.is((select unknown_error_waste_events from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),1,'zero-loss incident remains unpriced evidence rather than arbitrary money');
select extensions.is((select known_machine_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),8600000::numeric,'machine economics adds M2.5A consumption, operating cost, and error/waste exactly once');
select extensions.is((select known_machine_operating_cost_per_click from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),17200::numeric,'broader known cost per click uses valid M2.5A click volume');
select extensions.is((select known_standard_machine_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),5400000::numeric,'Standard Machine Cost adds only component consumption and assessed error/waste');
select extensions.is((select known_standard_cost_per_click from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),10800::numeric,'Standard Cost / Click uses valid clicks and never includes advanced costs');
select extensions.is((select known_full_machine_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),8600000::numeric,'Full Machine Operating Cost adds Advanced Operating Costs once');
select extensions.is((select known_full_operating_cost_per_click from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),17200::numeric,'Full Operating Cost / Click coexists with Standard Cost / Click');
select extensions.is((select economics_status::text from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'PARTIAL','unknown component and incident evidence keeps economics partial');
select extensions.is((select known_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-01')),10000::numeric,'one-time attribution respects machine operational timezone boundary');
select extensions.ok((select known_machine_operating_cost_per_click is null from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-01')),'zero clicks never produce broader cost per click');
select extensions.is((select known_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-07-15','2026-08-15')),2000000::numeric,'cross-month query deterministically prorates overlapping August coverage');
select extensions.is((select known_machine_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','2026-08-01','2026-08-27')),0::numeric,'valid clicks with no consumption or operating evidence remain a genuine zero');
select extensions.is((select known_machine_operating_cost_per_click from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','2026-08-01','2026-08-27')),0::numeric,'zero economics with positive clicks produces valid zero cost per click');
select extensions.is((select economics_status::text from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000003','2026-08-01','2026-08-27')),'COMPLETE','an in-period baseline is complete zero-click evidence without consumption');
select extensions.is((select economics_status::text from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000005','2026-08-01','2026-08-27')),'NO_DATA','machine with no clicks or economics remains NO_DATA');
select extensions.is((select count(*)::int from pg_enum value join pg_type type on type.oid=value.enumtypid where type.typname='machine_operating_cost_category'),11,'controlled category architecture includes all initial codes');
select extensions.is((select format_type(attribute.atttypid,attribute.atttypmod) from pg_attribute attribute where attribute.attrelid='public.machine_operating_costs'::regclass and attribute.attname='amount'),'numeric(30,2)','financial authority uses fixed PostgreSQL NUMERIC precision');
select extensions.is((select purchase_cost_context from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-07-01','2026-07-31')),5300000::numeric,'purchase remains context and is not added to machine operating cost');
select extensions.is((select known_machine_operating_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-20','2026-08-27')),3450000::numeric,'realized lifecycle cost and inventory context are excluded from economics total');
select extensions.is((select known_standard_machine_cost from public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-20','2026-08-27')),2650000::numeric,'component-only Standard period excludes Advanced cost, purchase, and remaining Inventory');
select extensions.lives_ok($$select public.create_machine_operating_cost('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','external_technician',500000,'one_time','Technician labor only','b5b00000-0000-4000-8000-000000000001','2026-08-10 09:00+07',null,null,'a5500000-0000-4000-8000-000000000001','SERVICE-10','Inventory parts excluded','manual')$$,'identical create retry is idempotent');
select extensions.throws_ok($$select public.create_machine_operating_cost('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','external_technician',600000,'one_time','Technician labor only','b5b00000-0000-4000-8000-000000000001','2026-08-10 09:00+07',null,null,'a5500000-0000-4000-8000-000000000001','SERVICE-10','Inventory parts excluded','manual')$$,'23505',null,'changed create retry payload is rejected');
select extensions.lives_ok($$select public.void_machine_operating_cost((select id from public.machine_operating_costs where client_request_id='b5b00000-0000-4000-8000-000000000004'),'Duplicate invoice','b5b00000-0000-4000-8000-000000000005')$$,'identical void retry is idempotent');

select extensions.is((select resolved_timezone from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'Asia/Jakarta','machine timezone overrides branch timezone');
select extensions.is((select period_start_at from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'2026-07-31 17:00+00'::timestamptz,'local start date resolves to timezone-safe boundary');
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'COMPLETE','counter evidence is complete at deterministic boundaries');
select extensions.is((select start_counter from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),1100::numeric,'legacy start field identifies the first effective reading attributed to the period');
select extensions.is((select end_counter from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),1500::numeric,'latest reading through period end boundary is end counter');
select extensions.is((select total_clicks from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),500::numeric,'period clicks sum authoritative usage attributed to effective readings in the period');
select extensions.is((select total_clicks from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-20','2026-08-20')),150::numeric,'multiple effective readings on one day each contribute authoritative usage');
select extensions.is((select total_consumption_events from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),3,'multiple component consumptions are counted');
select extensions.is((select known_consumption_events from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),2,'inventory-backed known events are distinguished');
select extensions.is((select unknown_consumption_events from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),1,'external consumption stays unknown');
select extensions.is((select known_consumption_cost from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),5300000::numeric,'known consumption is the FIFO allocation sum');
select extensions.is((select consumption_event_coverage_percent from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),66.67::numeric,'event coverage reports known evidence share');
select extensions.is((select consumption_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'PARTIAL','mixed known and unknown consumption is partial');
select extensions.is((select cost_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'PARTIAL','machine cost status retains incomplete evidence');
select extensions.is((select known_component_cost_per_click from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),10600::numeric,'known component cost per click uses period clicks');
select extensions.is((select purchase_cost_context from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),0::numeric,'purchase cost does not move into consumption period');
select extensions.is((select purchase_cost_context from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-07-01','2026-07-31')),5300000::numeric,'purchase cost stays in purchase period');
select extensions.is((select ending_known_inventory_cost_context from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),2650000::numeric,'ending branch Inventory Cost Basis is derived as of period end');
select extensions.is((select jsonb_array_length(component_breakdown) from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),2,'component composition returns each consumed component');
select extensions.is((select sum((entry->>'known_consumption_cost')::numeric) from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27') summary cross join lateral jsonb_array_elements(summary.component_breakdown) entry),5300000::numeric,'component breakdown reconciles to known consumption');
select extensions.is((select jsonb_array_length(realized_lifecycle_evidence) from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),3,'completed lifecycle evidence is exposed analytically');
select extensions.is((select known_consumption_cost from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-20','2026-08-27')),2650000::numeric,'lifecycle realization is not double-counted into period consumption');

select extensions.is((select total_clicks from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-27')),0::numeric,'equal counters are valid zero clicks');
select extensions.ok((select known_component_cost_per_click is null from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-27')),'zero clicks never divide by zero');
select extensions.is((select cost_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-27')),'NO_CONSUMPTION','valid clicks with no component consumption is explicit');
select extensions.is((select known_consumption_cost from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-27')),0::numeric,'zero consumption is a valid numeric zero');
select extensions.is((select total_clicks from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','2026-08-01','2026-08-27')),200::numeric,'positive click evidence is isolated to its machine');
select extensions.is((select known_component_cost_per_click from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','2026-08-01','2026-08-27')),0::numeric,'positive clicks with zero consumption produce valid zero component cost per click');
select extensions.is((select total_consumption_events from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-09')),0,'consumption outside the selected period is excluded');
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000003','2026-08-01','2026-08-27')),'COMPLETE','an in-period baseline is valid counter evidence without requiring a calendar boundary row');
select extensions.is((select total_clicks from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000003','2026-08-01','2026-08-27')),0::numeric,'a baseline contributes zero usage rather than its absolute reading value');
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000004','2026-08-01','2026-08-27')),'NO_DATA','a pre-period reading alone is not counter evidence in the selected period');
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000005','2026-08-01','2026-08-27')),'NO_DATA','machine without readings reports no counter data');
select extensions.ok((select total_clicks is null from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000004','2026-08-01','2026-08-27')),'a selected period with no effective reading keeps clicks null');

select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000002',true);
select extensions.lives_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'technician can read machine cost evidence');
select extensions.lives_ok($$select public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'technician can read machine economics');
select extensions.throws_ok($$select public.create_machine_operating_cost('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','labor',100,'one_time','Denied','b5b00000-0000-4000-8000-000000000010','2026-08-10',null,null,null,null,null,'manual')$$,'42501',null,'technician cannot create operating cost');
select extensions.throws_ok($$update public.machine_operating_costs set description='tampered' where client_request_id='b5b00000-0000-4000-8000-000000000001'$$,'42501',null,'direct posted-cost mutation is denied');
select extensions.is((select count(*)::int from public.machine_component_consumption_events where account_id='a5100000-0000-4000-8000-000000000001'),3,'technician can read intended tenant cost events');
select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000003',true);
select extensions.throws_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'42501',null,'suspended member cannot read machine cost RPC');
select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000004',true);
select extensions.throws_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'42501',null,'cross-account machine cost is denied');
select extensions.throws_ok($$select public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'42501',null,'cross-account machine economics is denied');
select extensions.is((select count(*)::int from public.machine_operating_costs where account_id='a5100000-0000-4000-8000-000000000001'),0,'cross-account operating cost rows are isolated');
select extensions.is((select count(*)::int from public.machine_component_consumption_events where account_id='a5100000-0000-4000-8000-000000000001'),0,'cross-account cost view is isolated by RLS');
reset role;

select extensions.ok((select count(*)=1 from pg_indexes where schemaname='public' and indexname='counter_readings_effective_history_idx'),'counter boundary query reuses effective stream index');
select extensions.ok((select count(*)=1 from pg_indexes where schemaname='public' and indexname='component_replacement_events_machine_history_idx'),'consumption period query reuses machine history index');
set local role anon;
select extensions.throws_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-27','2026-08-01')$$,'42501',null,'unauthenticated direct call is denied before period validation');
select extensions.throws_ok($$select public.get_machine_economics_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'42501',null,'anonymous machine economics is denied');
reset role;

select * from extensions.finish();
rollback;
