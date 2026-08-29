begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.has_table('public','identity_change_events','identity changes retain safe audit evidence');
select extensions.has_column('public','member_provisioning_requests','operation','provisioning requests distinguish direct create and activation');
select extensions.ok(not has_function_privilege('anon','public.prepare_direct_member_provisioning(uuid,uuid,text,text,text,account_role,uuid[],text,uuid)','EXECUTE'),
  'anonymous cannot prepare direct provisioning');
select extensions.ok(not has_function_privilege('authenticated','public.bootstrap_platform_superuser(uuid,uuid,text)','EXECUTE')
  and has_function_privilege('service_role','public.bootstrap_platform_superuser(uuid,uuid,text)','EXECUTE'),
  'bootstrap is service-role-only');
select extensions.ok(not has_table_privilege('authenticated','public.platform_user_privileges','INSERT')
  and not has_table_privilege('authenticated','public.platform_user_privileges','UPDATE'),
  'normal members cannot mutate platform privilege rows');
select extensions.ok(not has_function_privilege('authenticated','public.record_identity_auth_change(uuid,uuid,text,uuid,jsonb,jsonb,jsonb)','EXECUTE'),
  'normal members cannot forge Auth identity audit evidence');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('d7000000-0000-4000-8000-000000000001','authenticated','authenticated','owner-m27d@test.invalid','x',now(),'{}','{"display_name":"M27D Owner"}',now(),now()),
('d7000000-0000-4000-8000-000000000002','authenticated','authenticated','platform-m27d@test.invalid','x',now(),'{}','{"display_name":"M27D Platform"}',now(),now()),
('d7000000-0000-4000-8000-000000000003','authenticated','authenticated','invited-m27d@test.invalid','x',null,'{}','{"display_name":"M27D Invited"}',now(),now()),
('d7000000-0000-4000-8000-000000000004','authenticated','authenticated','other-m27d@test.invalid','x',now(),'{}','{"display_name":"M27D Other"}',now(),now());
insert into public.accounts(id,code,name) values
('d7100000-0000-4000-8000-000000000001','M27D-A','M27D Workspace'),
('d7100000-0000-4000-8000-000000000002','M27D-B','M27D Other');
insert into public.branches(id,account_id,code,name) values
('d7200000-0000-4000-8000-000000000001','d7100000-0000-4000-8000-000000000001','TUP','Tuparev'),
('d7200000-0000-4000-8000-000000000002','d7100000-0000-4000-8000-000000000001','GRH','Graha'),
('d7200000-0000-4000-8000-000000000003','d7100000-0000-4000-8000-000000000002','OTH','Other');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('d7300000-0000-4000-8000-000000000001','d7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000001','owner','active',now()),
('d7300000-0000-4000-8000-000000000003','d7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','admin','invited',null),
('d7300000-0000-4000-8000-000000000004','d7100000-0000-4000-8000-000000000002','d7000000-0000-4000-8000-000000000004','owner','active',now());
insert into public.account_membership_branches(account_id,membership_id,branch_id) values
('d7100000-0000-4000-8000-000000000001','d7300000-0000-4000-8000-000000000003','d7200000-0000-4000-8000-000000000002');
update public.profiles set username='platform.old' where user_id='d7000000-0000-4000-8000-000000000002';

set local role authenticated;
select set_config('request.jwt.claims','{"sub":"d7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.ok(not public.is_platform_superuser(),'Owner alone is not Platform Superuser');
select extensions.throws_ok($$select public.prepare_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001',null,'new-m27d@test.invalid','New M27D','new.m27d','admin',
  array['d7200000-0000-4000-8000-000000000002']::uuid[],'direct_create','d7900000-0000-4000-8000-000000000001')$$,
  '42501',null,'Owner without platform privilege cannot provision');
select extensions.throws_ok($$insert into public.platform_user_privileges(user_id,role) values('d7000000-0000-4000-8000-000000000001','superuser')$$,
  '42501',null,'authenticated Owner cannot self-promote');

reset role;
set local role service_role;
select extensions.is((public.bootstrap_platform_superuser(
  'd7000000-0000-4000-8000-000000000002','d7000000-0000-4000-8000-000000000002','pgTAP explicit bootstrap')).role::text,
  'superuser','explicit UUID bootstrap grants the established privilege');
