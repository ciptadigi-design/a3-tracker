begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.has_column('public','operational_incidents','operator_person_id','incident stores canonical Operator identity');
select extensions.has_column('public','operational_incidents','operator_name_snapshot','incident stores immutable Operator name snapshot');
select extensions.ok(has_function_privilege('authenticated','public.create_operational_incident_v2(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,uuid,numeric,numeric,text,text,text)','EXECUTE'),'authenticated workflow can use the V2 incident contract');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('c1000000-0000-4000-8000-000000000001','authenticated','authenticated','m210c-owner@test.invalid','x',now(),'{}','{}',now(),now());
insert into public.accounts(id,code,name) values('c1100000-0000-4000-8000-000000000001','M210C','M2.10C Test');
insert into public.branches(id,account_id,code,name) values
('c1200000-0000-4000-8000-000000000001','c1100000-0000-4000-8000-000000000001','TUP','Tuparev'),
('c1200000-0000-4000-8000-000000000002','c1100000-0000-4000-8000-000000000001','GRH','Graha');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values('c1300000-0000-4000-8000-000000000001','c1100000-0000-4000-8000-000000000001','c1000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.operational_people(id,account_id,name) values
('c1400000-0000-4000-8000-000000000001','c1100000-0000-4000-8000-000000000001','Akmal Fauzan'),
('c1400000-0000-4000-8000-000000000002','c1100000-0000-4000-8000-000000000001','Bigel'),
('c1400000-0000-4000-8000-000000000003','c1100000-0000-4000-8000-000000000001','Graha Only');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id) values
('c1100000-0000-4000-8000-000000000001','c1400000-0000-4000-8000-000000000001','c1200000-0000-4000-8000-000000000001'),
('c1100000-0000-4000-8000-000000000001','c1400000-0000-4000-8000-000000000002','c1200000-0000-4000-8000-000000000001'),
('c1100000-0000-4000-8000-000000000001','c1400000-0000-4000-8000-000000000003','c1200000-0000-4000-8000-000000000002');

set local role authenticated;
select set_config('request.jwt.claims','{"sub":"c1000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.lives_ok($$select public.create_operational_incident_v2(
  target_account_id=>'c1100000-0000-4000-8000-000000000001',
  target_branch_id=>'c1200000-0000-4000-8000-000000000001',
  target_occurred_at=>statement_timestamp(), target_category=>'prosedur',
  target_incident_type=>'human', target_description=>'Distinct people',
  target_client_request_id=>'c1900000-0000-4000-8000-000000000001',
  target_operator_person_id=>'c1400000-0000-4000-8000-000000000001',
  target_responsible_person_id=>'c1400000-0000-4000-8000-000000000002')$$,
  'new incident accepts distinct eligible Operator and PIC Terlibat');
select extensions.is((select operator_name_snapshot from public.operational_incidents where client_request_id='c1900000-0000-4000-8000-000000000001'),'Akmal Fauzan','Operator snapshot follows canonical person');
select extensions.is((select responsible_name_snapshot from public.operational_incidents where client_request_id='c1900000-0000-4000-8000-000000000001'),'Bigel','PIC Terlibat snapshot follows independent canonical person');

select extensions.throws_ok($$select public.create_operational_incident_v2(
  target_account_id=>'c1100000-0000-4000-8000-000000000001',
  target_branch_id=>'c1200000-0000-4000-8000-000000000001',
  target_occurred_at=>statement_timestamp(), target_category=>'prosedur',
  target_incident_type=>'human', target_description=>'Wrong branch Operator',
  target_client_request_id=>'c1900000-0000-4000-8000-000000000002',
  target_operator_person_id=>'c1400000-0000-4000-8000-000000000003')$$,
  '23514',null,'Branch-ineligible Operator is rejected');

select extensions.lives_ok($$select public.create_operational_incident(
  'c1100000-0000-4000-8000-000000000001','c1200000-0000-4000-8000-000000000001',statement_timestamp(),
  'prosedur','human','Historical contract row','c1900000-0000-4000-8000-000000000003')$$,
  'legacy incident contract remains available without rewriting history');
select extensions.ok((select operator_person_id is null and operator_name_snapshot is null from public.operational_incidents where client_request_id='c1900000-0000-4000-8000-000000000003'),'historical-style row remains NULL rather than receiving fabricated Operator evidence');

select * from extensions.finish();
rollback;
