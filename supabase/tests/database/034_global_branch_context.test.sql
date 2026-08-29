begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.has_column('public','inventory_purchases','branch_id','Purchase owns an operational Branch');
select extensions.ok(exists(
  select 1
  from pg_catalog.pg_constraint constraint_record
  join pg_catalog.pg_class relation on relation.oid = constraint_record.conrelid
  join pg_catalog.pg_namespace namespace on namespace.oid = relation.relnamespace
  where namespace.nspname = 'public'
    and relation.relname = 'inventory_purchases'
    and constraint_record.conname = 'inventory_purchases_branch_account_fkey'
    and constraint_record.contype = 'f'
), 'Purchase Branch is account-consistent');
select extensions.ok(not has_function_privilege('authenticated','public.create_inventory_purchase(uuid,uuid,text,date,text,text,text,jsonb,uuid)','EXECUTE'),
  'legacy account-wide purchase entry point is not callable');
select extensions.ok(has_function_privilege('authenticated','public.create_inventory_purchase_auto(uuid,uuid,uuid,date,text,text,text,jsonb,uuid)','EXECUTE'),
  'Branch-scoped purchase entry point is callable');
select extensions.ok(not has_function_privilege('authenticated','public.get_report_purchase_lines(uuid,date,date)','EXECUTE')
  and has_function_privilege('authenticated','public.get_report_purchase_lines(uuid,uuid,date,date)','EXECUTE'),
  'purchase report requires an explicit Branch parameter');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('c7000000-0000-4000-8000-000000000001','authenticated','authenticated','owner-m27c@test.invalid','',now(),'{}','{"display_name":"M27C Owner"}',now(),now()),
('c7000000-0000-4000-8000-000000000002','authenticated','authenticated','admin-m27c@test.invalid','',now(),'{}','{"display_name":"M27C Admin"}',now(),now()),
('c7000000-0000-4000-8000-000000000003','authenticated','authenticated','tech-m27c@test.invalid','',now(),'{}','{"display_name":"M27C Technician"}',now(),now()),
('c7000000-0000-4000-8000-000000000004','authenticated','authenticated','operator-m27c@test.invalid','',now(),'{}','{"display_name":"M27C Operator"}',now(),now()),
('c7000000-0000-4000-8000-000000000005','authenticated','authenticated','suspended-m27c@test.invalid','',now(),'{}','{"display_name":"M27C Suspended"}',now(),now()),
('c7000000-0000-4000-8000-000000000006','authenticated','authenticated','platform-m27c@test.invalid','',now(),'{}','{"display_name":"M27C Platform"}',now(),now()),
('c7000000-0000-4000-8000-000000000007','authenticated','authenticated','other-m27c@test.invalid','',now(),'{}','{"display_name":"M27C Other"}',now(),now());
insert into public.accounts(id,code,name) values
('c7100000-0000-4000-8000-000000000001','M27C-A','M27C Workspace'),
('c7100000-0000-4000-8000-000000000002','M27C-B','M27C Other Workspace');
insert into public.branches(id,account_id,code,name) values
('c7200000-0000-4000-8000-000000000001','c7100000-0000-4000-8000-000000000001','TUP','Tuparev'),
('c7200000-0000-4000-8000-000000000002','c7100000-0000-4000-8000-000000000001','GRH','Graha'),
('c7200000-0000-4000-8000-000000000003','c7100000-0000-4000-8000-000000000002','OTH','Other'),
('c7200000-0000-4000-8000-000000000004','c7100000-0000-4000-8000-000000000001','RST','Restricted');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('c7300000-0000-4000-8000-000000000001','c7100000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000001','owner','active',now()),
('c7300000-0000-4000-8000-000000000002','c7100000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000002','admin','active',now()),
('c7300000-0000-4000-8000-000000000003','c7100000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000003','technician','active',now()),
('c7300000-0000-4000-8000-000000000004','c7100000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000004','operator','active',now()),
('c7300000-0000-4000-8000-000000000005','c7100000-0000-4000-8000-000000000001','c7000000-0000-4000-8000-000000000005','admin','suspended',now()),
('c7300000-0000-4000-8000-000000000007','c7100000-0000-4000-8000-000000000002','c7000000-0000-4000-8000-000000000007','owner','active',now());
insert into public.account_membership_branches(account_id,membership_id,branch_id) values
('c7100000-0000-4000-8000-000000000001','c7300000-0000-4000-8000-000000000002','c7200000-0000-4000-8000-000000000001'),
('c7100000-0000-4000-8000-000000000001','c7300000-0000-4000-8000-000000000003','c7200000-0000-4000-8000-000000000001'),
('c7100000-0000-4000-8000-000000000001','c7300000-0000-4000-8000-000000000004','c7200000-0000-4000-8000-000000000001');
insert into public.platform_user_privileges(user_id,role) values('c7000000-0000-4000-8000-000000000006','superuser');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('c7400000-0000-4000-8000-000000000001','c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M27C-TUP-01','Tuparev Machine');
insert into public.operational_people(id,account_id,name) values
('c7500000-0000-4000-8000-000000000001','c7100000-0000-4000-8000-000000000001','M27C PIC');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id) values
('c7100000-0000-4000-8000-000000000001','c7500000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001');
insert into public.inventory_locations(id,account_id,branch_id,code,name) values
('c7600000-0000-4000-8000-000000000001','c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001','TUP-WH','Tuparev Warehouse'),
('c7600000-0000-4000-8000-000000000002','c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000004','RST-WH','Restricted Warehouse');
insert into public.inventory_items(id,account_id,sku,name,unit) values
('c7700000-0000-4000-8000-000000000001','c7100000-0000-4000-8000-000000000001','M27C-ITEM','Shared Item','pcs');
insert into public.inventory_suppliers(id,account_id,supplier_code,name) values
('c7800000-0000-4000-8000-000000000001','c7100000-0000-4000-8000-000000000001','M27C-SUP','M27C Supplier');

