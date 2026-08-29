begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.set_machine_economics_advanced_enabled(uuid,boolean)'::regprocedure),'feature-setting RPC is SECURITY DEFINER with empty search path');
select extensions.ok(not has_function_privilege('anon','public.set_machine_economics_advanced_enabled(uuid,boolean)','EXECUTE'),'anonymous has no feature-setting EXECUTE grant');
select extensions.ok(has_function_privilege('authenticated','public.set_machine_economics_advanced_enabled(uuid,boolean)','EXECUTE'),'authenticated role reaches guarded feature-setting RPC');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('c5000000-0000-4000-8000-000000000001','authenticated','authenticated','policy-owner@test.invalid','',now(),'{}','{"display_name":"Policy Owner"}',now(),now()),
('c5000000-0000-4000-8000-000000000002','authenticated','authenticated','policy-admin@test.invalid','',now(),'{}','{"display_name":"Policy Admin"}',now(),now()),
('c5000000-0000-4000-8000-000000000003','authenticated','authenticated','policy-tech@test.invalid','',now(),'{}','{"display_name":"Policy Tech"}',now(),now()),
('c5000000-0000-4000-8000-000000000004','authenticated','authenticated','policy-operator@test.invalid','',now(),'{}','{"display_name":"Policy Operator"}',now(),now()),
('c5000000-0000-4000-8000-000000000005','authenticated','authenticated','policy-suspended@test.invalid','',now(),'{}','{"display_name":"Policy Suspended"}',now(),now()),
('c5000000-0000-4000-8000-000000000006','authenticated','authenticated','policy-other@test.invalid','',now(),'{}','{"display_name":"Policy Other"}',now(),now());

