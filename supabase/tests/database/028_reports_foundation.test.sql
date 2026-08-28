begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

-- Every public report entry point is read-only, guarded, and callable only through authenticated roles.
select extensions.ok((select bool_and(prosecdef and proconfig @> array['search_path=""']::text[]) from pg_proc
  where oid=any(array[
    'public.get_report_overview(uuid,uuid,uuid,date,date)'::regprocedure,
    'public.get_report_machine_performance(uuid,uuid,uuid,date,date)'::regprocedure,
    'public.get_report_machine_economics(uuid,uuid,uuid,date,date)'::regprocedure,
    'public.get_report_daily_clicks(uuid,uuid,uuid,date,date)'::regprocedure,
    'public.get_report_component_consumption(uuid,uuid,uuid,date,date)'::regprocedure,
    'public.get_report_error_waste(uuid,uuid,uuid,date,date,public.operational_incident_category,public.operational_incident_status)'::regprocedure,
    'public.get_report_inventory_activity(uuid,uuid,date,date)'::regprocedure,
    'public.get_report_purchase_lines(uuid,date,date)'::regprocedure,
    'public.get_report_inventory_stock(uuid,uuid)'::regprocedure
  ])),'all Reports RPCs are SECURITY DEFINER with an empty search path');
select extensions.ok(not has_function_privilege('anon','public.get_report_overview(uuid,uuid,uuid,date,date)','EXECUTE'),'anonymous has no Reports RPC grant');
select extensions.ok(has_function_privilege('authenticated','public.get_report_overview(uuid,uuid,uuid,date,date)','EXECUTE'),'authenticated role reaches the membership-guarded Reports RPC');
select extensions.ok(not has_function_privilege('authenticated','public.resolve_operational_report_scope(uuid,uuid,uuid,date,date)','EXECUTE'),'internal report scope resolver is not directly exposed');
select extensions.ok((select count(*)=2 from pg_indexes where schemaname='public' and indexname in ('inventory_movements_account_time_idx','inventory_receipts_account_time_idx')),'justified inventory report indexes exist');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('b6000000-0000-4000-8000-000000000001','authenticated','authenticated','report-owner@test.invalid','',now(),'{}','{"display_name":"Report Owner"}',now(),now()),
('b6000000-0000-4000-8000-000000000002','authenticated','authenticated','report-admin@test.invalid','',now(),'{}','{"display_name":"Report Admin"}',now(),now()),
('b6000000-0000-4000-8000-000000000003','authenticated','authenticated','report-tech@test.invalid','',now(),'{}','{"display_name":"Report Tech"}',now(),now()),
('b6000000-0000-4000-8000-000000000004','authenticated','authenticated','report-operator@test.invalid','',now(),'{}','{"display_name":"Report Operator"}',now(),now()),
('b6000000-0000-4000-8000-000000000005','authenticated','authenticated','report-suspended@test.invalid','',now(),'{}','{"display_name":"Report Suspended"}',now(),now()),
('b6000000-0000-4000-8000-000000000006','authenticated','authenticated','report-other@test.invalid','',now(),'{}','{"display_name":"Report Other"}',now(),now());
insert into public.accounts(id,code,name,default_timezone,machine_economics_advanced_enabled) values
('b6100000-0000-4000-8000-000000000001','M26A-A','M2.6A Account','Asia/Jakarta',false),
('b6100000-0000-4000-8000-000000000002','M26A-B','M2.6A Other','Asia/Jakarta',false);
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('b6200000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000001','owner','active',now()),
('b6200000-0000-4000-8000-000000000002','b6100000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000002','admin','active',now()),
('b6200000-0000-4000-8000-000000000003','b6100000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000003','technician','active',now()),
('b6200000-0000-4000-8000-000000000004','b6100000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000004','operator','active',now()),
('b6200000-0000-4000-8000-000000000005','b6100000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000005','admin','suspended',now()),
('b6200000-0000-4000-8000-000000000006','b6100000-0000-4000-8000-000000000002','b6000000-0000-4000-8000-000000000006','owner','active',now());
insert into public.branches(id,account_id,code,name,timezone) values
('b6300000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','JKT','Jakarta','Asia/Jakarta'),
('b6300000-0000-4000-8000-000000000002','b6100000-0000-4000-8000-000000000001','SBY','Surabaya','Asia/Jakarta'),
('b6300000-0000-4000-8000-000000000003','b6100000-0000-4000-8000-000000000002','OTH','Other','Asia/Jakarta');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name,timezone) values
('b6400000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','RPT-A1','Report Machine A1','Asia/Jakarta'),
('b6400000-0000-4000-8000-000000000002','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','RPT-A2','Report Machine A2','Asia/Jakarta'),
('b6400000-0000-4000-8000-000000000003','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000002','51000000-0000-0000-0000-000000000001','RPT-B1','Report Machine B1','Asia/Jakarta'),
('b6400000-0000-4000-8000-000000000004','b6100000-0000-4000-8000-000000000002','b6300000-0000-4000-8000-000000000003','51000000-0000-0000-0000-000000000001','RPT-X','Other Machine','Asia/Jakarta');