select extensions.is((public.bootstrap_platform_superuser(
  'd7000000-0000-4000-8000-000000000002','d7000000-0000-4000-8000-000000000002','pgTAP explicit bootstrap')).role::text,
  'superuser','bootstrap retry is safe');
reset role;

set local role authenticated;
select set_config('request.jwt.claims','{"sub":"d7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.ok(public.is_platform_superuser(),'explicit privilege enables Platform Superuser authority');
select extensions.ok(public.can_manage_account_governance('d7100000-0000-4000-8000-000000000001'),'explicit privilege enables Settings governance');

select extensions.is((public.prepare_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001',null,'new-m27d@test.invalid','New M27D','new.m27d','admin',
  array['d7200000-0000-4000-8000-000000000002','d7200000-0000-4000-8000-000000000002']::uuid[],'direct_create','d7900000-0000-4000-8000-000000000002')).operation,
  'direct_create','Platform Superuser prepares Direct Active creation with canonical Branches');
select extensions.is(cardinality((select branch_ids from public.member_provisioning_requests
  where client_request_id='d7900000-0000-4000-8000-000000000002')),1,'duplicate Branch assignment is canonicalized');
select extensions.is((public.prepare_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001',null,'new-m27d@test.invalid','New M27D','new.m27d','admin',
  array['d7200000-0000-4000-8000-000000000002']::uuid[],'direct_create','d7900000-0000-4000-8000-000000000002')).operation,
  'direct_create','identical provisioning retry returns the same reservation');
select extensions.throws_ok($$select public.prepare_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001',null,'changed-m27d@test.invalid','New M27D','new.m27d','admin',
  array['d7200000-0000-4000-8000-000000000002']::uuid[],'direct_create','d7900000-0000-4000-8000-000000000002')$$,
  '23505',null,'same request ID with changed payload is rejected');

reset role;
insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('d7000000-0000-4000-8000-000000000005','authenticated','authenticated','new-m27d@test.invalid','x',now(),'{}','{"display_name":"New M27D"}',now(),now());
set local role authenticated;
select set_config('request.jwt.claims','{"sub":"d7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.is((public.finalize_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000005','new-m27d@test.invalid',
  'New M27D','new.m27d','admin',array['d7200000-0000-4000-8000-000000000002']::uuid[],'direct_create',
  'd7900000-0000-4000-8000-000000000002')).status::text,'active','Direct Active finalization creates an active membership');
select extensions.is((public.finalize_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000005','new-m27d@test.invalid',
  'New M27D','new.m27d','admin',array['d7200000-0000-4000-8000-000000000002']::uuid[],'direct_create',
  'd7900000-0000-4000-8000-000000000002')).status::text,'active','Direct Active finalization retry is idempotent');
select extensions.is((public.prepare_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001',null,'new-m27d@test.invalid','New M27D','new.m27d','admin',
  array['d7200000-0000-4000-8000-000000000002']::uuid[],'direct_create','d7900000-0000-4000-8000-000000000002')).status,
  'completed','completed Direct Active request remains retry-safe without client knowledge of the Auth UUID');
select extensions.is((select count(*)::integer from public.account_membership_branches assignment
  join public.account_memberships membership on membership.id=assignment.membership_id
  where membership.user_id='d7000000-0000-4000-8000-000000000005' and assignment.is_active),1,
  'Direct Active member receives exactly the requested Branch');
select extensions.ok(exists(select 1 from public.settings_change_events
  where client_request_id='d7900000-0000-4000-8000-000000000002' and action='member.provision_active'),
  'Direct Active creation retains safe audit evidence');

select extensions.is((public.prepare_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','invited-m27d@test.invalid',
  'M27D Invited','invited.m27d','admin',array['d7200000-0000-4000-8000-000000000002']::uuid[],'activate',
  'd7900000-0000-4000-8000-000000000003')).operation,'activate','existing invited identity is prepared for activation');
select extensions.is((public.finalize_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','invited-m27d@test.invalid',
  'M27D Invited','invited.m27d','admin',array['d7200000-0000-4000-8000-000000000002']::uuid[],'activate',
  'd7900000-0000-4000-8000-000000000003')).status::text,'active','invited identity activates without duplication');