set local role authenticated;
select set_config('request.jwt.claim.sub','c7000000-0000-4000-8000-000000000001',true);
select extensions.ok(not public.can_manage_account_governance('c7100000-0000-4000-8000-000000000001'),'Owner is not Platform Settings authority');
select extensions.throws_ok($$select public.manage_workspace_settings('c7100000-0000-4000-8000-000000000001','Owner denied','Asia/Jakarta','c7900000-0000-4000-8000-000000000001')$$,
  '42501',null,'Owner Settings mutation is denied');
select extensions.throws_ok($$select * from public.get_report_overview('c7100000-0000-4000-8000-000000000001',null,null,current_date,current_date)$$,
  '22023',null,'Owner cannot request All-Branches operational Reports');
select extensions.lives_ok($$select public.create_inventory_purchase_auto(
  'c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001','c7800000-0000-4000-8000-000000000001',current_date,null,'IDR',null,
  '[{"inventory_item_id":"c7700000-0000-4000-8000-000000000001","quantity":"1","unit_price":"100"}]','c7900000-0000-4000-8000-000000000002')$$,
  'Owner creates a purchase only with explicit Tuparev ownership');
select extensions.is((select branch_id from public.inventory_purchases where client_request_id='c7900000-0000-4000-8000-000000000002'),
  'c7200000-0000-4000-8000-000000000001'::uuid,'Purchase stores selected Branch');
select extensions.lives_ok($$select public.initialize_inventory_stock_costed(
  'c7100000-0000-4000-8000-000000000001','c7700000-0000-4000-8000-000000000001','c7600000-0000-4000-8000-000000000001',3,statement_timestamp(),
  'c7500000-0000-4000-8000-000000000001',null,'c7900000-0000-4000-8000-000000000003',100)$$,'Tuparev opening stock fixture posts');
select extensions.is((select active_machines from public.get_report_overview('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001',null,current_date,current_date)),1,'Tuparev report sees its Machine');
select extensions.is((select active_machines from public.get_report_overview('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000002',null,current_date,current_date)),0,'empty Graha report does not fall back to Tuparev Machine');
select extensions.is((select total_clicks from public.get_report_overview('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000002',null,current_date,current_date)),0::numeric,'empty Graha report has zero clicks');
select extensions.is((select count(*)::integer from public.get_report_purchase_lines('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000002',current_date,current_date)),0,'Graha purchase report excludes Tuparev Purchase');
select extensions.is((select total_stock from public.get_report_inventory_stock('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000002') where inventory_item_id='c7700000-0000-4000-8000-000000000001'),0::numeric,'Graha stock keeps account Item master with zero physical quantity');
select extensions.is((select total_stock from public.get_report_inventory_stock('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001') where inventory_item_id='c7700000-0000-4000-8000-000000000001'),3::numeric,'Tuparev stock remains unchanged');

