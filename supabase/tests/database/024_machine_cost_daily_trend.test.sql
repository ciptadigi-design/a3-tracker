begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.get_machine_cost_daily_trend(uuid,uuid,date,date)'::regprocedure),'daily trend RPC is SECURITY DEFINER with empty search path');
select extensions.ok(not has_function_privilege('anon','public.get_machine_cost_daily_trend(uuid,uuid,date,date)','EXECUTE'),'anonymous cannot execute daily trend');
select extensions.ok(has_function_privilege('authenticated','public.get_machine_cost_daily_trend(uuid,uuid,date,date)','EXECUTE'),'authenticated members can execute daily trend');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('d0000000-0000-4000-8000-000000000001','authenticated','authenticated','trend-owner@test.invalid','',now(),'{}','{"display_name":"Trend Owner"}',now(),now()),
('d0000000-0000-4000-8000-000000000002','authenticated','authenticated','trend-other@test.invalid','',now(),'{}','{"display_name":"Trend Other"}',now(),now());
insert into public.accounts(id,code,name,default_timezone,machine_economics_advanced_enabled) values
('d0100000-0000-4000-8000-000000000001','TREND','Trend Account','Asia/Jakarta',true),
('d0100000-0000-4000-8000-000000000002','OTHER','Other Account','Asia/Jakarta',false);
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('d0200000-0000-4000-8000-000000000001','d0100000-0000-4000-8000-000000000001','d0000000-0000-4000-8000-000000000001','owner','active',now()),
('d0200000-0000-4000-8000-000000000002','d0100000-0000-4000-8000-000000000002','d0000000-0000-4000-8000-000000000002','owner','active',now());
insert into public.branches(id,account_id,code,name,timezone) values
('d0300000-0000-4000-8000-000000000001','d0100000-0000-4000-8000-000000000001','JKT','Jakarta','Asia/Jakarta'),
('d0300000-0000-4000-8000-000000000002','d0100000-0000-4000-8000-000000000001','MKS','Makassar','Asia/Makassar'),
('d0300000-0000-4000-8000-000000000003','d0100000-0000-4000-8000-000000000002','OTH','Other','Asia/Jakarta');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name,timezone) values
('d0400000-0000-4000-8000-000000000001','d0100000-0000-4000-8000-000000000001','d0300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','TREND-A','Trend A','Asia/Jakarta'),
('d0400000-0000-4000-8000-000000000002','d0100000-0000-4000-8000-000000000001','d0300000-0000-4000-8000-000000000002','51000000-0000-0000-0000-000000000001','TREND-TZ','Trend TZ',null),
('d0400000-0000-4000-8000-000000000003','d0100000-0000-4000-8000-000000000002','d0300000-0000-4000-8000-000000000003','51000000-0000-0000-0000-000000000001','TREND-X','Trend X',null);
insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('d0500000-0000-4000-8000-000000000001','d0100000-0000-4000-8000-000000000001','d0300000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','54000000-0000-0000-0000-000000000025','53000000-0000-0000-0000-000000000025','TONER_C','active',1000,'2026-07-01 08:00+07','tracking_start',14000,14000);