select extensions.is((public.finalize_direct_member_provisioning(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','invited-m27d@test.invalid',
  'M27D Invited','invited.m27d','admin',array['d7200000-0000-4000-8000-000000000002']::uuid[],'activate',
  'd7900000-0000-4000-8000-000000000003')).status::text,'active','activation retry remains deterministic');
select extensions.is((select count(*)::integer from public.account_memberships
  where account_id='d7100000-0000-4000-8000-000000000001' and user_id='d7000000-0000-4000-8000-000000000003'),1,
  'activation preserves one membership identity');

select set_config('request.jwt.claims','{"sub":"d7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.is((public.manage_my_profile('Platform Renamed','platform.renamed','d7900000-0000-4000-8000-000000000004')).username,
  'platform.renamed','My Account changes normalized username');
select extensions.ok(public.is_platform_superuser(),'platform privilege survives display-name and username changes');
select extensions.is((public.manage_my_profile('Platform Renamed','platform.renamed','d7900000-0000-4000-8000-000000000004')).username,
  'platform.renamed','My Account profile retry is idempotent');

reset role;
set local role service_role;
select extensions.is(public.resolve_login_username('PLATFORM.RENAMED'),'d7000000-0000-4000-8000-000000000002'::uuid,
  'new username resolves to the same Supabase Auth identity');
select extensions.ok(public.resolve_login_username('platform.old') is null,'old username no longer resolves after committed change');
reset role;
update auth.users set email='platform-renamed-m27d@test.invalid',encrypted_password='replacement'
  where id='d7000000-0000-4000-8000-000000000002';
set local role authenticated;
select set_config('request.jwt.claims','{"sub":"d7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.ok(public.is_platform_superuser(),'platform privilege survives Auth email and password changes because it is UUID-bound');
select extensions.is((select count(*)::integer from public.platform_user_privileges
  where user_id='d7000000-0000-4000-8000-000000000002' and is_active),1,'identity changes do not duplicate platform privilege');

select extensions.throws_ok($$select public.prepare_managed_auth_change(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000004','member.password_reset',null,
  'd7900000-0000-4000-8000-000000000005')$$,'P0002',null,'cross-account target is not managed through another workspace');
select extensions.is((public.prepare_managed_auth_change(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','member.password_reset',null,
  'd7900000-0000-4000-8000-000000000006')->>'claimed')::boolean,true,'Platform Superuser prepares managed password reset');
select extensions.is(public.finish_managed_auth_change(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','member.password_reset',null,
  'd7900000-0000-4000-8000-000000000006')->>'credential_replaced','true','managed reset records no password content');
select extensions.ok(not exists(select 1 from public.settings_change_events
  where request_payload::text ~* 'password|credential[^_]' or coalesce(after_state::text,'') ~* 'password'),
  'Settings audit contains no password content');

select set_config('request.jwt.claims','{"sub":"d7000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.throws_ok($$select public.prepare_managed_auth_change(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','member.password_reset',null,
  'd7900000-0000-4000-8000-000000000007')$$,'42501',null,'Owner cannot reset another member password');
select extensions.throws_ok($$select public.prepare_managed_auth_change(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000003','member.email','other-email@test.invalid',
  'd7900000-0000-4000-8000-000000000008')$$,'42501',null,'Owner cannot change another member email');
select set_config('request.jwt.claims','{"sub":"d7000000-0000-4000-8000-000000000002","role":"authenticated"}',true);
select extensions.throws_ok($$select public.manage_settings_membership(
  'd7100000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000001','admin','active',
  array['d7200000-0000-4000-8000-000000000001']::uuid[],'owner.changed','M27D Owner',
  'd7900000-0000-4000-8000-000000000010')$$,'23514',null,'last active Owner protection remains enforced');

reset role;
set local role anon;
select extensions.throws_ok($$select public.manage_my_profile('Anonymous','anonymous.user','d7900000-0000-4000-8000-000000000009')$$,
  '42501',null,'anonymous cannot mutate My Account profile');
reset role;

select * from extensions.finish();
rollback;
