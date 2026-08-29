begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select relrowsecurity from pg_class where oid='public.machine_component_lifecycles'::regclass),'lifecycle table has RLS');
select extensions.is((select count(*)::int from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a where c.oid=any(array['public.machine_component_lifecycles'::regclass,'public.machine_component_health'::regclass]) and a.grantee=0),0,'PUBLIC has no lifecycle relation privileges');
select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.initialize_machine_component_lifecycle(uuid,uuid,uuid,numeric,timestamptz,uuid,text)'::regprocedure),'initialize RPC is SECURITY DEFINER with empty search path');
select extensions.ok(not has_table_privilege('authenticated','public.machine_component_lifecycles','INSERT') and not has_table_privilege('authenticated','public.machine_component_lifecycles','UPDATE') and not has_table_privilege('authenticated','public.machine_component_lifecycles','DELETE'),'authenticated receives no direct lifecycle mutation privileges');

insert into auth.users (id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('80000000-0000-0000-0000-000000000001','authenticated','authenticated','m23b-owner@test.invalid','',now(),'{}','{}',now(),now()),
('80000000-0000-0000-0000-000000000002','authenticated','authenticated','m23b-admin@test.invalid','',now(),'{}','{}',now(),now()),
('80000000-0000-0000-0000-000000000003','authenticated','authenticated','m23b-tech@test.invalid','',now(),'{}','{}',now(),now()),
('80000000-0000-0000-0000-000000000004','authenticated','authenticated','m23b-operator@test.invalid','',now(),'{}','{}',now(),now()),
('80000000-0000-0000-0000-000000000005','authenticated','authenticated','m23b-other@test.invalid','',now(),'{}','{}',now(),now()),
('80000000-0000-0000-0000-000000000006','authenticated','authenticated','m23b-suspended@test.invalid','',now(),'{}','{}',now(),now());

insert into public.accounts(id,code,name,created_by,updated_by) values
('81000000-0000-0000-0000-000000000001','M23B-A','M2.3B Account A','80000000-0000-0000-0000-000000000001','80000000-0000-0000-0000-000000000001'),
('81000000-0000-0000-0000-000000000002','M23B-B','M2.3B Account B','80000000-0000-0000-0000-000000000005','80000000-0000-0000-0000-000000000005');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('82000000-0000-0000-0000-000000000001','81000000-0000-0000-0000-000000000001','80000000-0000-0000-0000-000000000001','owner','active',now()),
('82000000-0000-0000-0000-000000000002','81000000-0000-0000-0000-000000000001','80000000-0000-0000-0000-000000000002','admin','active',now()),
('82000000-0000-0000-0000-000000000003','81000000-0000-0000-0000-000000000001','80000000-0000-0000-0000-000000000003','technician','active',now()),
('82000000-0000-0000-0000-000000000004','81000000-0000-0000-0000-000000000001','80000000-0000-0000-0000-000000000004','operator','active',now()),
('82000000-0000-0000-0000-000000000005','81000000-0000-0000-0000-000000000002','80000000-0000-0000-0000-000000000005','owner','active',now()),
('82000000-0000-0000-0000-000000000006','81000000-0000-0000-0000-000000000001','80000000-0000-0000-0000-000000000006','admin','suspended',now());

insert into public.branches(id,account_id,code,name) values
('83000000-0000-0000-0000-000000000001','81000000-0000-0000-0000-000000000001','A','Branch A'),
('83000000-0000-0000-0000-000000000002','81000000-0000-0000-0000-000000000002','B','Branch B');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('84000000-0000-0000-0000-000000000001','81000000-0000-0000-0000-000000000001','83000000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001','M23B-A-01','M2.3B A Machine'),
('84000000-0000-0000-0000-000000000002','81000000-0000-0000-0000-000000000002','83000000-0000-0000-0000-000000000002','51000000-0000-0000-0000-000000000001','M23B-B-01','M2.3B B Machine');

insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by) values
('85000000-0000-0000-0000-000000000001','81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','52000000-0000-0000-0000-000000000001',1000000,now()-interval '1 minute','80000000-0000-0000-0000-000000000001','85000000-0000-0000-0000-000000000011','80000000-0000-0000-0000-000000000001'),
('85000000-0000-0000-0000-000000000002','81000000-0000-0000-0000-000000000002','84000000-0000-0000-0000-000000000002','52000000-0000-0000-0000-000000000001',500000,now()-interval '1 minute','80000000-0000-0000-0000-000000000005','85000000-0000-0000-0000-000000000012','80000000-0000-0000-0000-000000000005');