insert into public.accounts(id,code,name,default_timezone) values
('c5100000-0000-4000-8000-000000000001','POLA','Policy Account A','Asia/Jakarta'),
('c5100000-0000-4000-8000-000000000002','POLB','Policy Account B','Asia/Jakarta');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('c5200000-0000-4000-8000-000000000001','c5100000-0000-4000-8000-000000000001','c5000000-0000-4000-8000-000000000001','owner','active',now()),
('c5200000-0000-4000-8000-000000000002','c5100000-0000-4000-8000-000000000001','c5000000-0000-4000-8000-000000000002','admin','active',now()),
('c5200000-0000-4000-8000-000000000003','c5100000-0000-4000-8000-000000000001','c5000000-0000-4000-8000-000000000003','technician','active',now()),
('c5200000-0000-4000-8000-000000000004','c5100000-0000-4000-8000-000000000001','c5000000-0000-4000-8000-000000000004','operator','active',now()),
('c5200000-0000-4000-8000-000000000005','c5100000-0000-4000-8000-000000000001','c5000000-0000-4000-8000-000000000005','admin','suspended',now()),
('c5200000-0000-4000-8000-000000000006','c5100000-0000-4000-8000-000000000002','c5000000-0000-4000-8000-000000000006','owner','active',now());
insert into public.branches(id,account_id,code,name,timezone) values
('c5300000-0000-4000-8000-000000000001','c5100000-0000-4000-8000-000000000001','POL','Policy Branch','Asia/Jakarta');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name,timezone) values
('c5400000-0000-4000-8000-000000000001','c5100000-0000-4000-8000-000000000001','c5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','POLICY-01','Policy Machine','Asia/Jakarta');
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by,previous_reading_id) values
('c5500000-0000-4000-8000-000000000001','c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','c5000000-0000-4000-8000-000000000001','c5600000-0000-4000-8000-000000000001','c5000000-0000-4000-8000-000000000001',null),
('c5500000-0000-4000-8000-000000000002','c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1500,'2026-08-31 16:59+00','c5000000-0000-4000-8000-000000000001','c5600000-0000-4000-8000-000000000002','c5000000-0000-4000-8000-000000000001','c5500000-0000-4000-8000-000000000001');

-- M2.7B legacy-fixture continuity: scoped memberships and Operational People
-- are explicitly assigned to the fixture branches that were account-wide before M2.7B.
insert into public.account_membership_branches (account_id, membership_id, branch_id, assigned_by, updated_by)
select membership.account_id, membership.id, branch.id, membership.created_by, membership.created_by
from public.account_memberships membership
join public.branches branch on branch.account_id = membership.account_id
where membership.role <> 'owner'
on conflict (membership_id, branch_id) do update set is_active = true;

insert into public.operational_person_branches (account_id, operational_person_id, branch_id, assigned_by, updated_by)
select person.account_id, person.id, branch.id, person.created_by, person.created_by
from public.operational_people person
join public.branches branch on branch.account_id = person.account_id
on conflict (operational_person_id, branch_id) do update set is_active = true;

set local role authenticated;
select set_config('request.jwt.claim.sub','c5000000-0000-4000-8000-000000000001',true);
select extensions.is((select machine_economics_advanced_enabled from public.accounts where id='c5100000-0000-4000-8000-000000000001'),false,'Advanced Machine Economics defaults OFF');
select extensions.throws_ok($$select public.create_machine_operating_cost('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','electricity',1000,'one_time','Must stay disabled','c5700000-0000-4000-8000-000000000001','2026-08-10 10:00+07',null,null,null,null,null,'manual')$$,'42501',null,'OFF rejects new advanced operating-cost evidence');
select extensions.is(public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',true),true,'Owner can enable Advanced Machine Economics');
select extensions.is((select count(*)::int from public.machine_operating_costs where account_id='c5100000-0000-4000-8000-000000000001'),0,'enable does not create operating-cost records');
select extensions.is((select known_advanced_operating_cost from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),0::numeric,'Advanced ON with no period records is an explicit recorded zero');
select extensions.is((select full_economics_available from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),true,'Advanced ON makes separately labelled Full economics available');
select public.create_machine_operating_cost('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','electricity',1000,'one_time','Meter-backed electricity','c5700000-0000-4000-8000-000000000002','2026-08-10 10:00+07',null,null,null,'METER-AUG',null,'manual');
select public.create_operational_incident('c5100000-0000-4000-8000-000000000001','c5300000-0000-4000-8000-000000000001','2026-08-12 11:00+07','kualitas','human','Assessed waste','c5800000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001',null,null,null,1,null,'Operator',120,0,null,null,null);
select extensions.is(public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',false),false,'Owner can disable Advanced Machine Economics');
select extensions.is(public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',false),false,'identical setting retry is idempotent');
select extensions.is((select count(*)::int from public.machine_operating_costs where account_id='c5100000-0000-4000-8000-000000000001'),1,'disable never deletes historical advanced evidence');
select extensions.is((select advanced_machine_economics_enabled from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),false,'engine exposes Advanced OFF state');
select extensions.is((select known_standard_machine_cost from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),120::numeric,'error-only Standard Machine Cost excludes stored Advanced cost while OFF');
select extensions.is((select known_standard_cost_per_click from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),0.24::numeric,'Standard Cost / Click divides Standard cost by valid clicks');
select extensions.is((select known_full_machine_operating_cost from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),1120::numeric,'historical Full cost remains database-derived while presentation is disabled');
select extensions.is((select full_economics_available from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),false,'Full economics presentation is unavailable while Advanced is OFF');

select set_config('request.jwt.claim.sub','c5000000-0000-4000-8000-000000000002',true);
select extensions.is(public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',true),true,'Admin can enable Advanced Machine Economics');
select extensions.is((select known_standard_machine_cost from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),120::numeric,'enabling Advanced never changes Standard Machine Cost');
select extensions.is((select known_full_operating_cost_per_click from public.get_machine_economics_period('c5100000-0000-4000-8000-000000000001','c5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),2.24::numeric,'Advanced ON exposes correct Full Operating Cost / Click without double counting');
select extensions.is((select count(*)::int from public.machine_operating_costs where account_id='c5100000-0000-4000-8000-000000000001'),1,'enable preserves record count and creates no evidence');

select set_config('request.jwt.claim.sub','c5000000-0000-4000-8000-000000000003',true);
select extensions.throws_ok($$select public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',false)$$,'42501',null,'Technician cannot change feature state');
select set_config('request.jwt.claim.sub','c5000000-0000-4000-8000-000000000004',true);
select extensions.throws_ok($$select public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',false)$$,'42501',null,'Operator cannot change feature state');
select set_config('request.jwt.claim.sub','c5000000-0000-4000-8000-000000000005',true);
select extensions.throws_ok($$select public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',false)$$,'42501',null,'Suspended member cannot change feature state');
select set_config('request.jwt.claim.sub','c5000000-0000-4000-8000-000000000006',true);
select extensions.throws_ok($$select public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',false)$$,'42501',null,'Cross-account owner cannot change feature state');
reset role;

set local role anon;
select extensions.throws_ok($$select public.set_machine_economics_advanced_enabled('c5100000-0000-4000-8000-000000000001',false)$$,'42501',null,'Anonymous cannot change feature state');
reset role;

select * from extensions.finish();
rollback;
