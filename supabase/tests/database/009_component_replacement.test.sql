begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select relrowsecurity from pg_class where oid='public.component_replacement_events'::regclass),'replacement event table has RLS');
select extensions.is((select count(*)::int from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a where c.oid=any(array['public.component_replacement_events'::regclass,'public.component_replacement_history'::regclass,'public.component_lifecycle_samples'::regclass]) and a.grantee=0),0,'PUBLIC has no replacement relation privileges');
select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)'::regprocedure),'replacement RPC is SECURITY DEFINER with empty search path');
select extensions.ok((select count(*)=0 from pg_catalog.pg_proc p cross join lateral pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a where p.oid='public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)'::regprocedure and a.grantee=0) and not has_function_privilege('anon','public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)','EXECUTE'),'PUBLIC and anonymous RPC execute denied');
select extensions.ok(not has_table_privilege('authenticated','public.component_replacement_events','INSERT') and not has_table_privilege('authenticated','public.component_replacement_events','UPDATE') and not has_table_privilege('authenticated','public.component_replacement_events','DELETE'),'authenticated cannot directly mutate replacement events');
select extensions.ok(not has_table_privilege('authenticated','public.machine_component_lifecycles','UPDATE'),'authenticated cannot directly mutate lifecycles');

insert into auth.users (id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('90000000-0000-0000-0000-000000000001','authenticated','authenticated','m23c-owner@test.invalid','',now(),'{}','{"display_name":"Owner M23C"}',now(),now()),
('90000000-0000-0000-0000-000000000002','authenticated','authenticated','m23c-admin@test.invalid','',now(),'{}','{"display_name":"Admin M23C"}',now(),now()),
('90000000-0000-0000-0000-000000000003','authenticated','authenticated','m23c-tech@test.invalid','',now(),'{}','{"display_name":"Tech M23C"}',now(),now()),
('90000000-0000-0000-0000-000000000004','authenticated','authenticated','m23c-operator@test.invalid','',now(),'{}','{"display_name":"Operator M23C"}',now(),now()),
('90000000-0000-0000-0000-000000000005','authenticated','authenticated','m23c-other@test.invalid','',now(),'{}','{"display_name":"Other M23C"}',now(),now()),
('90000000-0000-0000-0000-000000000006','authenticated','authenticated','m23c-suspended@test.invalid','',now(),'{}','{"display_name":"Suspended M23C"}',now(),now());

insert into public.accounts(id,code,name,created_by,updated_by) values
('91000000-0000-0000-0000-000000000001','M23C-A','M2.3C Account A','90000000-0000-0000-0000-000000000001','90000000-0000-0000-0000-000000000001'),
('91000000-0000-0000-0000-000000000002','M23C-B','M2.3C Account B','90000000-0000-0000-0000-000000000005','90000000-0000-0000-0000-000000000005');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('92000000-0000-0000-0000-000000000001','91000000-0000-0000-0000-000000000001','90000000-0000-0000-0000-000000000001','owner','active',now()),
('92000000-0000-0000-0000-000000000002','91000000-0000-0000-0000-000000000001','90000000-0000-0000-0000-000000000002','admin','active',now()),
('92000000-0000-0000-0000-000000000003','91000000-0000-0000-0000-000000000001','90000000-0000-0000-0000-000000000003','technician','active',now()),
('92000000-0000-0000-0000-000000000004','91000000-0000-0000-0000-000000000001','90000000-0000-0000-0000-000000000004','operator','active',now()),
('92000000-0000-0000-0000-000000000005','91000000-0000-0000-0000-000000000002','90000000-0000-0000-0000-000000000005','owner','active',now()),
('92000000-0000-0000-0000-000000000006','91000000-0000-0000-0000-000000000001','90000000-0000-0000-0000-000000000006','admin','suspended',now());
insert into public.branches(id,account_id,code,name) values
('93000000-0000-0000-0000-000000000001','91000000-0000-0000-0000-000000000001','A','Branch A'),
('93000000-0000-0000-0000-000000000002','91000000-0000-0000-0000-000000000002','B','Branch B');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('94000000-0000-0000-0000-000000000001','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001','M23C-A-01','M2.3C A Machine'),
('94000000-0000-0000-0000-000000000002','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001','M23C-A-02','M2.3C A Second Machine'),
('94000000-0000-0000-0000-000000000003','91000000-0000-0000-0000-000000000002','93000000-0000-0000-0000-000000000002','51000000-0000-0000-0000-000000000001','M23C-B-01','M2.3C B Machine');

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by) values
('95000000-0000-0000-0000-000000000001','91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','52000000-0000-0000-0000-000000000001',1000000,'2026-01-01T00:00:00Z','90000000-0000-0000-0000-000000000001','95000000-0000-0000-0000-000000000011','90000000-0000-0000-0000-000000000001'),
('95000000-0000-0000-0000-000000000002','91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000002','52000000-0000-0000-0000-000000000001',700000,'2026-01-01T00:00:00Z','90000000-0000-0000-0000-000000000001','95000000-0000-0000-0000-000000000012','90000000-0000-0000-0000-000000000001');

insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('96000000-0000-0000-0000-000000000001','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',960000,'2025-12-01','tracking_start',40000,40000),
('96000000-0000-0000-0000-000000000002','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000002','53000000-0000-0000-0000-000000000002','CHARGING_CORONA_M','active',900000,'2025-12-01','tracking_start',40000,40000),
('96000000-0000-0000-0000-000000000003','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000003','53000000-0000-0000-0000-000000000003','CHARGING_CORONA_Y','active',970000,'2025-12-01','tracking_start',40000,40000),
('96000000-0000-0000-0000-000000000004','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000004','53000000-0000-0000-0000-000000000004','CHARGING_CORONA_K','active',980000,'2025-12-01','tracking_start',40000,40000),
('96000000-0000-0000-0000-000000000005','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000006','53000000-0000-0000-0000-000000000006','CLEANING_UNIT','unknown',null,null,'legacy_import',200000,200000),
('96000000-0000-0000-0000-000000000006','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000007','53000000-0000-0000-0000-000000000007','DEVELOPER_C','active',800000,'2025-12-01','tracking_start',100000,100000),
('96000000-0000-0000-0000-000000000007','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000025','53000000-0000-0000-0000-000000000025','TONER_C','active',990000,'2025-12-01','tracking_start',14000,14000),
('96000000-0000-0000-0000-000000000008','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000008','53000000-0000-0000-0000-000000000008','DEVELOPER_M','active',950000,'2025-12-01','tracking_start',100000,100000),
('96000000-0000-0000-0000-000000000009','91000000-0000-0000-0000-000000000001','93000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000002','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',650000,'2025-12-01','tracking_start',40000,40000);

set local role anon;
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000001',1000000,'2026-02-01','normal_eol','worn',null,null,'Anon',null,'97000000-0000-0000-0000-000000000001','external_untracked',null,null,null,'Regression fixture')$$,'42501',null,'anonymous replacement denied');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','90000000-0000-0000-0000-000000000006',true);
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000001',1000000,'2026-02-01','normal_eol','worn',null,null,'Suspended',null,'97000000-0000-0000-0000-000000000002','external_untracked',null,null,null,'Regression fixture')$$,'42501',null,'suspended member denied');
select set_config('request.jwt.claim.sub','90000000-0000-0000-0000-000000000001',true);
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000002','94000000-0000-0000-0000-000000000003','96000000-0000-0000-0000-000000000001',1000000,'2026-02-01','normal_eol','worn',null,null,'Owner',null,'97000000-0000-0000-0000-000000000003','external_untracked',null,null,null,'Regression fixture')$$,'42501',null,'cross-account replacement denied');
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000002','96000000-0000-0000-0000-000000000001',1000000,'2026-02-01','normal_eol','worn',null,null,'Owner',null,'97000000-0000-0000-0000-000000000004','external_untracked',null,null,null,'Regression fixture')$$,'P0002',null,'wrong machine and lifecycle combination denied');
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000005',1000000,'2026-02-01','normal_eol','worn',null,null,'Owner',null,'97000000-0000-0000-0000-000000000005','external_untracked',null,null,null,'Regression fixture')$$,'22023',null,'unknown lifecycle cannot fabricate usage');