-- Superseded and voided readings remain audit history but cannot affect daily usage.
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,source,status,previous_reading_id,corrects_reading_id,correction_reason,client_request_id,created_by) values
('d1000000-0000-4000-8000-000000000001','d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 10:00+07','d0000000-0000-4000-8000-000000000001','manual','effective',null,null,null,'d1100000-0000-4000-8000-000000000001','d0000000-0000-4000-8000-000000000001'),
('d1000000-0000-4000-8000-000000000002','d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1100,'2026-08-26 10:00+07','d0000000-0000-4000-8000-000000000001','manual','superseded','d1000000-0000-4000-8000-000000000001',null,'Corrected','d1100000-0000-4000-8000-000000000002','d0000000-0000-4000-8000-000000000001'),
('d1000000-0000-4000-8000-000000000003','d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1200,'2026-08-26 10:00+07','d0000000-0000-4000-8000-000000000001','correction','effective','d1000000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000002',null,'d1100000-0000-4000-8000-000000000003','d0000000-0000-4000-8000-000000000001'),
('d1000000-0000-4000-8000-000000000004','d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1300,'2026-08-26 11:00+07','d0000000-0000-4000-8000-000000000001','manual','voided','d1000000-0000-4000-8000-000000000003',null,'Duplicate','d1100000-0000-4000-8000-000000000004','d0000000-0000-4000-8000-000000000001'),
('d1000000-0000-4000-8000-000000000005','d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',500,'2026-07-31 15:00+00','d0000000-0000-4000-8000-000000000001','manual','effective',null,null,null,'d1100000-0000-4000-8000-000000000005','d0000000-0000-4000-8000-000000000001'),
('d1000000-0000-4000-8000-000000000006','d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',550,'2026-07-31 16:00+00','d0000000-0000-4000-8000-000000000001','manual','effective','d1000000-0000-4000-8000-000000000005',null,null,'d1100000-0000-4000-8000-000000000006','d0000000-0000-4000-8000-000000000001');

set local role authenticated;
select set_config('request.jwt.claim.sub','d0000000-0000-4000-8000-000000000001',true);

-- Unknown external Component consumption and mixed Error / Waste evidence on one day.
select public.replace_machine_component('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','d0500000-0000-4000-8000-000000000001',1250,'2026-08-27 10:00+07','depleted','worn',true,'d0000000-0000-4000-8000-000000000001','Trend Owner',null,'d1200000-0000-4000-8000-000000000001','external_untracked',null,null,null,'Untracked supplier stock');
select public.create_operational_incident('d0100000-0000-4000-8000-000000000001','d0300000-0000-4000-8000-000000000001','2026-08-27 11:00+07','kualitas','human','Known waste','d1300000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001',null,null,null,1,null,'Operator',2000,0,null,null,null);
select public.create_operational_incident('d0100000-0000-4000-8000-000000000001','d0300000-0000-4000-8000-000000000001','2026-08-27 12:00+07','prosedur','machine_operation','Unknown waste','d1300000-0000-4000-8000-000000000002','d0400000-0000-4000-8000-000000000001',null,null,null,1,null,'Operator',0,0,null,null,null);
select public.create_machine_operating_cost('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','electricity',9000,'one_time','Must stay Advanced','d1400000-0000-4000-8000-000000000001','2026-08-27 12:00+07',null,null,null,null,null,'manual');

select extensions.is((select daily_clicks from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-26','2026-08-26')),200::numeric,'daily clicks include only effective correction usage');
select extensions.is((select daily_clicks from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-27','2026-08-27')),50::numeric,'replacement-created effective reading contributes its authoritative usage on that day');
select extensions.is((select known_daily_cost from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-27','2026-08-27')),2000::numeric,'daily Standard known cost includes assessed waste and excludes Advanced cost');
select extensions.is((select unknown_cost_events from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-27','2026-08-27')),2,'unknown Component and unpriced Error / Waste evidence remain explicit');
select extensions.is((select cost_evidence_status from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-27','2026-08-27')),'PARTIAL','mixed known and unknown daily evidence is partial');
select extensions.ok((select known_daily_cost is null from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-01','2026-08-01')),'no cost evidence remains null rather than fabricated zero');
select extensions.is((select daily_clicks from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000002','2026-08-01','2026-08-01')),50::numeric,'machine-local Asia/Makassar date includes its UTC boundary event');
select extensions.is((select sum(daily_clicks) from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),250::numeric,'daily click totals reconcile to effective period usage');
select extensions.is((select count(*)::integer from public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),31,'inclusive period emits deterministic operational dates without fabricating evidence');

select set_config('request.jwt.claim.sub','d0000000-0000-4000-8000-000000000002',true);
select extensions.throws_ok($$select public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')$$,'42501',null,'cross-account member cannot read daily trend');
reset role;
set local role anon;
select extensions.throws_ok($$select public.get_machine_cost_daily_trend('d0100000-0000-4000-8000-000000000001','d0400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')$$,'42501',null,'anonymous daily trend call is denied');
reset role;

select * from extensions.finish();
rollback;