-- Unknown legacy row is intentionally present but has no fabricated counter/date.
insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installation_source,baseline_expected_clicks_snapshot,expected_at_install,notes) values
('86000000-0000-0000-0000-000000000001','81000000-0000-0000-0000-000000000001','83000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000006','53000000-0000-0000-0000-000000000006','CLEANING_UNIT','unknown','legacy_import',200000,200000,'sentinel');

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
select extensions.throws_ok('select * from public.machine_component_lifecycles','42501',null,'anonymous lifecycle read denied');
select extensions.throws_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000006',null,null,'86000000-0000-0000-0000-000000000011',null)$$,'42501',null,'anonymous initialization denied');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','80000000-0000-0000-0000-000000000004',true);
select extensions.throws_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000006',null,null,'86000000-0000-0000-0000-000000000012',null)$$,'42501',null,'operator initialization denied');
select set_config('request.jwt.claim.sub','80000000-0000-0000-0000-000000000003',true);
select extensions.throws_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000006',null,null,'86000000-0000-0000-0000-000000000013',null)$$,'42501',null,'technician initialization denied');
select set_config('request.jwt.claim.sub','80000000-0000-0000-0000-000000000006',true);
select extensions.throws_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000006',null,null,'86000000-0000-0000-0000-000000000014',null)$$,'42501',null,'suspended member initialization denied');

select set_config('request.jwt.claim.sub','80000000-0000-0000-0000-000000000001',true);
select extensions.throws_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000002','84000000-0000-0000-0000-000000000002','54000000-0000-0000-0000-000000000001',450000,null,'86000000-0000-0000-0000-000000000015',null)$$,'42501',null,'cross-account initialization denied');
select extensions.throws_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000002','54000000-0000-0000-0000-000000000001',450000,null,'86000000-0000-0000-0000-000000000016',null)$$,'P0002',null,'cross-machine mismatch denied');
select extensions.lives_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000006',900000,'2026-01-01T00:00:00Z','86000000-0000-0000-0000-000000000017','known legacy replacement')$$,'owner initializes unknown lifecycle with historical counter');
select extensions.is((select installation_source::text from public.machine_component_lifecycles where id='86000000-0000-0000-0000-000000000001'),'manual_historical','historical initialization source is explicit');

select set_config('request.jwt.claim.sub','80000000-0000-0000-0000-000000000002',true);
select extensions.lives_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',970000,null,'86000000-0000-0000-0000-000000000018',null)$$,'admin initializes lifecycle');
select extensions.lives_ok($$select public.initialize_machine_component_lifecycle('81000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',970000,null,'86000000-0000-0000-0000-000000000018',null)$$,'same initialization request is idempotent');
select extensions.throws_ok($$update public.machine_component_lifecycles set notes='direct' where id='86000000-0000-0000-0000-000000000001'$$,'42501',null,'direct authenticated lifecycle update denied');
select extensions.throws_ok($$delete from public.machine_component_lifecycles where id='86000000-0000-0000-0000-000000000001'$$,'42501',null,'direct authenticated lifecycle delete denied');