select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000001',1000000,'2026-02-01','normal_eol','worn',null,'90000000-0000-0000-0000-000000000001','Owner M23C','Normal replacement','97000000-0000-0000-0000-000000000011','external_untracked',null,null,null,'Regression fixture')$$,'owner can replace at latest counter');
select extensions.is((select status::text from public.machine_component_lifecycles where id='96000000-0000-0000-0000-000000000001'),'closed','replacement closes old lifecycle');
select extensions.is((select removed_counter::bigint from public.machine_component_lifecycles where id='96000000-0000-0000-0000-000000000001'),1000000::bigint,'removed counter populated');
select extensions.is((select removed_at::date from public.machine_component_lifecycles where id='96000000-0000-0000-0000-000000000001'),'2026-02-01'::date,'removed date populated');
select extensions.is((select actual_usage::bigint from public.machine_component_lifecycles where id='96000000-0000-0000-0000-000000000001'),40000::bigint,'old lifecycle actual usage is replacement minus installed');
select extensions.is((select count(*)::int from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000001'),1,'replacement event created exactly once');
select extensions.ok((select new_lifecycle_id is not null from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000001'),'new lifecycle linked');
select extensions.is((select installed_counter::bigint from public.machine_component_lifecycles where id=(select new_lifecycle_id from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000001')),1000000::bigint,'new lifecycle starts at replacement counter');
select extensions.is((select expected_at_install from public.machine_component_lifecycles where id=(select new_lifecycle_id from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000001')),40000::bigint,'new lifecycle snapshots current baseline');
select extensions.is((select count(*)::int from public.machine_component_lifecycles where machine_id='94000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C' and status='active'),1,'one active lifecycle remains for replaced slot');
select extensions.is((select count(*)::int from public.counter_readings where client_request_id='97000000-0000-0000-0000-000000000011'),0,'equal latest counter creates no extra reading');
select extensions.ok((select include_in_adaptive_learning from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000001'),'normal EOL defaults into adaptive learning');
select extensions.is((select current_usage::bigint from public.machine_component_health where lifecycle_id=(select new_lifecycle_id from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000001')),0::bigint,'new lifecycle usage resets naturally to zero');

select set_config('request.jwt.claim.sub','90000000-0000-0000-0000-000000000002',true);
select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000002',1000100,'2026-02-02','preventive','fair',true,'90000000-0000-0000-0000-000000000002','Admin M23C','Intentional inclusion','97000000-0000-0000-0000-000000000012','external_untracked',null,null,null,'Regression fixture')$$,'admin can replace with a higher physical counter');
select extensions.is((select source from public.counter_readings where client_request_id='97000000-0000-0000-0000-000000000012'),'component_replacement','higher replacement writes contextual counter reading');
select extensions.is((select reading_value::bigint from public.counter_readings where client_request_id='97000000-0000-0000-0000-000000000012'),1000100::bigint,'higher replacement reading stores physical counter');
select extensions.is((select reading_value::bigint from public.counter_readings where machine_id='94000000-0000-0000-0000-000000000001' and status='effective' order by observed_at desc,created_at desc,id desc limit 1),1000100::bigint,'replacement reading becomes latest effective counter');
select extensions.is((select actual_usage::bigint from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000002'),100100::bigint,'overdue replacement actual usage is accepted');
select extensions.ok((select include_in_adaptive_learning from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000002'),'learning default can be intentionally overridden');

select set_config('request.jwt.claim.sub','90000000-0000-0000-0000-000000000003',true);
select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000003',1000100,'2026-02-03','print_quality','worn',null,'90000000-0000-0000-0000-000000000003','Tech M23C',null,'97000000-0000-0000-0000-000000000013','external_untracked',null,null,null,'Regression fixture')$$,'technician can replace');
select set_config('request.jwt.claim.sub','90000000-0000-0000-0000-000000000004',true);
select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000004',1000100,'2026-02-04','failure','failed',null,'90000000-0000-0000-0000-000000000004','Operator M23C',null,'97000000-0000-0000-0000-000000000014','external_untracked',null,null,null,'Regression fixture')$$,'operator can replace');
select extensions.ok(not (select include_in_adaptive_learning from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000004'),'failure defaults out of adaptive learning');
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000008',999999,'2026-02-05','damage','failed',false,null,'Operator M23C',null,'97000000-0000-0000-0000-000000000015','external_untracked',null,null,null,'Regression fixture')$$,'22003',null,'counter below latest is denied');
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000008',1000100,'2026-02-05','other','fair',false,null,'Operator M23C',null,'97000000-0000-0000-0000-000000000016','external_untracked',null,null,null,'Regression fixture')$$,'22023',null,'other reason requires notes');

select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000007',1000100,'2026-02-06','depleted','worn',null,null,'Operator M23C','Toner depleted','97000000-0000-0000-0000-000000000017','external_untracked',null,null,null,'Regression fixture')$$,'toner replacement uses same transaction');
select extensions.is((select actual_usage::bigint from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000007'),10100::bigint,'toner actual usage preserves real yield');

select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000006',1000100,'2026-02-07','normal_eol','worn',null,null,'Operator M23C','Overdue accepted','97000000-0000-0000-0000-000000000018','external_untracked',null,null,null,'Regression fixture')$$,'overdue lifecycle can be replaced');
select extensions.ok((select actual_usage > expected_at_install from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000006'),'overdue evidence is retained above expected life');

select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000001',1000000,'2026-02-01','normal_eol','worn',null,'90000000-0000-0000-0000-000000000001','Owner M23C','Normal replacement','97000000-0000-0000-0000-000000000011','external_untracked',null,null,null,'Regression fixture')$$,'idempotent retry returns existing event');
select extensions.is((select count(*)::int from public.component_replacement_events where client_request_id='97000000-0000-0000-0000-000000000011'),1,'idempotent retry creates no duplicate event');
select extensions.is((select count(*)::int from public.machine_component_lifecycles where machine_id='94000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),2,'double submit creates no extra lifecycle');
select extensions.throws_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000001','96000000-0000-0000-0000-000000000001',1000100,'2026-02-08','failure','failed',false,null,'Operator M23C',null,'97000000-0000-0000-0000-000000000019','external_untracked',null,null,null,'Regression fixture')$$,'40001',null,'second replacement of closed lifecycle loses safely');

select extensions.throws_ok($$update public.component_replacement_events set notes='tampered' where client_request_id='97000000-0000-0000-0000-000000000011'$$,'42501',null,'replacement event cannot be updated directly');
select extensions.throws_ok($$delete from public.component_replacement_events where client_request_id='97000000-0000-0000-0000-000000000011'$$,'42501',null,'replacement event cannot be deleted directly');
select extensions.throws_ok($$update public.machine_component_lifecycles set notes='tampered' where id='96000000-0000-0000-0000-000000000008'$$,'42501',null,'lifecycle cannot be directly modified by client');
reset role;

select extensions.is((select expected_at_install from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000001'),40000::bigint,'old lifecycle expected snapshot remains immutable');
update public.machine_model_components set baseline_expected_clicks=45000 where id='54000000-0000-0000-0000-000000000001';
set local role authenticated;
select set_config('request.jwt.claim.sub','90000000-0000-0000-0000-000000000001',true);
select extensions.lives_ok($$select public.replace_machine_component('91000000-0000-0000-0000-000000000001','94000000-0000-0000-0000-000000000002','96000000-0000-0000-0000-000000000009',700000,'2026-02-08','normal_eol','worn',null,null,'Owner M23C',null,'97000000-0000-0000-0000-000000000020','external_untracked',null,null,null,'Regression fixture')$$,'replacement after profile edit succeeds');
select extensions.is((select expected_at_install from public.component_replacement_events where previous_lifecycle_id='96000000-0000-0000-0000-000000000009'),40000::bigint,'completed old lifecycle keeps original 40K expectation');
select extensions.is((select new_expected_at_install from public.component_replacement_history where previous_lifecycle_id='96000000-0000-0000-0000-000000000009'),45000::bigint,'new lifecycle snapshots current 45K baseline');
reset role;

select extensions.is((select count(*)::int from public.machine_component_lifecycles where account_id='91000000-0000-0000-0000-000000000001' and lower(slot_code)='test_component'),0,'TEST_COMPONENT remains untouched');
select extensions.is((select count(*)::int from public.component_lifecycle_samples where account_id='91000000-0000-0000-0000-000000000001' and include_in_adaptive_learning),6,'sample view exposes only explicitly eligible facts when filtered');
select extensions.ok((select position('for update' in lower(pg_get_functiondef('public.replace_machine_component(uuid,uuid,uuid,numeric,timestamptz,public.component_replacement_reason,public.component_removal_condition,boolean,uuid,text,text,uuid,public.component_replacement_inventory_source,uuid,uuid,numeric,text)'::regprocedure))) > 0),'RPC contains row locking for concurrency control');
select extensions.ok((select count(*)=1 from pg_indexes where schemaname='public' and indexname='machine_component_lifecycles_open_slot_key'),'partial uniqueness still protects one open lifecycle per slot');

select * from extensions.finish();
rollback;
