begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.has_table('public','platform_user_privileges','platform privilege is separate from tenant membership');
select extensions.has_table('public','account_membership_branches','membership Branch scope is normalized');
select extensions.has_table('public','operational_person_branches','PIC Branch scope is normalized');
select extensions.has_column('public','profiles','username_normalized','profile owns normalized username');
select extensions.ok(not has_function_privilege('anon','public.get_settings_members(uuid)','EXECUTE'),'anonymous cannot enumerate members');
select extensions.ok(not has_function_privilege('anon','public.resolve_login_username(text)','EXECUTE')
  and not has_function_privilege('authenticated','public.resolve_login_username(text)','EXECUTE')
  and has_function_privilege('service_role','public.resolve_login_username(text)','EXECUTE'),
  'only the server Auth boundary can resolve username to Auth identity');
select extensions.ok(not has_function_privilege('anon','public.can_access_branch(uuid,uuid)','EXECUTE'),'anonymous cannot call Branch resolver');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('b7000000-0000-4000-8000-000000000001','authenticated','authenticated','owner-m27b@example.com','x',now(),'{}','{"display_name":"Owner M27B"}',now(),now()),
('b7000000-0000-4000-8000-000000000002','authenticated','authenticated','admin-m27b@example.com','x',now(),'{}','{"display_name":"Admin M27B"}',now(),now()),
('b7000000-0000-4000-8000-000000000003','authenticated','authenticated','tech-m27b@example.com','x',now(),'{}','{"display_name":"Tech M27B"}',now(),now()),
('b7000000-0000-4000-8000-000000000004','authenticated','authenticated','operator-m27b@example.com','x',now(),'{}','{"display_name":"Operator M27B"}',now(),now()),
('b7000000-0000-4000-8000-000000000005','authenticated','authenticated','platform-m27b@example.com','x',now(),'{}','{"display_name":"Platform M27B"}',now(),now());
insert into public.accounts(id,code,name) values
('b7100000-0000-4000-8000-000000000001','M27B-A','M27B Workspace'),
('b7100000-0000-4000-8000-000000000002','M27B-B','Other Workspace');
insert into public.branches(id,account_id,code,name) values
('b7200000-0000-4000-8000-000000000001','b7100000-0000-4000-8000-000000000001','TUP','Tuparev'),
('b7200000-0000-4000-8000-000000000002','b7100000-0000-4000-8000-000000000001','CIA','Cianjur'),
('b7200000-0000-4000-8000-000000000003','b7100000-0000-4000-8000-000000000002','OTH','Other Branch');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('b7300000-0000-4000-8000-000000000001','b7100000-0000-4000-8000-000000000001','b7000000-0000-4000-8000-000000000001','owner','active',now()),
('b7300000-0000-4000-8000-000000000002','b7100000-0000-4000-8000-000000000001','b7000000-0000-4000-8000-000000000002','admin','active',now()),
('b7300000-0000-4000-8000-000000000003','b7100000-0000-4000-8000-000000000001','b7000000-0000-4000-8000-000000000003','technician','active',now()),
('b7300000-0000-4000-8000-000000000004','b7100000-0000-4000-8000-000000000001','b7000000-0000-4000-8000-000000000004','operator','active',now());
insert into public.account_membership_branches(account_id,membership_id,branch_id) values
('b7100000-0000-4000-8000-000000000001','b7300000-0000-4000-8000-000000000002','b7200000-0000-4000-8000-000000000001'),
('b7100000-0000-4000-8000-000000000001','b7300000-0000-4000-8000-000000000003','b7200000-0000-4000-8000-000000000001'),
('b7100000-0000-4000-8000-000000000001','b7300000-0000-4000-8000-000000000004','b7200000-0000-4000-8000-000000000001');

select extensions.lives_ok($$update public.profiles set username=' Admin.Tuparev ' where user_id='b7000000-0000-4000-8000-000000000002'$$,'username is trimmed and accepted');
select extensions.is((select username from public.profiles where user_id='b7000000-0000-4000-8000-000000000002'),'admin.tuparev','username canonicalizes to lowercase');
select extensions.throws_ok($$update public.profiles set username='ADMIN.TUPAREV' where user_id='b7000000-0000-4000-8000-000000000003'$$,'23505',null,'case-insensitive duplicate is rejected');
select extensions.throws_ok($$update public.profiles set username='a b' where user_id='b7000000-0000-4000-8000-000000000003'$$,'22023',null,'spaces are rejected');
select extensions.throws_ok($$update public.profiles set username='ab' where user_id='b7000000-0000-4000-8000-000000000003'$$,'22023',null,'too-short username is rejected');
select extensions.throws_ok($$update public.profiles set username=repeat('a',33) where user_id='b7000000-0000-4000-8000-000000000003'$$,'22023',null,'too-long username is rejected');
select extensions.throws_ok($$update public.profiles set username='root' where user_id='b7000000-0000-4000-8000-000000000003'$$,'22023',null,'reserved username is rejected');
select extensions.ok((select username is null from public.profiles where user_id='b7000000-0000-4000-8000-000000000004'),'legacy NULL username remains supported');

set local role authenticated;
select set_config('request.jwt.claims','{"sub":"b7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.ok(public.can_manage_account_governance('b7100000-0000-4000-8000-000000000001'),'Owner manages governance');
select extensions.ok(public.can_access_branch('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002'),'Owner has implicit all-Branch scope');
select extensions.lives_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002','51000000-0000-0000-0000-000000000001','M27B-OWNER-CIA','Owner Cianjur Machine')$$,
  'Owner can create a Machine in any own-account Branch');
