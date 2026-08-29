begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.has_table('public','account_operational_permissions','Settings has a dedicated operational policy table');
select extensions.has_table('public','settings_change_events','Settings changes retain audit evidence');
select extensions.ok(has_function_privilege('authenticated','public.manage_workspace_settings(uuid,text,text,uuid)','EXECUTE'),'authenticated reaches guarded workspace RPC');
select extensions.ok(has_function_privilege('authenticated','public.manage_settings_branch(uuid,uuid,text,text,text,text,text,text,uuid)','EXECUTE'),'authenticated reaches guarded branch RPC');
select extensions.ok(has_function_privilege('authenticated','public.manage_settings_membership(uuid,uuid,account_role,membership_status,uuid)','EXECUTE'),'authenticated reaches guarded membership RPC');
select extensions.ok(has_function_privilege('authenticated','public.manage_operational_permissions(uuid,boolean,boolean,boolean,boolean,boolean,boolean,boolean,uuid)','EXECUTE'),'authenticated reaches guarded permission RPC');
select extensions.ok(not has_function_privilege('anon','public.manage_workspace_settings(uuid,text,text,uuid)','EXECUTE'),'anonymous cannot reach workspace RPC');
select extensions.ok(not has_function_privilege('authenticated','public.set_operational_override(uuid,text)','EXECUTE'),'capability override helper is not client-callable');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('a7000000-0000-4000-8000-000000000001','authenticated','authenticated','owner-settings@example.com','x',now(),'{}','{"display_name":"Settings Owner"}',now(),now()),
('a7000000-0000-4000-8000-000000000002','authenticated','authenticated','admin-settings@example.com','x',now(),'{}','{"display_name":"Settings Admin"}',now(),now()),
('a7000000-0000-4000-8000-000000000003','authenticated','authenticated','operator-settings@example.com','x',now(),'{}','{"display_name":"Settings Operator"}',now(),now()),
('a7000000-0000-4000-8000-000000000004','authenticated','authenticated','tech-settings@example.com','x',now(),'{}','{"display_name":"Settings Technician"}',now(),now()),
('a7000000-0000-4000-8000-000000000005','authenticated','authenticated','other-settings@example.com','x',now(),'{}','{"display_name":"Other Owner"}',now(),now()),
('a7000000-0000-4000-8000-000000000006','authenticated','authenticated','second-owner@example.com','x',now(),'{}','{"display_name":"Second Owner"}',now(),now());

insert into public.accounts(id,code,name,default_timezone) values
('a7100000-0000-4000-8000-000000000001','M27A','Settings Workspace','Asia/Jakarta'),
('a7100000-0000-4000-8000-000000000002','M27B','Other Workspace','Asia/Jakarta');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('a7200000-0000-4000-8000-000000000001','a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000001','owner','active',now()),
('a7200000-0000-4000-8000-000000000002','a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000002','admin','active',now()),
('a7200000-0000-4000-8000-000000000003','a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000003','operator','active',now()),
('a7200000-0000-4000-8000-000000000004','a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000004','technician','active',now()),
('a7200000-0000-4000-8000-000000000005','a7100000-0000-4000-8000-000000000002','a7000000-0000-4000-8000-000000000005','owner','active',now()),
('a7200000-0000-4000-8000-000000000006','a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000006','owner','active',now());

select extensions.ok((select not operator_can_initialize_component and operator_can_replace_component and not operator_can_create_purchase and not operator_can_receive_goods and not operator_can_adjust_inventory and not operator_can_transfer_inventory and operator_can_log_errors from public.account_operational_permissions where account_id='a7100000-0000-4000-8000-000000000001'),'policy backfill preserves every accepted Operator default');

