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

insert into public.accounts(id,code,name,default_timezone) values
('a5100000-0000-4000-8000-000000000001','M25A','M2.5A Account','Asia/Jakarta'),
('a5100000-0000-4000-8000-000000000002','M25B','Other Account','Asia/Jakarta');
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

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by) values
('a5a00000-0000-4000-8000-000000000001','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000001','a5000000-0000-4000-8000-000000000001'),
('a5a00000-0000-4000-8000-000000000002','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',700,'2026-07-31 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000002','a5000000-0000-4000-8000-000000000001'),
('a5a00000-0000-4000-8000-000000000003','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',700,'2026-08-27 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000003','a5000000-0000-4000-8000-000000000001'),
('a5a00000-0000-4000-8000-000000000004','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000003','52000000-0000-0000-0000-000000000001',800,'2026-08-05 09:00+07','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000004','a5000000-0000-4000-8000-000000000001'),
('a5a00000-0000-4000-8000-000000000005','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000004','52000000-0000-0000-0000-000000000001',900,'2026-07-31 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000005','a5000000-0000-4000-8000-000000000001'),
('a5a00000-0000-4000-8000-000000000008','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','52000000-0000-0000-0000-000000000001',100,'2026-07-31 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000008','a5000000-0000-4000-8000-000000000001'),
('a5a00000-0000-4000-8000-000000000009','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000007','52000000-0000-0000-0000-000000000001',300,'2026-08-27 16:00+00','a5000000-0000-4000-8000-000000000001','a5b00000-0000-4000-8000-000000000009','a5000000-0000-4000-8000-000000000001');

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

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,source,client_request_id,created_by) values
('a5a00000-0000-4000-8000-000000000006','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1350,'2026-08-20 15:00+07','a5000000-0000-4000-8000-000000000001','manual','a5b00000-0000-4000-8000-000000000006','a5000000-0000-4000-8000-000000000001'),
('a5a00000-0000-4000-8000-000000000007','a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1500,'2026-08-27 17:00+00','a5000000-0000-4000-8000-000000000001','manual','a5b00000-0000-4000-8000-000000000007','a5000000-0000-4000-8000-000000000001');

set local role authenticated;
select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000001',true);

select extensions.is((select resolved_timezone from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'Asia/Jakarta','machine timezone overrides branch timezone');
select extensions.is((select period_start_at from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'2026-07-31 17:00+00'::timestamptz,'local start date resolves to timezone-safe boundary');
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),'COMPLETE','counter evidence is complete at deterministic boundaries');
select extensions.is((select start_counter from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),1000::numeric,'latest reading at period start is start counter');
select extensions.is((select end_counter from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),1500::numeric,'latest reading through period end boundary is end counter');
select extensions.is((select total_clicks from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')),500::numeric,'cumulative counter volume is end minus start, never a sum');
select extensions.is((select total_clicks from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-20','2026-08-20')),150::numeric,'multiple readings on one day use the latest reading before the end boundary');
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
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000003','2026-08-01','2026-08-27')),'INSUFFICIENT_START','first reading inside period does not fabricate a start boundary');
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000004','2026-08-01','2026-08-27')),'INSUFFICIENT_END','a pre-period reading alone does not fabricate an end boundary');
select extensions.is((select counter_status::text from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000005','2026-08-01','2026-08-27')),'NO_DATA','machine without readings reports no counter data');
select extensions.ok((select total_clicks is null from public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000003','2026-08-01','2026-08-27')),'insufficient evidence never fabricates zero clicks');

select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000002',true);
select extensions.lives_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'technician can read machine cost evidence');
select extensions.is((select count(*)::int from public.machine_component_consumption_events where account_id='a5100000-0000-4000-8000-000000000001'),3,'technician can read intended tenant cost events');
select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000003',true);
select extensions.throws_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'42501',null,'suspended member cannot read machine cost RPC');
select set_config('request.jwt.claim.sub','a5000000-0000-4000-8000-000000000004',true);
select extensions.throws_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-27')$$,'42501',null,'cross-account machine cost is denied');
select extensions.is((select count(*)::int from public.machine_component_consumption_events where account_id='a5100000-0000-4000-8000-000000000001'),0,'cross-account cost view is isolated by RLS');
reset role;

select extensions.ok((select count(*)=1 from pg_indexes where schemaname='public' and indexname='counter_readings_effective_history_idx'),'counter boundary query reuses effective stream index');
select extensions.ok((select count(*)=1 from pg_indexes where schemaname='public' and indexname='component_replacement_events_machine_history_idx'),'consumption period query reuses machine history index');
set local role anon;
select extensions.throws_ok($$select public.get_machine_cost_period('a5100000-0000-4000-8000-000000000001','a5400000-0000-4000-8000-000000000001','2026-08-27','2026-08-01')$$,'42501',null,'unauthenticated direct call is denied before period validation');
reset role;

select * from extensions.finish();
rollback;
