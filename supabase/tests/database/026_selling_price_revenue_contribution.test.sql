begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc
  where oid='public.create_machine_selling_price(uuid,uuid,numeric,timestamp with time zone,text,uuid)'::regprocedure),'price create RPC is SECURITY DEFINER with empty search path');
select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc
  where oid='public.get_machine_economics_period(uuid,uuid,date,date)'::regprocedure),'period economics remains SECURITY DEFINER with empty search path');
select extensions.ok(not has_function_privilege('anon','public.create_machine_selling_price(uuid,uuid,numeric,timestamp with time zone,text,uuid)','EXECUTE'),'anonymous cannot execute price mutation');
select extensions.ok(has_function_privilege('authenticated','public.create_machine_selling_price(uuid,uuid,numeric,timestamp with time zone,text,uuid)','EXECUTE'),'authenticated reaches guarded price mutation RPC');
select extensions.is((select count(*)::int from pg_catalog.pg_class relation cross join lateral
  pg_catalog.aclexplode(coalesce(relation.relacl,pg_catalog.acldefault('r',relation.relowner))) acl
  where relation.oid=any(array['public.machine_selling_prices'::regclass,'public.machine_selling_price_history'::regclass]) and acl.grantee=0),0,'PUBLIC has no price table or view privileges');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('d5000000-0000-4000-8000-000000000001','authenticated','authenticated','m25c-owner@test.invalid','',now(),'{}','{"display_name":"M25C Owner"}',now(),now()),
('d5000000-0000-4000-8000-000000000002','authenticated','authenticated','m25c-admin@test.invalid','',now(),'{}','{"display_name":"M25C Admin"}',now(),now()),
('d5000000-0000-4000-8000-000000000003','authenticated','authenticated','m25c-tech@test.invalid','',now(),'{}','{"display_name":"M25C Tech"}',now(),now()),
('d5000000-0000-4000-8000-000000000004','authenticated','authenticated','m25c-operator@test.invalid','',now(),'{}','{"display_name":"M25C Operator"}',now(),now()),
('d5000000-0000-4000-8000-000000000005','authenticated','authenticated','m25c-suspended@test.invalid','',now(),'{}','{"display_name":"M25C Suspended"}',now(),now()),
('d5000000-0000-4000-8000-000000000006','authenticated','authenticated','m25c-other@test.invalid','',now(),'{}','{"display_name":"M25C Other"}',now(),now());
insert into public.accounts(id,code,name,default_timezone,machine_economics_advanced_enabled) values
('d5100000-0000-4000-8000-000000000001','M25C-A','M2.5C Account','Asia/Jakarta',true),
('d5100000-0000-4000-8000-000000000002','M25C-B','M2.5C Other','Asia/Jakarta',false);
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('d5200000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000001','owner','active',now()),
('d5200000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000002','admin','active',now()),
('d5200000-0000-4000-8000-000000000003','d5100000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000003','technician','active',now()),
('d5200000-0000-4000-8000-000000000004','d5100000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000004','operator','active',now()),
('d5200000-0000-4000-8000-000000000005','d5100000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000005','admin','suspended',now()),
('d5200000-0000-4000-8000-000000000006','d5100000-0000-4000-8000-000000000002','d5000000-0000-4000-8000-000000000006','owner','active',now());
insert into public.branches(id,account_id,code,name,timezone) values
('d5300000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','JKT','Jakarta','Asia/Jakarta'),
('d5300000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000002','OTH','Other','Asia/Jakarta');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name,timezone) values
('d5400000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','REV-1','Revenue Complete','Asia/Jakarta'),
('d5400000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','REV-2','Revenue Partial','Asia/Jakarta'),
('d5400000-0000-4000-8000-000000000003','d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','REV-3','No Price','Asia/Jakarta'),
('d5400000-0000-4000-8000-000000000004','d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','REV-4','No Clicks','Asia/Jakarta'),
('d5400000-0000-4000-8000-000000000006','d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','REV-6','Unknown Component Cost','Asia/Jakarta'),
('d5400000-0000-4000-8000-000000000005','d5100000-0000-4000-8000-000000000002','d5300000-0000-4000-8000-000000000002','51000000-0000-0000-0000-000000000001','REV-X','Other Machine','Asia/Jakarta');
insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,
  installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install,created_by) values
('d5450000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000006',
  '54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',1000,'2026-07-31 17:00+00','tracking_start',40000,40000,'d5000000-0000-4000-8000-000000000001');