select extensions.is((select current_usage::bigint from public.machine_component_health where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001'),30000::bigint,'latest effective counter drives current usage');
select extensions.is((select remaining_clicks::bigint from public.machine_component_health where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001'),10000::bigint,'remaining clicks are expected minus current usage');
select extensions.is((select remaining_percent from public.machine_component_health where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001'),25.00::numeric,'remaining percent is derived');
select extensions.is((select health_status from public.machine_component_health where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001'),'watch','configured thresholds drive initialized health');
select extensions.is((select estimated_replacement_counter::bigint from public.machine_component_health where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001'),1010000::bigint,'estimated replacement counter is installed plus snapshot expectation');
select extensions.is((select expected_source from public.machine_component_health where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001'),'Baseline only','zero adaptive samples reports baseline only');
reset role;

select extensions.is((select count(*)::int from public.component_replacement_events where client_request_id in ('86000000-0000-0000-0000-000000000017','86000000-0000-0000-0000-000000000018')),0,'lifecycle initialization creates no replacement event');
select extensions.is((select count(*)::int from public.inventory_movements where client_request_id in ('86000000-0000-0000-0000-000000000017','86000000-0000-0000-0000-000000000018')),0,'lifecycle initialization creates no inventory movement');
select extensions.is((select count(*)::int from public.inventory_cost_allocations allocation join public.inventory_movements movement on movement.id=allocation.outbound_movement_id where movement.client_request_id in ('86000000-0000-0000-0000-000000000017','86000000-0000-0000-0000-000000000018')),0,'lifecycle initialization creates no FIFO cost allocation');

-- Direct trusted inserts model guarded bootstrap behavior and uniqueness.
select extensions.throws_ok($$insert into public.machine_component_lifecycles(account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values('81000000-0000-0000-0000-000000000001','83000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',970000,'legacy_import',40000,40000)$$,'23505',null,'one open lifecycle per machine/profile slot and duplicate bootstrap denied');
select extensions.throws_ok($$insert into public.machine_component_lifecycles(account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values('81000000-0000-0000-0000-000000000001','83000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','WRONG_SLOT','active',970000,'legacy_import',40000,40000)$$,'23514',null,'machine/profile slot mismatch denied');
select extensions.throws_ok($$insert into public.machine_component_lifecycles(account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values('81000000-0000-0000-0000-000000000001','83000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','TEST_COMPONENT','active',970000,'legacy_import',40000,40000)$$,'23514',null,'TEST_COMPONENT is excluded from lifecycle bootstrap');

select extensions.is((select current_usage from public.machine_component_health where lifecycle_id='86000000-0000-0000-0000-000000000001'),100000::numeric,'initialized former unknown has real usage only after explicit initialization');

insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('86000000-0000-0000-0000-000000000002','81000000-0000-0000-0000-000000000001','83000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000010','53000000-0000-0000-0000-000000000010','DEVELOPER_K','unknown','legacy_import',100000,100000);
select extensions.ok((select current_usage is null and remaining_clicks is null and remaining_percent is null and health_status='unknown' and estimated_replacement_counter is null from public.machine_component_health where lifecycle_id='86000000-0000-0000-0000-000000000002'),'unknown lifecycle fabricates no usage, remaining, health, or target');

insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('86000000-0000-0000-0000-000000000003','81000000-0000-0000-0000-000000000001','83000000-0000-0000-0000-000000000001','84000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000002','53000000-0000-0000-0000-000000000002','CHARGING_CORONA_M','active',900000,'legacy_import',40000,40000);
select extensions.ok((select remaining_clicks=-60000 and health_status='overdue' from public.machine_component_health where lifecycle_id='86000000-0000-0000-0000-000000000003'),'overdue preserves negative remaining clicks');

update public.machine_model_components set baseline_expected_clicks=45000 where id='54000000-0000-0000-0000-000000000001';
select extensions.ok((select expected_at_install=40000 and baseline_expected_clicks_snapshot=40000 and current_profile_baseline=45000 and effective_expected=40000 from public.machine_component_health where lifecycle_id=(select id from public.machine_component_lifecycles where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001')),'profile edit does not rewrite or recalculate lifecycle snapshots');

-- Correct the latest reading. The view must follow the replacement effective row.
set local role authenticated;
select set_config('request.jwt.claim.sub','80000000-0000-0000-0000-000000000001',true);
select extensions.lives_ok($$select public.correct_machine_counter('85000000-0000-0000-0000-000000000001','fixture correction',990000,'85000000-0000-0000-0000-000000000013',null)$$,'counter correction succeeds');
select extensions.is((select current_usage::bigint from public.machine_component_health where slot_code='CHARGING_CORONA_C' and machine_id='84000000-0000-0000-0000-000000000001'),20000::bigint,'counter correction safely changes derived lifecycle usage');
reset role;

-- Every trusted legacy row reconstructs exactly; sentinel rows are separately classified.
with legacy(slot_code,used,estimated,expected,is_unknown) as (values
('CHARGING_CORONA_C',32136::bigint,1445775::bigint,40000::bigint,false),('CHARGING_CORONA_M',101463,1376448,40000,false),('CHARGING_CORONA_Y',102387,1375524,40000,false),('CHARGING_CORONA_K',154116,1323795,40000,false),('CLEANING_BLADE',51348,1486563,100000,false),('CLEANING_UNIT',1437911,200000,200000,true),('DEVELOPER_C',22342,1515569,100000,false),('DEVELOPER_M',163267,1374644,100000,false),('DEVELOPER_Y',52462,1485449,100000,false),('DEVELOPER_K',1437911,100000,100000,true),('DEVELOPING_UNIT_C',1437911,200000,200000,true),('DEVELOPING_UNIT_M',1437911,200000,200000,true),('DEVELOPING_UNIT_Y',48450,1589461,200000,false),('DEVELOPING_UNIT_K',1437911,200000,200000,true),('DRUM_C',32136,1445775,40000,false),('DRUM_M',97119,1380792,40000,false),('DRUM_Y',51397,1426514,40000,false),('DRUM_K',1437911,40000,40000,true),('FUSER_BELT',39763,1598148,200000,false),('GEAR',1437911,150000,150000,true),('IBT',31635,1606276,200000,false),('LASER_UNIT',1437911,300000,300000,true),('ROLL_MESIN',1437911,150000,150000,true),('SENSOR',1437911,250000,250000,true),('TONER_C',7273,1444638,14000,false),('TONER_M',2045,1449866,14000,false),('TONER_Y',5516,1446395,14000,false),('TONER_K',3245,1448666,14000,false))
select extensions.is((select count(*)::int from legacy where not is_unknown and 1437911-(estimated-expected)=used),18,'all 18 trusted legacy rows reconstruct exactly');
with sentinel(slot_code,used,estimated,expected) as (values ('CLEANING_UNIT',1437911,200000,200000),('DEVELOPER_K',1437911,100000,100000),('DEVELOPING_UNIT_C',1437911,200000,200000),('DEVELOPING_UNIT_M',1437911,200000,200000),('DEVELOPING_UNIT_K',1437911,200000,200000),('DRUM_K',1437911,40000,40000),('GEAR',1437911,150000,150000),('LASER_UNIT',1437911,300000,300000),('ROLL_MESIN',1437911,150000,150000),('SENSOR',1437911,250000,250000))
select extensions.is((select count(*)::int from sentinel where used=1437911 and estimated=expected),10,'all 10 known sentinel rows remain classified unknown');
select extensions.is((1437911::bigint-(1444638::bigint-14000::bigint)),7273::bigint,'Toner Cyan historical 14K reconstruction is preserved despite 13.5K override');

select * from extensions.finish();
rollback;