select set_config('request.jwt.claim.sub','c7000000-0000-4000-8000-000000000002',true);
select extensions.ok(not public.can_manage_account_governance('c7100000-0000-4000-8000-000000000001'),'Admin is not Platform Settings authority');
select extensions.is((select count(*)::integer from public.machines where account_id='c7100000-0000-4000-8000-000000000001'),1,'Admin reads assigned-Branch Machine only');
select extensions.is((select count(*)::integer from public.inventory_locations where account_id='c7100000-0000-4000-8000-000000000001'),1,'Admin reads assigned-Branch Location only');
select extensions.throws_ok($$select public.create_inventory_purchase_auto(
  'c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000002','c7800000-0000-4000-8000-000000000001',current_date,null,'IDR',null,
  '[{"inventory_item_id":"c7700000-0000-4000-8000-000000000001","quantity":"1","unit_price":"100"}]','c7900000-0000-4000-8000-000000000004')$$,
  '42501',null,'Admin cannot tamper with an unassigned Purchase Branch');
select extensions.throws_ok($$insert into public.inventory_locations(account_id,branch_id,code,name) values(
  'c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000002','DENIED','Denied Graha Location')$$,
  '42501',null,'Admin cannot create a Location in an unassigned Branch');
select extensions.throws_ok($$select public.adjust_inventory_stock(
  'c7100000-0000-4000-8000-000000000001','c7700000-0000-4000-8000-000000000001','c7600000-0000-4000-8000-000000000002',1,statement_timestamp(),
  'c7500000-0000-4000-8000-000000000001','Cross-Branch adjustment',null,'c7900000-0000-4000-8000-000000000007')$$,
  '42501',null,'Admin cannot adjust stock through an unassigned Location');
select extensions.throws_ok($$select * from public.transfer_inventory_stock(
  'c7100000-0000-4000-8000-000000000001','c7700000-0000-4000-8000-000000000001','c7600000-0000-4000-8000-000000000001','c7600000-0000-4000-8000-000000000002',1,statement_timestamp(),
  'c7500000-0000-4000-8000-000000000001',null,'c7900000-0000-4000-8000-000000000008')$$,
  '42501',null,'Admin cannot transfer stock into an unassigned Branch');
select extensions.throws_ok($$select public.manage_workspace_settings('c7100000-0000-4000-8000-000000000001','Admin denied','Asia/Jakarta','c7900000-0000-4000-8000-000000000005')$$,
  '42501',null,'Admin Settings mutation is denied');

select set_config('request.jwt.claim.sub','c7000000-0000-4000-8000-000000000003',true);
select extensions.ok(not public.can_manage_account_governance('c7100000-0000-4000-8000-000000000001'),'Technician Settings is denied');
select set_config('request.jwt.claim.sub','c7000000-0000-4000-8000-000000000004',true);
select extensions.ok(not public.can_manage_account_governance('c7100000-0000-4000-8000-000000000001'),'Operator Settings is denied');
select set_config('request.jwt.claim.sub','c7000000-0000-4000-8000-000000000005',true);
select extensions.throws_ok($$select public.get_report_overview('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001',null,current_date,current_date)$$,
  '42501',null,'suspended member cannot read selected-Branch Reports');
select set_config('request.jwt.claim.sub','c7000000-0000-4000-8000-000000000007',true);
select extensions.throws_ok($$select public.get_report_overview('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000001',null,current_date,current_date)$$,
  '42501',null,'cross-account caller cannot read selected-Branch Reports');

select set_config('request.jwt.claim.sub','c7000000-0000-4000-8000-000000000006',true);
select extensions.ok(public.can_manage_account_governance('c7100000-0000-4000-8000-000000000001'),'explicit Platform Superuser is Settings authority');
select extensions.lives_ok($$select public.manage_workspace_settings('c7100000-0000-4000-8000-000000000001','Platform managed','Asia/Jakarta','c7900000-0000-4000-8000-000000000006')$$,
  'Platform Superuser can mutate Settings');
select extensions.throws_ok($$select public.get_report_overview('c7100000-0000-4000-8000-000000000001',null,null,current_date,current_date)$$,
  '22023',null,'Platform Superuser still cannot aggregate All Branches in normal Reports');
select extensions.is((select active_machines from public.get_report_overview('c7100000-0000-4000-8000-000000000001','c7200000-0000-4000-8000-000000000002',null,current_date,current_date)),0,'Platform Superuser selected Graha projection stays empty');

select * from extensions.finish();
rollback;