-- Corrected history: superseded and voided readings do not contribute. Effective usage is 100+200+150+50=500.
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,source,status,correction_reason,client_request_id,created_by,previous_reading_id,corrects_reading_id) values
('d5500000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','d5000000-0000-4000-8000-000000000001','manual','effective',null,'d5600000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000001',null,null),
('d5500000-0000-4000-8000-000000000002','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1100,'2026-08-10 10:00+07','d5000000-0000-4000-8000-000000000001','manual','effective',null,'d5600000-0000-4000-8000-000000000002','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000001',null),
('d5500000-0000-4000-8000-000000000003','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1300,'2026-08-20 10:00+07','d5000000-0000-4000-8000-000000000001','manual','effective',null,'d5600000-0000-4000-8000-000000000003','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000002',null),
('d5500000-0000-4000-8000-000000000004','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1400,'2026-08-25 10:00+07','d5000000-0000-4000-8000-000000000001','manual','superseded','Corrected','d5600000-0000-4000-8000-000000000004','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000003',null),
('d5500000-0000-4000-8000-000000000005','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1450,'2026-08-25 10:00+07','d5000000-0000-4000-8000-000000000001','correction','effective',null,'d5600000-0000-4000-8000-000000000005','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000003','d5500000-0000-4000-8000-000000000004'),
('d5500000-0000-4000-8000-000000000006','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1500,'2026-08-26 10:00+07','d5000000-0000-4000-8000-000000000001','manual','voided','Duplicate','d5600000-0000-4000-8000-000000000006','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000005',null),
('d5500000-0000-4000-8000-000000000007','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1500,'2026-08-27 10:00+07','d5000000-0000-4000-8000-000000000001','manual','effective',null,'d5600000-0000-4000-8000-000000000007','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000005',null);
-- Partial price, no price, and baseline-only machines.
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by,previous_reading_id) values
('d5500000-0000-4000-8000-000000000011','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','d5000000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000011','d5000000-0000-4000-8000-000000000001',null),
('d5500000-0000-4000-8000-000000000012','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',1100,'2026-08-05 10:00+07','d5000000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000012','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000011'),
('d5500000-0000-4000-8000-000000000013','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',1150,'2026-08-15 10:00+07','d5000000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000013','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000012'),
('d5500000-0000-4000-8000-000000000021','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','d5000000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000021','d5000000-0000-4000-8000-000000000001',null),
('d5500000-0000-4000-8000-000000000022','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003','52000000-0000-0000-0000-000000000001',1100,'2026-08-10 10:00+07','d5000000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000022','d5000000-0000-4000-8000-000000000001','d5500000-0000-4000-8000-000000000021'),
('d5500000-0000-4000-8000-000000000031','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000004','52000000-0000-0000-0000-000000000001',1000,'2026-08-10 10:00+07','d5000000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000031','d5000000-0000-4000-8000-000000000001',null);
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by,previous_reading_id) values
('d5500000-0000-4000-8000-000000000041','d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000006','52000000-0000-0000-0000-000000000001',1000,'2026-07-31 17:00+00','d5000000-0000-4000-8000-000000000001','d5600000-0000-4000-8000-000000000041','d5000000-0000-4000-8000-000000000001',null);

insert into public.operational_incidents(account_id,branch_id,machine_id,occurred_at,category,incident_type,material_loss,description,client_request_id,created_by,updated_by) values
('d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-12 10:00+07','kualitas','human',20000,'Machine assessed waste','d5700000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000001'),
('d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001',null,'2026-08-12 11:00+07','kualitas','human',11000,'Branch-only assessed waste','d5700000-0000-4000-8000-000000000002','d5000000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000001'),
('d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-13 10:00+07','kualitas','human',0,'Unknown machine waste cost','d5700000-0000-4000-8000-000000000003','d5000000-0000-4000-8000-000000000001','d5000000-0000-4000-8000-000000000001');

-- M2.7B legacy-fixture continuity: scoped memberships and Operational People
-- are explicitly assigned to the fixture branches that were account-wide before M2.7B.
insert into public.operational_people (account_id, name, linked_user_id)
select membership.account_id, coalesce(nullif(btrim(profile.display_name), ''), auth_user.email), membership.user_id
from public.account_memberships membership
join public.profiles profile on profile.user_id = membership.user_id
join auth.users auth_user on auth_user.id = membership.user_id
where not exists (select 1 from public.operational_people person
  where person.account_id = membership.account_id and person.linked_user_id = membership.user_id);

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
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000001',true);
select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001',800,'2026-08-01 00:00+07','Initial price','d5800000-0000-4000-8000-000000000001');
select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001',850,'2026-08-16 00:00+07','Later price','d5800000-0000-4000-8000-000000000002');
select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002',900,'2026-08-10 00:00+07',null,'d5800000-0000-4000-8000-000000000003');
select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000004',750,'2026-08-01 00:00+07',null,'d5800000-0000-4000-8000-000000000004');
select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000006',800,'2026-08-01 00:00+07',null,'d5800000-0000-4000-8000-000000000011');
select public.replace_machine_component('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000006',
  'd5450000-0000-4000-8000-000000000001',1100,'2026-08-10 10:00+07','normal_eol','worn',true,
  'd5000000-0000-4000-8000-000000000001','M25C Owner',null,'d5850000-0000-4000-8000-000000000001','external_untracked',null,null,null,'Acquisition cost unavailable');
select public.create_machine_operating_cost('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','electricity',10000,'one_time','Advanced fixture','d5900000-0000-4000-8000-000000000001','2026-08-14 10:00+07',null,null,null,null,null,'manual');

select extensions.is((select price_per_click from public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001',800,'2026-08-01 00:00+07','Initial price','d5800000-0000-4000-8000-000000000001')),800::numeric,'identical create retry returns existing evidence');
select extensions.throws_ok($$select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001',801,'2026-08-01 00:00+07','Initial price','d5800000-0000-4000-8000-000000000001')$$,'23505',null,'conflicting duplicate retry is rejected');
select extensions.throws_ok($$select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001',825,'2026-08-16 00:00+07',null,'d5800000-0000-4000-8000-000000000005')$$,'23P01',null,'contradictory active price at the same boundary is rejected');
select extensions.throws_ok($$select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','NaN'::numeric,'2026-09-01 00:00+07',null,'d5800000-0000-4000-8000-000000000010')$$,'22003',null,'server rejects non-finite numeric price');
select extensions.is((select effective_to from public.machine_selling_price_history where machine_id='d5400000-0000-4000-8000-000000000001' and price_per_click=800),'2026-08-15 17:00+00'::timestamptz,'first price interval closes at later effective price');

select extensions.is((select total_clicks from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),500::numeric,'corrected effective counter usage excludes superseded and voided readings');
select extensions.is((select priced_clicks from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),500::numeric,'all effective clicks have price evidence');
select extensions.is((select estimated_revenue from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),420000::numeric,'two historical prices produce reconciled utilization revenue');
select extensions.is((select revenue_status::text from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),'COMPLETE','full price coverage reports COMPLETE');
select extensions.is((select known_standard_machine_cost from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),20000::numeric,'Standard cost includes machine-attributed assessed waste and excludes branch-only waste');
select extensions.is((select standard_contribution_status::text from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),'PARTIAL_COST','unknown machine cost evidence explicitly qualifies contribution');
select extensions.is((select estimated_standard_contribution from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),400000::numeric,'Standard contribution subtracts available Standard cost without treating unknown evidence as complete');
select extensions.is((select standard_contribution_per_click from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),800::numeric,'Standard contribution per click reconciles against fully priced clicks');
select extensions.is((select round(standard_contribution_margin_percent,4) from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),95.2381::numeric,'Standard contribution margin uses contribution divided by utilization revenue');
select extensions.is((select estimated_full_contribution from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),390000::numeric,'Advanced ON exposes separately calculated Full contribution');
select extensions.is(public.set_machine_economics_advanced_enabled('d5100000-0000-4000-8000-000000000001',false),false,'Owner can disable Advanced economics after evidence exists');
select extensions.is((select estimated_standard_contribution from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),400000::numeric,'Advanced OFF never changes Standard contribution');
select extensions.ok((select estimated_full_contribution is null and full_contribution_status is null from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),'Advanced OFF makes Full contribution unavailable');

select extensions.is((select priced_clicks from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-31')),50::numeric,'partial price coverage counts only clicks at or after first price');
select extensions.is((select unpriced_clicks from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-31')),100::numeric,'clicks before first price remain explicitly unpriced');
select extensions.is((select estimated_revenue from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-31')),45000::numeric,'partial revenue represents priced portion only');
select extensions.is((select revenue_status::text from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-31')),'PARTIAL','partial price coverage is explicit');
select extensions.ok((select estimated_standard_contribution is null and standard_contribution_per_click is null from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000002','2026-08-01','2026-08-31')),'partial revenue never produces misleading period contribution');
select extensions.is((select revenue_status::text from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003','2026-08-01','2026-08-31')),'NO_PRICE','recorded clicks with no price report NO_PRICE');
select extensions.ok((select estimated_revenue is null and estimated_standard_contribution is null from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003','2026-08-01','2026-08-31')),'no price leaves revenue and contribution unavailable');
select extensions.is((select revenue_status::text from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000004','2026-08-01','2026-08-31')),'NO_CLICKS','baseline-only usage reports NO_CLICKS');
select extensions.is((select estimated_revenue from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000004','2026-08-01','2026-08-31')),0::numeric,'no clicks has exact zero utilization revenue without division');
select extensions.ok((select standard_contribution_margin_percent is null from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000004','2026-08-01','2026-08-31')),'zero revenue never divides by zero');
select extensions.is((select unknown_consumption_events from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000006','2026-08-01','2026-08-31')),1,'external replacement remains unknown component-cost evidence');
select extensions.is((select standard_contribution_status::text from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000006','2026-08-01','2026-08-31')),'PARTIAL_COST','unknown component cost explicitly qualifies contribution');
select extensions.is((select estimated_standard_contribution from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000006','2026-08-01','2026-08-31')),80000::numeric,'unknown component cost is not fabricated as complete while available-basis contribution remains numeric');

select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000002',true);
select extensions.is((select price_per_click from public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003',700,'2026-09-01 00:00+07',null,'d5800000-0000-4000-8000-000000000006')),700::numeric,'Admin can manage selling price');
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000003',true);
select extensions.is((select count(*)::int from public.machine_selling_price_history where account_id='d5100000-0000-4000-8000-000000000001'),6,'Technician can read same-account price history');
select extensions.throws_ok($$select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003',710,'2026-09-02 00:00+07',null,'d5800000-0000-4000-8000-000000000007')$$,'42501',null,'Technician cannot mutate selling price');
select extensions.throws_ok($$insert into public.machine_selling_prices(account_id,branch_id,machine_id,price_per_click,effective_from,client_request_id,created_by,created_by_name_snapshot)
  values('d5100000-0000-4000-8000-000000000001','d5300000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003',1,now(),'d5800000-0000-4000-8000-000000000012','d5000000-0000-4000-8000-000000000003','M25C Tech')$$,'42501',null,'direct unauthorized table mutation is denied');
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000004',true);
select extensions.throws_ok($$select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000003',710,'2026-09-02 00:00+07',null,'d5800000-0000-4000-8000-000000000008')$$,'42501',null,'Operator cannot mutate selling price');
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000005',true);
select extensions.is((select count(*)::int from public.machine_selling_price_history where account_id='d5100000-0000-4000-8000-000000000001'),0,'Suspended membership cannot read price history through RLS');
select extensions.throws_ok($$select public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')$$,'42501',null,'Suspended membership cannot read economics RPC');
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000006',true);
select extensions.is((select count(*)::int from public.machine_selling_price_history where account_id='d5100000-0000-4000-8000-000000000001'),0,'Cross-account member cannot read price history');
select extensions.throws_ok($$select public.create_machine_selling_price('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001',999,'2026-09-01 00:00+07',null,'d5800000-0000-4000-8000-000000000009')$$,'42501',null,'Cross-account owner cannot mutate price');
reset role;

set local role anon;
select extensions.throws_ok($$select public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')$$,'42501',null,'Anonymous cannot read economics');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','d5000000-0000-4000-8000-000000000001',true);
select extensions.is((select status::text from public.void_machine_selling_price((select id from public.machine_selling_prices where client_request_id='d5800000-0000-4000-8000-000000000002'),'Incorrect commercial effective date','d5a00000-0000-4000-8000-000000000001')),'voided','Owner can void mistaken immutable evidence with a reason');
select extensions.is((select status::text from public.void_machine_selling_price((select id from public.machine_selling_prices where client_request_id='d5800000-0000-4000-8000-000000000002'),'Incorrect commercial effective date','d5a00000-0000-4000-8000-000000000001')),'voided','identical void retry is idempotent');
select extensions.throws_ok($$select public.void_machine_selling_price((select id from public.machine_selling_prices where client_request_id='d5800000-0000-4000-8000-000000000001'),'','d5a00000-0000-4000-8000-000000000002')$$,'22023',null,'void requires correction reason');
select extensions.is((select estimated_revenue from public.get_machine_economics_period('d5100000-0000-4000-8000-000000000001','d5400000-0000-4000-8000-000000000001','2026-08-01','2026-08-31')),400000::numeric,'voided price is excluded and prior active evidence resumes without deleting audit history');

select * from extensions.finish();
rollback;