set local role authenticated;
select set_config('request.jwt.claims','{"sub":"a7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.is((public.manage_workspace_settings('a7100000-0000-4000-8000-000000000001',' Settings Renamed ','Asia/Makassar','a7300000-0000-4000-8000-000000000001')).name,'Settings Renamed','Owner updates trimmed workspace display name');
select extensions.is((public.manage_workspace_settings('a7100000-0000-4000-8000-000000000001',' Settings Renamed ','Asia/Makassar','a7300000-0000-4000-8000-000000000001')).default_timezone,'Asia/Makassar','same workspace request is idempotent');
select extensions.throws_ok($$select public.manage_workspace_settings('a7100000-0000-4000-8000-000000000001','Different','Asia/Makassar','a7300000-0000-4000-8000-000000000001')$$,'23505',null,'same request key with different payload is rejected');
select extensions.throws_ok($$select public.manage_workspace_settings('a7100000-0000-4000-8000-000000000001','Valid','UTC+7','a7300000-0000-4000-8000-000000000002')$$,'22023',null,'arbitrary UTC offsets are rejected');

select extensions.is((public.manage_settings_branch('a7100000-0000-4000-8000-000000000001',null,'create',' jkt2 ','Jakarta Two',null,null,null,'a7300000-0000-4000-8000-000000000003')).code,'JKT2','branch create normalizes code');
select extensions.is((select count(*)::integer from public.branches where account_id='a7100000-0000-4000-8000-000000000001' and code='JKT2'),1,'retry-safe branch create produces one branch');
select extensions.throws_ok($$select public.manage_settings_branch('a7100000-0000-4000-8000-000000000001',null,'create','JKT2','Duplicate',null,null,null,'a7300000-0000-4000-8000-000000000004')$$,'23505',null,'duplicate normalized branch code is rejected');

reset role;
select id as settings_branch_id from public.branches where account_id='a7100000-0000-4000-8000-000000000001' and code='JKT2' \gset
set local role authenticated;
select set_config('request.jwt.claims','{"sub":"a7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.ok(not (public.manage_settings_branch('a7100000-0000-4000-8000-000000000001',:'settings_branch_id','archive','JKT2','Jakarta Two',null,null,null,'a7300000-0000-4000-8000-000000000005')).is_active,'branch archives without deletion');
select extensions.ok((public.manage_settings_branch('a7100000-0000-4000-8000-000000000001',:'settings_branch_id','restore','JKT2','Jakarta Two',null,null,null,'a7300000-0000-4000-8000-000000000006')).is_active,'archived branch restores safely');

select extensions.is((public.manage_settings_membership('a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000003','technician','active','a7300000-0000-4000-8000-000000000007')).role::text,'technician','Owner changes a non-owner role');
select extensions.is((public.manage_settings_membership('a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000003','technician','suspended','a7300000-0000-4000-8000-000000000008')).status::text,'suspended','Owner suspends a member without deleting membership');
select extensions.is((public.manage_settings_membership('a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000003','technician','active','a7300000-0000-4000-8000-000000000009')).status::text,'active','Owner reactivates a member');
select extensions.is((public.manage_settings_membership('a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000003','operator','active','a7300000-0000-4000-8000-000000000014')).role::text,'operator','Owner restores the Operator fixture role');

select extensions.is((public.manage_operational_permissions('a7100000-0000-4000-8000-000000000001',true,false,true,true,true,true,false,'a7300000-0000-4000-8000-000000000010')).operator_can_create_purchase,true,'Owner updates all operational flags atomically');

select set_config('request.jwt.claims','{"sub":"a7000000-0000-4000-8000-000000000003","role":"authenticated"}',true);
select extensions.ok(public.has_operational_capability('a7100000-0000-4000-8000-000000000001','initialize_component'),'Operator is allowed when policy is true');
select extensions.ok(not public.has_operational_capability('a7100000-0000-4000-8000-000000000001','replace_component'),'Operator is denied when policy is false');
select extensions.ok(public.has_operational_capability('a7100000-0000-4000-8000-000000000001','create_purchase'),'purchase capability resolves from policy');
select extensions.ok(public.has_operational_capability('a7100000-0000-4000-8000-000000000001','receive_goods'),'receiving capability resolves from policy');
select extensions.ok(public.has_operational_capability('a7100000-0000-4000-8000-000000000001','adjust_inventory'),'adjustment capability resolves from policy');
select extensions.ok(public.has_operational_capability('a7100000-0000-4000-8000-000000000001','transfer_inventory'),'transfer capability resolves from policy');
select extensions.ok(not public.has_operational_capability('a7100000-0000-4000-8000-000000000001','log_errors'),'Error logging is denied when policy false');

select set_config('request.jwt.claims','{"sub":"a7000000-0000-4000-8000-000000000004","role":"authenticated"}',true);
select extensions.ok(public.has_operational_capability('a7100000-0000-4000-8000-000000000001','replace_component'),'Technician replacement remains fixed on');
select extensions.ok(not public.has_operational_capability('a7100000-0000-4000-8000-000000000001','create_purchase'),'Technician purchasing remains fixed off');

select set_config('request.jwt.claims','{"sub":"a7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.throws_ok($$select public.manage_settings_membership('a7100000-0000-4000-8000-000000000001','a7000000-0000-4000-8000-000000000001','admin','active','a7300000-0000-4000-8000-000000000011')$$,'42501',null,'Admin cannot modify an Owner');
select extensions.throws_ok($$select public.manage_workspace_settings('a7100000-0000-4000-8000-000000000002','Cross account','Asia/Jakarta','a7300000-0000-4000-8000-000000000012')$$,'42501',null,'cross-account administration is denied');

select set_config('request.jwt.claims','{"sub":"a7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.is((select count(*)::integer from public.get_settings_members('a7100000-0000-4000-8000-000000000001') where email is not null),5,'member directory exposes scoped email only to Settings admins');
select extensions.ok((select count(*)>=8 from public.settings_change_events where account_id='a7100000-0000-4000-8000-000000000001'),'administrative mutations retain actor-snapshot audit evidence');

select extensions.is(public.manage_advanced_economics_setting('a7100000-0000-4000-8000-000000000001',true,'a7300000-0000-4000-8000-000000000013'),true,'Advanced Economics is managed through its existing policy contract');
select extensions.is((select count(*)::integer from public.machine_operating_costs where account_id='a7100000-0000-4000-8000-000000000001'),0,'enabling Advanced Economics creates no operating-cost rows');

reset role;
set local role anon;
select extensions.throws_ok($$select public.get_settings_members('a7100000-0000-4000-8000-000000000001')$$,'42501',null,'anonymous member directory access is denied by function privilege');
reset role;

select * from extensions.finish();
rollback;
