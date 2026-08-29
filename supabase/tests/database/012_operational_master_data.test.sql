begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

insert into auth.users (id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('e3000000-0000-0000-0000-000000000001','authenticated','authenticated','m23e-owner-a@test.invalid','',now(),'{}','{}',now(),now()),
('e3000000-0000-0000-0000-000000000002','authenticated','authenticated','m23e-admin-a@test.invalid','',now(),'{}','{}',now(),now()),
('e3000000-0000-0000-0000-000000000003','authenticated','authenticated','m23e-tech-a@test.invalid','',now(),'{}','{}',now(),now()),
('e3000000-0000-0000-0000-000000000004','authenticated','authenticated','m23e-operator-a@test.invalid','',now(),'{}','{}',now(),now()),
('e3000000-0000-0000-0000-000000000005','authenticated','authenticated','m23e-suspended-a@test.invalid','',now(),'{}','{}',now(),now()),
('e3000000-0000-0000-0000-000000000006','authenticated','authenticated','m23e-owner-b@test.invalid','',now(),'{}','{}',now(),now()),
('e3000000-0000-0000-0000-000000000007','authenticated','authenticated','m23e-platform@test.invalid','',now(),'{}','{}',now(),now());

insert into public.platform_user_privileges(user_id,role)
values('e3000000-0000-0000-0000-000000000007','superuser');

insert into public.accounts (id,code,name,created_by,updated_by) values
('e3100000-0000-0000-0000-000000000001','M23E-A','M2.3E Account A','e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001'),
('e3100000-0000-0000-0000-000000000002','M23E-B','M2.3E Account B','e3000000-0000-0000-0000-000000000006','e3000000-0000-0000-0000-000000000006');

insert into public.account_memberships (id,account_id,user_id,role,status,accepted_at,created_by,updated_by) values
('e3200000-0000-0000-0000-000000000001','e3100000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001','owner','active',now(),'e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001'),
('e3200000-0000-0000-0000-000000000002','e3100000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000002','admin','active',now(),'e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001'),
('e3200000-0000-0000-0000-000000000003','e3100000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000003','technician','active',now(),'e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001'),
('e3200000-0000-0000-0000-000000000004','e3100000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000004','operator','active',now(),'e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001'),
('e3200000-0000-0000-0000-000000000005','e3100000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000005','operator','suspended',now(),'e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001'),
('e3200000-0000-0000-0000-000000000006','e3100000-0000-0000-0000-000000000002','e3000000-0000-0000-0000-000000000006','owner','active',now(),'e3000000-0000-0000-0000-000000000006','e3000000-0000-0000-0000-000000000006');

insert into public.branches (id,account_id,code,name,timezone,created_by,updated_by) values
('e3300000-0000-0000-0000-000000000001','e3100000-0000-0000-0000-000000000001','M23E-A-MAIN','M2.3E A Main','Asia/Jakarta','e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001'),
('e3300000-0000-0000-0000-000000000002','e3100000-0000-0000-0000-000000000002','M23E-B-MAIN','M2.3E B Main','Asia/Makassar','e3000000-0000-0000-0000-000000000006','e3000000-0000-0000-0000-000000000006');

insert into public.machines (id,account_id,branch_id,machine_model_id,machine_code,display_name,created_by,updated_by) values
('e3400000-0000-4000-8000-000000000001','e3100000-0000-0000-0000-000000000001','e3300000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001','M23E-A-01','M2.3E Machine A','e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001');

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

set local role anon;
select extensions.throws_ok('select * from public.operational_people','42501',null,'anonymous cannot read operators');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000001',true);
select extensions.throws_ok($$insert into public.operational_people(id,account_id,name,code) values ('e3500000-0000-4000-8000-000000000001','e3100000-0000-0000-0000-000000000001','Press PIC','PIC-1')$$,'42501',null,'Owner cannot create Settings-governed Operational People');
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000007',true);
select extensions.lives_ok($$insert into public.operational_people(id,account_id,name,code) values ('e3500000-0000-4000-8000-000000000001','e3100000-0000-0000-0000-000000000001','Press PIC','PIC-1')$$,'Platform Superuser can create Operational People');
select extensions.lives_ok($$insert into public.manufacturers(id,account_id,code,name) values ('e3600000-0000-4000-8000-000000000001','e3100000-0000-0000-0000-000000000001','TEST-MFG','Test Manufacturer')$$,'Platform Superuser can create workspace manufacturer');
select extensions.lives_ok($$insert into public.machine_models(id,account_id,manufacturer_id,model_code,name,machine_category,color_capability) values ('e3700000-0000-4000-8000-000000000001','e3100000-0000-0000-0000-000000000001','e3600000-0000-4000-8000-000000000001','TEST-MODEL','Test Model','digital_a3','color')$$,'Platform Superuser can create workspace model');
select extensions.throws_ok($$delete from public.manufacturers where id='e3600000-0000-4000-8000-000000000001'$$,'23503',null,'manufacturer deletion is denied while a model references it');
select extensions.lives_ok($$insert into public.manufacturers(id,account_id,code,name) values ('e3600000-0000-4000-8000-000000000002','e3100000-0000-0000-0000-000000000001','TEMP-MFG','Temporary Manufacturer')$$,'Platform Superuser can create a second workspace manufacturer for deletion coverage');
select extensions.lives_ok($$insert into public.machine_models(id,account_id,manufacturer_id,model_code,name,machine_category,color_capability) values ('e3700000-0000-4000-8000-000000000002','e3100000-0000-0000-0000-000000000001','e3600000-0000-4000-8000-000000000002','TEMP-MODEL','Temporary Model','digital_a3','color')$$,'Platform Superuser can create a second workspace model for deletion coverage');
reset role;

insert into public.operational_person_branches(account_id,operational_person_id,branch_id,assigned_by,updated_by)
values ('e3100000-0000-0000-0000-000000000001','e3500000-0000-4000-8000-000000000001','e3300000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001','e3000000-0000-0000-0000-000000000001');

set local role authenticated;
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000002',true);
select extensions.is_empty($$update public.operational_people set notes='Admin managed' where id='e3500000-0000-4000-8000-000000000001' returning id$$,'Admin cannot mutate Owner-governed Operational People');
select extensions.is_empty($$update public.manufacturers set notes='Admin managed' where id='e3600000-0000-4000-8000-000000000001' returning id$$,'Admin cannot update Settings-governed manufacturers');
select extensions.is_empty($$update public.machine_models set description='Admin managed' where id='e3700000-0000-4000-8000-000000000001' returning id$$,'Admin cannot update Settings-governed machine models');
select extensions.lives_ok($$insert into public.machines(account_id,branch_id,machine_model_id,machine_code,display_name) values ('e3100000-0000-0000-0000-000000000001','e3300000-0000-0000-0000-000000000001','e3700000-0000-4000-8000-000000000001','M23E-A-02','M2.3E Workspace Model Machine')$$,'admin can create a machine referencing a workspace model');
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000007',true);
select extensions.throws_ok($$delete from public.machine_models where id='e3700000-0000-4000-8000-000000000001'$$,'23503',null,'machine model deletion is denied while a machine references it');
select extensions.lives_ok($$update public.machine_models set is_active=false where id='e3700000-0000-4000-8000-000000000001'$$,'referenced workspace model can be archived');
select extensions.lives_ok($$update public.manufacturers set is_active=false where id='e3600000-0000-4000-8000-000000000001'$$,'referenced workspace manufacturer can be archived');
select extensions.is((select machine_model_id from public.machines where machine_code='M23E-A-02'),'e3700000-0000-4000-8000-000000000001'::uuid,'catalog archive preserves the physical machine reference');
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000002',true);
select extensions.lives_ok($$select public.record_machine_counter('e3100000-0000-0000-0000-000000000001','e3400000-0000-4000-8000-000000000001',100,'2026-08-26 08:00+07','e3800000-0000-4000-8000-000000000001','e3500000-0000-4000-8000-000000000001',null,null,'total_impressions')$$,'admin records a reading for a different operational person');
select extensions.is((select operator_person_id from public.counter_readings where client_request_id='e3800000-0000-4000-8000-000000000001'),'e3500000-0000-4000-8000-000000000001'::uuid,'counter stores the operational person reference');
select extensions.is((select operator_name_snapshot from public.counter_readings where client_request_id='e3800000-0000-4000-8000-000000000001'),'Press PIC','counter stores operator name snapshot');
select extensions.is((select created_by from public.counter_readings where client_request_id='e3800000-0000-4000-8000-000000000001'),'e3000000-0000-0000-0000-000000000002'::uuid,'authenticated creator remains separate from operator');
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000007',true);
select extensions.throws_ok($$delete from public.operational_people where id='e3500000-0000-4000-8000-000000000001'$$,'23503',null,'referenced operator cannot be hard deleted');
select extensions.lives_ok($$update public.operational_people set name='Renamed PIC',is_active=false where id='e3500000-0000-4000-8000-000000000001'$$,'Platform Superuser can archive a referenced operator');
select extensions.is((select operator_name_snapshot from public.counter_readings where client_request_id='e3800000-0000-4000-8000-000000000001'),'Press PIC','archive and rename do not change historical snapshot');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000003',true);
select extensions.is((select count(*)::integer from public.operational_people),1,'technician can read account operators including archived history');
select extensions.throws_ok($$insert into public.operational_people(account_id,name) values ('e3100000-0000-0000-0000-000000000001','Denied PIC')$$,'42501',null,'technician cannot manage operators');
select extensions.throws_ok($$insert into public.manufacturers(account_id,code,name) values ('e3100000-0000-0000-0000-000000000001','DENIED','Denied')$$,'42501',null,'technician cannot manage manufacturers');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000004',true);
select extensions.is((select count(*)::integer from public.machine_models where id='e3700000-0000-4000-8000-000000000002'),1,'operator can read workspace models');
select extensions.throws_ok($$insert into public.operational_people(account_id,name) values ('e3100000-0000-0000-0000-000000000001','Operator Denied PIC')$$,'42501',null,'operator cannot manage operational people');
select extensions.is_empty($$update public.machine_models set notes='Operator denied' where id='e3700000-0000-4000-8000-000000000002' returning id$$,'operator cannot manage machine models');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000005',true);
select extensions.is((select count(*)::integer from public.operational_people),0,'suspended member cannot read operators');
select extensions.throws_ok($$insert into public.operational_people(account_id,name) values ('e3100000-0000-0000-0000-000000000001','Suspended Denied PIC')$$,'42501',null,'suspended member cannot manage operational people');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','e3000000-0000-0000-0000-000000000006',true);
select extensions.lives_ok($$update public.operational_people set name='Cross account' where id='e3500000-0000-4000-8000-000000000001'$$,'cross-account update exposes no writable row');
select extensions.is((select count(*)::integer from public.operational_people where id='e3500000-0000-4000-8000-000000000001'),0,'cross-account operator remains invisible');
select extensions.throws_ok($$insert into public.manufacturers(account_id,code,name) values ('e3100000-0000-0000-0000-000000000001','CROSS-MFG','Cross-account Manufacturer')$$,'42501',null,'cross-account manufacturer creation is denied');
select extensions.throws_ok($$insert into public.machine_models(account_id,manufacturer_id,model_code,name,machine_category,color_capability) values ('e3100000-0000-0000-0000-000000000002','e3600000-0000-4000-8000-000000000001','CROSS','Cross Model','digital_a3','color')$$,'23503',null,'cross-account manufacturer cannot back a model');
reset role;

select extensions.lives_ok($$insert into public.counter_readings(account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by) values ('e3100000-0000-0000-0000-000000000001','e3400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',90,'2026-08-25 08:00+07','e3000000-0000-0000-0000-000000000001','e3800000-0000-4000-8000-000000000002','e3000000-0000-0000-0000-000000000001')$$,'historical counter readings may retain a null operator');
select extensions.lives_ok($$update public.machines set timezone='Asia/Jayapura' where id='e3400000-0000-4000-8000-000000000001'$$,'valid IANA machine timezone is accepted');
select extensions.is((select timezone from public.machines where id='e3400000-0000-4000-8000-000000000001'),'Asia/Jayapura','explicit machine timezone remains explicit');
select extensions.throws_ok($$update public.machines set timezone='Jakarta Time' where id='e3400000-0000-4000-8000-000000000001'$$,'23514',null,'invalid machine timezone is rejected');
select extensions.lives_ok($$update public.machines set timezone=null where id='e3400000-0000-4000-8000-000000000001'$$,'null machine timezone remains available for inheritance');
select extensions.is((select timezone from public.branches where id='e3300000-0000-0000-0000-000000000001'),'Asia/Jakarta','branch timezone remains the inheritance source');
select extensions.lives_ok($$delete from public.machine_models where id='e3700000-0000-4000-8000-000000000002'$$,'unreferenced workspace model can be deleted');
select extensions.lives_ok($$delete from public.manufacturers where id='e3600000-0000-4000-8000-000000000002'$$,'unreferenced workspace manufacturer can be deleted');

select extensions.finish();
rollback;