select extensions.throws_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('b7100000-0000-4000-8000-000000000002','b7200000-0000-4000-8000-000000000003','51000000-0000-0000-0000-000000000001','M27B-OWNER-CROSS','Cross-account Machine')$$,
  '42501',null,'Owner cannot create a Machine in another Account');
select set_config('request.jwt.claims','{"sub":"b7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.ok(not public.can_manage_account_governance('b7100000-0000-4000-8000-000000000001'),'Admin cannot manage governance');
select extensions.ok(public.can_access_branch('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001'),'Admin reaches assigned Tuparev');
select extensions.ok(not public.can_access_branch('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002'),'Admin cannot reach Cianjur');
select extensions.lives_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M27B-TUP','Authorized Tuparev Machine')$$,
  'Admin can create a Machine in an assigned Branch');
select extensions.throws_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002','51000000-0000-0000-0000-000000000001','M27B-CIA-DENIED','Denied Cianjur Machine')$$,
  '42501',null,'Admin cannot create a Machine in an unassigned Branch');
select extensions.throws_ok($$select public.manage_workspace_settings('b7100000-0000-4000-8000-000000000001','Nope','Asia/Jakarta','b7900000-0000-4000-8000-000000000001')$$,'42501',null,'Admin is denied workspace governance');
select extensions.throws_ok($$select * from public.resolve_operational_report_scope('b7100000-0000-4000-8000-000000000001',null,null,current_date,current_date)$$,'42501',null,'scoped Admin cannot request all-Branch report');

select set_config('request.jwt.claims','{"sub":"b7000000-0000-4000-8000-000000000003","role":"authenticated"}',true);
select extensions.ok(public.can_access_branch('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001'),'Technician reaches an assigned Branch');
select extensions.throws_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M27B-TECH-DENIED','Denied Technician Machine')$$,
  '42501',null,'Technician Branch access does not grant Machine administration');
select set_config('request.jwt.claims','{"sub":"b7000000-0000-4000-8000-000000000004","role":"authenticated"}',true);
select extensions.ok(public.can_access_branch('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001'),'Operator reaches an assigned Branch');
select extensions.ok(not public.can_access_branch('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002'),'Operator cannot reach an unassigned Branch');
select extensions.throws_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M27B-OP-DENIED','Denied Operator Machine')$$,
  '42501',null,'Operator Branch access does not grant Machine administration');

reset role;
insert into public.operational_people(id,account_id,name) values
('b7400000-0000-4000-8000-000000000001','b7100000-0000-4000-8000-000000000001','Akmal Fauzan');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id) values
('b7100000-0000-4000-8000-000000000001','b7400000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001');
set local role authenticated;
select set_config('request.jwt.claims','{"sub":"b7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.ok(public.is_operational_person_valid_for_branch('b7100000-0000-4000-8000-000000000001','b7400000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001'),'PIC is valid in assigned Branch');
select extensions.ok(not public.is_operational_person_valid_for_branch('b7100000-0000-4000-8000-000000000001','b7400000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002'),'PIC is invalid in unassigned Branch');
select extensions.lives_ok($$select public.create_operational_incident(
  'b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000001',statement_timestamp(),
  'prosedur','human','Assigned-Branch PIC fixture','b7900000-0000-4000-8000-000000000010',null,null,null,null,null,
  'b7400000-0000-4000-8000-000000000001','Akmal Fauzan',0,0,null,null,null)$$,
  'Owner can log an incident with a PIC assigned to its Branch');
select extensions.throws_ok($$select public.create_operational_incident(
  'b7100000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002',statement_timestamp(),
  'prosedur','human','Wrong-Branch PIC fixture','b7900000-0000-4000-8000-000000000011',null,null,null,null,null,
  'b7400000-0000-4000-8000-000000000001','Akmal Fauzan',0,0,null,null,null)$$,
  '23514',null,'Owner all-Branch access does not bypass PIC Branch assignment');
select public.manage_operational_person_branches('b7100000-0000-4000-8000-000000000001','b7400000-0000-4000-8000-000000000001',array['b7200000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002']::uuid[],'b7900000-0000-4000-8000-000000000002');
select extensions.ok(public.is_operational_person_valid_for_branch('b7100000-0000-4000-8000-000000000001','b7400000-0000-4000-8000-000000000001','b7200000-0000-4000-8000-000000000002'),'one PIC supports multiple Branches without duplication');
select extensions.is((select count(*)::integer from public.operational_people where id='b7400000-0000-4000-8000-000000000001'),1,'multi-Branch PIC remains one person');
select extensions.ok(exists(select 1 from public.settings_change_events where client_request_id='b7900000-0000-4000-8000-000000000002' and action='operational_person.branches'),'PIC assignment creates audit evidence');

reset role;
insert into public.platform_user_privileges(user_id,role) values('b7000000-0000-4000-8000-000000000005','superuser');
set local role authenticated;
select set_config('request.jwt.claims','{"sub":"b7000000-0000-4000-8000-000000000005","role":"authenticated"}',true);
select extensions.ok(public.is_platform_superuser(),'platform Superuser resolves independently');
select extensions.ok(public.can_access_branch('b7100000-0000-4000-8000-000000000002','b7200000-0000-4000-8000-000000000003'),'platform Superuser reaches explicitly entered tenant context');
select extensions.lives_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name)
  values('b7100000-0000-4000-8000-000000000002','b7200000-0000-4000-8000-000000000003','51000000-0000-0000-0000-000000000001','M27B-PLATFORM','Platform Managed Machine')$$,
  'platform Superuser can administer a Machine in explicit tenant context');

select * from extensions.finish();
rollback;