-- Effective usage: A1=350 across two active dates; A2=80; B1=70. Local Sep 1 boundary is excluded from August.
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by,previous_reading_id,status,correction_reason) values
('b6500000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000001',null,'effective',null),
('b6500000-0000-4000-8000-000000000002','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1100,'2026-08-10 09:00+07','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000002','b6000000-0000-4000-8000-000000000001','b6500000-0000-4000-8000-000000000001','effective',null),
('b6500000-0000-4000-8000-000000000003','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1150,'2026-08-10 14:00+07','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000003','b6000000-0000-4000-8000-000000000001','b6500000-0000-4000-8000-000000000002','effective',null),
('b6500000-0000-4000-8000-000000000004','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1350,'2026-08-20 10:00+07','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000004','b6000000-0000-4000-8000-000000000001','b6500000-0000-4000-8000-000000000003','effective',null),
('b6500000-0000-4000-8000-000000000005','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1400,'2026-08-31 17:30+00','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000005','b6000000-0000-4000-8000-000000000001','b6500000-0000-4000-8000-000000000004','effective',null),
('b6500000-0000-4000-8000-000000000006','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1375,'2026-08-25 10:00+07','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000006','b6000000-0000-4000-8000-000000000001','b6500000-0000-4000-8000-000000000004','voided','Duplicate'),
('b6500000-0000-4000-8000-000000000011','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',500,'2026-07-31 17:00+00','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000011','b6000000-0000-4000-8000-000000000001',null,'effective',null),
('b6500000-0000-4000-8000-000000000012','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',580,'2026-08-12 10:00+07','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000012','b6000000-0000-4000-8000-000000000001','b6500000-0000-4000-8000-000000000011','effective',null),
('b6500000-0000-4000-8000-000000000021','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000003','52000000-0000-0000-0000-000000000001',700,'2026-07-31 17:00+00','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000021','b6000000-0000-4000-8000-000000000001',null,'effective',null),
('b6500000-0000-4000-8000-000000000022','b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000003','52000000-0000-0000-0000-000000000001',770,'2026-08-15 10:00+07','b6000000-0000-4000-8000-000000000001','b6600000-0000-4000-8000-000000000022','b6000000-0000-4000-8000-000000000001','b6500000-0000-4000-8000-000000000021','effective',null);

insert into public.operational_incidents(id,account_id,branch_id,machine_id,occurred_at,category,incident_type,responsible_name_snapshot,material_loss,service_loss,description,status,client_request_id,created_by,updated_by) values
('b6700000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','2026-08-14 10:00+07','kualitas','human','Operator A',2000,500,'Machine incident','open','b6800000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000001'),
('b6700000-0000-4000-8000-000000000002','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001',null,'2026-08-14 11:00+07','bahan','test_print','Operator B',3000,0,'Branch incident','resolved','b6800000-0000-4000-8000-000000000002','b6000000-0000-4000-8000-000000000001','b6000000-0000-4000-8000-000000000001');

insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install,created_by) values
('b6900000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',1000,'2026-07-31 17:00+00','tracking_start',40000,40000,'b6000000-0000-4000-8000-000000000001');

insert into public.operational_people(id,account_id,name,code) values ('b6a00000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','Report PIC','RPT');
insert into public.inventory_locations(id,account_id,branch_id,code,name) values
('b6b00000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','RPT-WH','Report Warehouse'),
('b6b00000-0000-4000-8000-000000000002','b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000002','RPT-SBY','Surabaya Stock');
insert into public.inventory_items(id,account_id,sku,name,unit,minimum_stock) values ('b6c00000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','RPT-SKU','Report Toner','bottle',3);

set local role authenticated;
select set_config('request.jwt.claim.sub','b6000000-0000-4000-8000-000000000001',true);
select public.create_machine_selling_price('b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001',800,'2026-08-01 00:00+07','First','b6d00000-0000-4000-8000-000000000001');
select public.create_machine_selling_price('b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001',850,'2026-08-16 00:00+07','Second','b6d00000-0000-4000-8000-000000000002');
select public.replace_machine_component('b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','b6900000-0000-4000-8000-000000000001',1150,'2026-08-10 14:00+07','normal_eol','worn',true,'b6000000-0000-4000-8000-000000000001','Report Owner',null,'b6e00000-0000-4000-8000-000000000001','external_untracked',null,null,null,'Unknown external acquisition cost');
select public.adjust_inventory_stock_costed('b6100000-0000-4000-8000-000000000001','b6c00000-0000-4000-8000-000000000001','b6b00000-0000-4000-8000-000000000001',5,'2026-08-05 10:00+07','b6a00000-0000-4000-8000-000000000001','Opening count',null,'b6f00000-0000-4000-8000-000000000001',100000);
select public.adjust_inventory_stock('b6100000-0000-4000-8000-000000000001','b6c00000-0000-4000-8000-000000000001','b6b00000-0000-4000-8000-000000000001',-1,'2026-08-06 10:00+07','b6a00000-0000-4000-8000-000000000001','Physical correction',null,'b6f00000-0000-4000-8000-000000000002');
select * from public.transfer_inventory_stock('b6100000-0000-4000-8000-000000000001','b6c00000-0000-4000-8000-000000000001','b6b00000-0000-4000-8000-000000000001','b6b00000-0000-4000-8000-000000000002',1,'2026-08-07 10:00+07','b6a00000-0000-4000-8000-000000000001','Branch transfer','b6f00000-0000-4000-8000-000000000003');
insert into public.inventory_suppliers(id,account_id,supplier_code,name) values ('b7000000-0000-4000-8000-000000000001','b6100000-0000-4000-8000-000000000001','RPT-SUP','Report Supplier');
select public.create_inventory_purchase('b6100000-0000-4000-8000-000000000001','b7000000-0000-4000-8000-000000000001','RPT-PO-001','2026-08-08','EXT-001','IDR','Report purchase','[{"inventory_item_id":"b6c00000-0000-4000-8000-000000000001","quantity":"4","unit_price":"120000"}]'::jsonb,'b7100000-0000-4000-8000-000000000001');
select public.receive_inventory_purchase('b6100000-0000-4000-8000-000000000001',(select id from public.inventory_purchases where purchase_number='RPT-PO-001'),'b6b00000-0000-4000-8000-000000000001','2026-08-09 10:00+07','b6a00000-0000-4000-8000-000000000001','Partial report receipt',jsonb_build_array(jsonb_build_object('purchase_line_id',(select id from public.inventory_purchase_lines where purchase_id=(select id from public.inventory_purchases where purchase_number='RPT-PO-001')),'quantity','2')),'b7200000-0000-4000-8000-000000000001');

-- Machine performance, operational dates, correction/status exclusion, and branch aggregation.
select extensions.is((select total_clicks from public.get_report_machine_performance('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),350::numeric,'machine performance reconciles effective usage only');
select extensions.is((select active_days from public.get_report_machine_performance('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),2,'multiple readings on one operational date count one active day');
select extensions.is((select daily_average_clicks from public.get_report_machine_performance('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),175::numeric,'daily average uses active days without fabricated capacity');
select extensions.is((select latest_counter from public.get_report_machine_performance('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),1350::numeric,'latest counter respects the operational timezone boundary');
select extensions.is((select total_clicks from public.get_report_daily_clicks('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31') where operational_date='2026-08-10'),150::numeric,'daily trend sums multiple effective readings on a date');
select extensions.is((select count(*)::int from public.get_report_daily_clicks('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),2,'daily trend excludes voided and local out-of-period usage');
select extensions.is((select sum(total_clicks) from public.get_report_machine_performance('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31')),430::numeric,'branch aggregation includes only machines in the selected branch');
select extensions.is((select count(*)::int from public.get_report_machine_performance('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000002',null,'2026-08-01','2026-08-31')),1,'other same-account branch remains isolated by branch filter');

-- Economics remains a direct projection of M2.5C.
select extensions.is((select estimated_machine_revenue from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),290000::numeric,'two historical prices produce period-aware Estimated Machine Revenue');
select extensions.is((select period_price_count from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),2,'report exposes multiple historical price evidence');
select extensions.is((select error_waste_cost from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),2500::numeric,'machine economics includes exact-machine Error/Waste only');
select extensions.is((select standard_machine_cost from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),2500::numeric,'unknown component cost is not fabricated into Standard cost');
select extensions.is((select standard_contribution_status::text from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),'PARTIAL_COST','partial component evidence qualifies report contribution');
select extensions.is((select estimated_standard_contribution from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),287500::numeric,'Estimated Contribution subtracts Standard Machine Cost');
select extensions.ok((select contribution_margin_percent is not null and advanced_enabled=false and estimated_full_contribution is null from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),'Advanced OFF preserves Standard and omits Full economics');
select extensions.is((select revenue_status::text from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000002','2026-08-01','2026-08-31')),'NO_PRICE','no-price machine remains explicit');
select extensions.ok((select estimated_machine_revenue is null and estimated_standard_contribution is null from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000002','2026-08-01','2026-08-31')),'no price never fabricates revenue or contribution');
select extensions.is((select branch_only_error_waste from public.get_report_overview('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31')),3000::numeric,'branch overview exposes branch-only Error/Waste separately');
select extensions.is((select branch_only_error_waste from public.get_report_overview('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),0::numeric,'machine overview excludes branch-only Error/Waste');
select extensions.is((select report_status from public.get_report_overview('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31')),'PARTIAL_PRICE','overview completeness exposes no-price machines');
select extensions.is((select report_status from public.get_report_overview('b6100000-0000-4000-8000-000000000001',null,null,'2025-01-01','2025-01-31')),'NO_COUNTER_DATA','machines without period counter evidence remain explicitly incomplete');

select public.create_machine_operating_cost('b6100000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','electricity',10000,'one_time','Report advanced fixture','b7300000-0000-4000-8000-000000000001','2026-08-18 10:00+07',null,null,null,null,null,'manual');
select public.set_machine_economics_advanced_enabled('b6100000-0000-4000-8000-000000000001',true);
select extensions.ok((select advanced_enabled and full_machine_operating_cost=12500 and estimated_full_contribution=277500 from public.get_report_machine_economics('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),'Advanced ON exposes separate Full economics without changing Standard');

-- Component, incident, purchasing, receiving, movement, and current-stock projections.
select extensions.is((select replacement_count from public.get_report_component_consumption('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),1,'component report counts replacement evidence once');
select extensions.is((select unknown_cost_events from public.get_report_component_consumption('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),1,'component report keeps unknown acquisition evidence explicit');
select extensions.is((select average_observed_yield from public.get_report_component_consumption('b6100000-0000-4000-8000-000000000001',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),150::numeric,'component report reuses observed lifecycle yield');
select extensions.is((select count(*)::int from public.get_report_error_waste('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31',null,null)),2,'Error/Waste branch report includes machine and branch-only incidents');
select extensions.is((select count(*)::int from public.get_report_error_waste('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31',null,null)),1,'Error/Waste machine filter excludes branch-only incidents');
select extensions.is((select count(*)::int from public.get_report_error_waste('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31','bahan',null)),1,'Error/Waste category filter is authoritative');
select extensions.is((select count(*)::int from public.get_report_error_waste('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31',null,'resolved')),1,'Error/Waste status filter is authoritative');
select extensions.is((select purchases from public.get_report_inventory_activity('b6100000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31')),1,'inventory context reports purchases in period');
select extensions.is((select purchase_value from public.get_report_inventory_activity('b6100000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31')),480000::numeric,'purchase value remains acquisition context');
select extensions.is((select receipts from public.get_report_inventory_activity('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),1,'branch inventory context counts receipts at branch locations');
select extensions.is((select adjustments from public.get_report_inventory_activity('b6100000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31')),2,'inventory context reports opening/physical adjustments according to movement type');
select extensions.is((select transfer_legs from public.get_report_inventory_activity('b6100000-0000-4000-8000-000000000001',null,'2026-08-01','2026-08-31')),2,'inventory context reports both immutable transfer legs');
select extensions.is((select remaining_quantity from public.get_report_purchase_lines('b6100000-0000-4000-8000-000000000001','2026-08-01','2026-08-31') where purchase_number='RPT-PO-001'),2::numeric,'purchase report exposes partial receipt remaining quantity');
select extensions.is((select external_reference from public.get_report_purchase_lines('b6100000-0000-4000-8000-000000000001','2026-08-01','2026-08-31') where purchase_number='RPT-PO-001'),'EXT-001','purchase report exposes readable external reference');
select extensions.is((select total_stock from public.get_report_inventory_stock('b6100000-0000-4000-8000-000000000001',null) where inventory_item_id='b6c00000-0000-4000-8000-000000000001'),6::numeric,'account inventory report derives current movement balance');
select extensions.is((select total_stock from public.get_report_inventory_stock('b6100000-0000-4000-8000-000000000001','b6300000-0000-4000-8000-000000000002') where inventory_item_id='b6c00000-0000-4000-8000-000000000001'),1::numeric,'branch inventory report derives branch location balance only');
select extensions.ok((select jsonb_array_length(location_breakdown)=2 from public.get_report_inventory_stock('b6100000-0000-4000-8000-000000000001',null) where inventory_item_id='b6c00000-0000-4000-8000-000000000001'),'inventory report exposes readable location breakdown without raw top-level IDs');

-- All active operational roles read the same projection; suspended, cross-account, and anonymous callers are denied.
select set_config('request.jwt.claim.sub','b6000000-0000-4000-8000-000000000002',true);
select extensions.is((select total_clicks from public.get_report_overview('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31')),500::numeric,'Admin reads account report');
select set_config('request.jwt.claim.sub','b6000000-0000-4000-8000-000000000003',true);
select extensions.is((select total_clicks from public.get_report_overview('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31')),500::numeric,'Technician reads permitted operational report');
select set_config('request.jwt.claim.sub','b6000000-0000-4000-8000-000000000004',true);
select extensions.is((select total_clicks from public.get_report_overview('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31')),500::numeric,'Operator reads permitted operational report');
select set_config('request.jwt.claim.sub','b6000000-0000-4000-8000-000000000005',true);
select extensions.throws_ok($$select public.get_report_overview('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31')$$,'42501',null,'suspended membership is denied');
select set_config('request.jwt.claim.sub','b6000000-0000-4000-8000-000000000006',true);
select extensions.throws_ok($$select public.get_report_overview('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31')$$,'42501',null,'cross-account member is denied');
select extensions.throws_ok($$select public.get_report_machine_performance('b6100000-0000-4000-8000-000000000002',null,'b6400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')$$,'P0002',null,'machine outside requested account is denied');
reset role;
set local role anon;
select extensions.throws_ok($$select public.get_report_overview('b6100000-0000-4000-8000-000000000001',null,null,'2026-08-01','2026-08-31')$$,'42501',null,'anonymous caller is denied');
reset role;

select * from extensions.finish();
rollback;
