begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.ok((select relrowsecurity from pg_class where oid='public.component_profile_baseline_revisions'::regclass),'baseline revision table has RLS');
select extensions.ok((select prosecdef and proconfig @> array['search_path=""']::text[] from pg_proc where oid='public.adopt_component_intelligence_recommendation(uuid,uuid,bigint,text,text,uuid,text)'::regprocedure),'adoption RPC is SECURITY DEFINER with empty search path');
select extensions.is((select count(*)::int from pg_catalog.pg_proc p cross join lateral pg_catalog.aclexplode(coalesce(p.proacl,pg_catalog.acldefault('f',p.proowner))) a where p.oid='public.adopt_component_intelligence_recommendation(uuid,uuid,bigint,text,text,uuid,text)'::regprocedure and a.grantee=0),0,'PUBLIC has no adoption RPC privileges');
select extensions.ok(not has_table_privilege('authenticated','public.component_profile_baseline_revisions','INSERT') and not has_table_privilege('authenticated','public.component_profile_baseline_revisions','UPDATE') and not has_table_privilege('authenticated','public.component_profile_baseline_revisions','DELETE'),'authenticated cannot directly mutate baseline audit');

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('b0000000-0000-0000-0000-000000000001','authenticated','authenticated','m23d-owner@test.invalid','',now(),'{}','{"display_name":"M23D Owner"}',now(),now()),
('b0000000-0000-0000-0000-000000000002','authenticated','authenticated','m23d-admin@test.invalid','',now(),'{}','{"display_name":"M23D Admin"}',now(),now()),
('b0000000-0000-0000-0000-000000000003','authenticated','authenticated','m23d-tech@test.invalid','',now(),'{}','{"display_name":"M23D Tech"}',now(),now()),
('b0000000-0000-0000-0000-000000000004','authenticated','authenticated','m23d-operator@test.invalid','',now(),'{}','{"display_name":"M23D Operator"}',now(),now()),
('b0000000-0000-0000-0000-000000000005','authenticated','authenticated','m23d-other@test.invalid','',now(),'{}','{"display_name":"M23D Other"}',now(),now()),
('b0000000-0000-0000-0000-000000000006','authenticated','authenticated','m23d-suspended@test.invalid','',now(),'{}','{"display_name":"M23D Suspended"}',now(),now());
insert into public.accounts(id,code,name,created_by,updated_by) values
('b1000000-0000-0000-0000-000000000001','M23D-A','M2.3D Account A','b0000000-0000-0000-0000-000000000001','b0000000-0000-0000-0000-000000000001'),
('b1000000-0000-0000-0000-000000000002','M23D-B','M2.3D Account B','b0000000-0000-0000-0000-000000000005','b0000000-0000-0000-0000-000000000005');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('b2000000-0000-0000-0000-000000000001','b1000000-0000-0000-0000-000000000001','b0000000-0000-0000-0000-000000000001','owner','active',now()),
('b2000000-0000-0000-0000-000000000002','b1000000-0000-0000-0000-000000000001','b0000000-0000-0000-0000-000000000002','admin','active',now()),
('b2000000-0000-0000-0000-000000000003','b1000000-0000-0000-0000-000000000001','b0000000-0000-0000-0000-000000000003','technician','active',now()),
('b2000000-0000-0000-0000-000000000004','b1000000-0000-0000-0000-000000000001','b0000000-0000-0000-0000-000000000004','operator','active',now()),
('b2000000-0000-0000-0000-000000000005','b1000000-0000-0000-0000-000000000002','b0000000-0000-0000-0000-000000000005','owner','active',now()),
('b2000000-0000-0000-0000-000000000006','b1000000-0000-0000-0000-000000000001','b0000000-0000-0000-0000-000000000006','admin','suspended',now());
insert into public.branches(id,account_id,code,name) values
('b3000000-0000-0000-0000-000000000001','b1000000-0000-0000-0000-000000000001','A','M23D Branch A'),
('b3000000-0000-0000-0000-000000000002','b1000000-0000-0000-0000-000000000002','B','M23D Branch B');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name)
select ('b4000000-0000-0000-0000-'||lpad(n::text,12,'0'))::uuid,
  'b1000000-0000-0000-0000-000000000001','b3000000-0000-0000-0000-000000000001',
  '51000000-0000-0000-0000-000000000001',format('M23D-A-%s',lpad(n::text,2,'0')),format('M23D Dataset %s',n)
from generate_series(1,13) n;

-- Workspace override used to verify adaptive_enabled=false behavior.
insert into public.machine_model_components(
  id,account_id,machine_model_id,component_id,slot_code,display_order,tracking_method,
  baseline_expected_clicks,adaptive_enabled,healthy_threshold_percent,watch_threshold_percent,
  warning_threshold_percent,critical_threshold_percent,is_active
) values (
  'be000000-0000-0000-0000-000000000011','b1000000-0000-0000-0000-000000000001',
  '51000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000011',
  'DEVELOPING_UNIT_C',11,'counter_based',200000,false,30,15,5,0,true
);

create or replace function pg_temp.add_adaptive_samples(
  target_machine_id uuid,
  target_profile_id uuid,
  sample_values bigint[],
  sample_eligibility boolean[] default null
)
returns void
language plpgsql
as $$
declare
  machine_record public.machines%rowtype;
  profile_record public.machine_model_components%rowtype;
  previous_lifecycle public.machine_component_lifecycles%rowtype;
  next_lifecycle public.machine_component_lifecycles%rowtype;
  sample_value bigint;
  sample_is_eligible boolean;
  sample_index integer;
  next_counter numeric;
begin
  select * into machine_record from public.machines where id=target_machine_id;
  select * into profile_record from public.machine_model_components where id=target_profile_id;
  select * into previous_lifecycle from public.machine_component_lifecycles
    where machine_id=target_machine_id and lower(slot_code)=lower(profile_record.slot_code) and status='active';
  if not found then
    insert into public.machine_component_lifecycles(
      account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,
      status,installed_counter,installed_at,installation_source,
      baseline_expected_clicks_snapshot,expected_at_install,created_by
    ) values (
      machine_record.account_id,machine_record.branch_id,machine_record.id,profile_record.id,
      profile_record.component_id,profile_record.slot_code,'active',100000,'2025-01-01',
      'tracking_start',profile_record.baseline_expected_clicks,profile_record.baseline_expected_clicks,
      'b0000000-0000-0000-0000-000000000001'
    ) returning * into previous_lifecycle;
  end if;

  for sample_index in 1..coalesce(array_length(sample_values,1),0) loop
    sample_value := sample_values[sample_index];
    sample_is_eligible := coalesce(sample_eligibility[sample_index],true);
    next_counter := previous_lifecycle.installed_counter + sample_value;
    update public.machine_component_lifecycles set status='closed',removed_counter=next_counter,
      removed_at='2026-01-01'::timestamptz + sample_index*interval '1 day',actual_usage=sample_value
      where id=previous_lifecycle.id returning * into previous_lifecycle;
    insert into public.machine_component_lifecycles(
      account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,
      status,installed_counter,installed_at,installation_source,
      baseline_expected_clicks_snapshot,expected_at_install,created_by
    ) values (
      machine_record.account_id,machine_record.branch_id,machine_record.id,profile_record.id,
      profile_record.component_id,profile_record.slot_code,'active',next_counter,
      '2026-01-01'::timestamptz + sample_index*interval '1 day','replacement',
      profile_record.baseline_expected_clicks,profile_record.baseline_expected_clicks,
      'b0000000-0000-0000-0000-000000000001'
    ) returning * into next_lifecycle;
    insert into public.component_replacement_events(
      account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code_snapshot,
      previous_lifecycle_id,new_lifecycle_id,previous_installed_counter,replacement_counter,
      actual_usage,expected_at_install,baseline_expected_snapshot,adaptive_expected_snapshot,
      replacement_reason,condition_at_removal,include_in_adaptive_learning,
      performed_by_user_id,performed_by_name_snapshot,replaced_at,client_request_id,created_by
    ) values (
      machine_record.account_id,machine_record.branch_id,machine_record.id,profile_record.id,
      profile_record.component_id,profile_record.slot_code,previous_lifecycle.id,next_lifecycle.id,
      previous_lifecycle.installed_counter,next_counter,sample_value,previous_lifecycle.expected_at_install,
      previous_lifecycle.baseline_expected_clicks_snapshot,previous_lifecycle.adaptive_expected_snapshot,
      'normal_eol','worn',sample_is_eligible,'b0000000-0000-0000-0000-000000000001',
      'M23D Owner','2026-01-01'::timestamptz + sample_index*interval '1 day',gen_random_uuid(),
      'b0000000-0000-0000-0000-000000000001'
    );
    previous_lifecycle := next_lifecycle;
  end loop;
end;
$$;

select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000003','54000000-0000-0000-0000-000000000003',array[48000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000004','54000000-0000-0000-0000-000000000004',array[10000,100000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',array[48000,49000,50000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000005','54000000-0000-0000-0000-000000000005',array[112000,113000,114000,115000,116000,117000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000007','54000000-0000-0000-0000-000000000007',array[100000,101000,102000,103000,104000,105000,106000,107000,108000,109000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000008','54000000-0000-0000-0000-000000000008',array[20000,30000,40000,60000,80000,120000,160000,200000,250000,300000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000009','54000000-0000-0000-0000-000000000009',array[98000,99000,100000,101000,300000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000010','54000000-0000-0000-0000-000000000010',array[110000,111000,112000,1000,500000],array[true,true,true,false,false]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000011','be000000-0000-0000-0000-000000000011',array[230000,231000,232000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000012','54000000-0000-0000-0000-000000000012',array[100000,110000,120000]);
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000013','54000000-0000-0000-0000-000000000013',array[300000,320000,340000]);

-- Toner dataset uses yield terminology in UI but identical deterministic math.
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name)
values('b4000000-0000-0000-0000-000000000025','b1000000-0000-0000-0000-000000000001','b3000000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001','M23D-TONER','M23D Toner Dataset');
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000025','54000000-0000-0000-0000-000000000025',array[13500,13800,13900,14000,14200,14500]);

set local role authenticated;
select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000001',true);
select extensions.ok((select recommendation_state='no_data' and usable_samples=0 and observed_expected_life is null and confidence_label='no_data' and confidence_score=0 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_M'),'zero samples produce intentional no-data state');
select extensions.ok((select recommendation_state='insufficient_data' and usable_samples=1 and observed_expected_life=48000 and confidence_score<25 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_Y'),'one sample is observation-only with very-low confidence');
select extensions.ok((select recommendation_state='insufficient_data' and usable_samples=2 and outlier_count=0 and confidence_score<=44 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_K'),'two samples remain insufficient and are not aggressively outlier-filtered');
select extensions.ok((select usable_samples=3 and recommendation_state='review_increase' and can_adopt from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),'three usable samples unlock a formal recommendation');
select extensions.is((select observed_expected_life::bigint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),49000::bigint,'observed estimator is the median');
select extensions.is((select mean_actual_usage::bigint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),49000::bigint,'mean is derived correctly');
select extensions.ok((select minimum_actual_usage=48000 and maximum_actual_usage=50000 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),'minimum and maximum are correct');
select extensions.ok((select standard_deviation=1000 and coefficient_of_variation between .0203 and .0205 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),'variability statistics are deterministic');
select extensions.ok((select difference_clicks=9000 and difference_percent=22.50 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),'baseline differences are correct');
select extensions.is((select recommendation_state from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPER_C'),'keep_baseline','ten consistent samples inside dead band keep baseline');
select extensions.is((select recommendation_state from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CLEANING_BLADE'),'review_increase','consistent evidence outside dead band recommends review increase');
select extensions.is((select recommendation_state from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPING_UNIT_M'),'review_decrease','lower observed life recommends review decrease');
select extensions.is((select suggested_baseline from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPING_UNIT_Y'),250000::bigint,'positive one-step adoption is guarded at +25 percent');
select extensions.is((select suggested_baseline from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPING_UNIT_M'),150000::bigint,'negative one-step adoption is guarded at -25 percent');
select extensions.ok((select recommendation_state='high_variability' and coefficient_of_variation>.40 and not can_adopt from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPER_M'),'high variability blocks unsafe adoption');
select extensions.ok((select confidence_score=(quantity_score*.45+consistency_score*.40+quality_score*.15) and algorithm_version='v1' from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPER_C'),'confidence formula and algorithm version are deterministic');
select extensions.ok((select a.confidence_score < b.confidence_score and b.confidence_score < c.confidence_score from public.component_adaptive_intelligence a,public.component_adaptive_intelligence b,public.component_adaptive_intelligence c where a.account_id='b1000000-0000-0000-0000-000000000001' and b.account_id=a.account_id and c.account_id=a.account_id and a.slot_code='CHARGING_CORONA_C' and b.slot_code='CLEANING_BLADE' and c.slot_code='DEVELOPER_C'),'confidence increases with consistent evidence quantity');
select extensions.ok((select outlier_count=1 and eligible_samples=5 and usable_samples=4 and observed_expected_life=99500 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPER_Y'),'sufficient data detects and excludes an IQR outlier');
select extensions.is((select count(*)::int from public.component_adaptive_sample_diagnostics where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPER_Y'),5,'outlier remains visible historically');
select extensions.ok((select total_completed_samples=5 and eligible_samples=3 and usable_samples=3 and observed_expected_life=111000 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPER_K'),'ineligible samples never enter statistics');
select extensions.ok((select tracking_method='consumption_based' and observed_expected_life=13950 and recommendation_state='keep_baseline' from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='TONER_C'),'toner yield uses the same robust estimator');
select extensions.ok((select recommendation_state='adaptive_disabled' and not can_adopt and observed_expected_life=231000 from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPING_UNIT_C'),'adaptive-disabled profile exposes statistics but no actionable recommendation');

select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000003',true);
select extensions.throws_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',40000,(select sample_fingerprint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),'v1','bf000000-0000-0000-0000-000000000001',null)$$,'42501',null,'technician cannot adopt');
select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000004',true);
select extensions.throws_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',40000,(select sample_fingerprint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),'v1','bf000000-0000-0000-0000-000000000002',null)$$,'42501',null,'operator cannot adopt');
select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000006',true);
select extensions.throws_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',40000,null,'v1','bf000000-0000-0000-0000-000000000003',null)$$,'42501',null,'suspended member cannot adopt');
select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000005',true);
select extensions.throws_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',40000,null,'v1','bf000000-0000-0000-0000-000000000004',null)$$,'42501',null,'cross-account adoption denied');
reset role;
set local role anon;
select extensions.throws_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',40000,null,'v1','bf000000-0000-0000-0000-000000000005',null)$$,'42501',null,'anonymous adoption denied');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000001',true);
select extensions.lives_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',40000,(select sample_fingerprint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),'v1','bf000000-0000-0000-0000-000000000011','Approved fixture recommendation')$$,'owner can adopt formal recommendation');
select extensions.is((select baseline_expected_clicks from public.machine_model_components where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C'),49000::bigint,'adoption creates correct workspace override baseline');
select extensions.ok((select change_source='adaptive_recommendation' and previous_expected_clicks=40000 and new_expected_clicks=49000 and algorithm_version='v1' and usable_samples=3 from public.component_profile_baseline_revisions where client_request_id='bf000000-0000-0000-0000-000000000011'),'adoption creates complete algorithm-versioned baseline audit');
select extensions.ok((select baseline_expected_clicks=40000 and account_id is null from public.machine_model_components where id='54000000-0000-0000-0000-000000000001'),'shared profile remains untouched');
select extensions.is((select count(*)::int from public.machine_component_lifecycles where machine_id='b4000000-0000-0000-0000-000000000001' and expected_at_install<>40000),0,'adoption does not rewrite historical or active lifecycle expectations');
select extensions.lives_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001',40000,(select sample_fingerprint from public.component_profile_baseline_revisions where client_request_id='bf000000-0000-0000-0000-000000000011'),'v1','bf000000-0000-0000-0000-000000000011','Approved fixture recommendation')$$,'duplicate adoption is idempotent');
select extensions.is((select count(*)::int from public.component_profile_baseline_revisions where client_request_id='bf000000-0000-0000-0000-000000000011'),1,'duplicate adoption creates one audit revision');

select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000002',true);
select extensions.lives_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000005',100000,(select sample_fingerprint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='CLEANING_BLADE'),'v1','bf000000-0000-0000-0000-000000000012',null)$$,'admin can adopt formal recommendation');
select extensions.throws_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000013',199999,(select sample_fingerprint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPING_UNIT_Y'),'v1','bf000000-0000-0000-0000-000000000013',null)$$,'40001',null,'stale baseline adoption is rejected');

create temporary table stale_sample_snapshot as select sample_fingerprint from public.component_adaptive_intelligence where account_id='b1000000-0000-0000-0000-000000000001' and slot_code='DEVELOPING_UNIT_M';
reset role;
select pg_temp.add_adaptive_samples('b4000000-0000-0000-0000-000000000012','54000000-0000-0000-0000-000000000012',array[130000]);
set local role authenticated;
select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000002',true);
select extensions.throws_ok($$select public.adopt_component_intelligence_recommendation('b1000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000012',200000,(select sample_fingerprint from stale_sample_snapshot),'v1','bf000000-0000-0000-0000-000000000014',null)$$,'40001',null,'stale sample-set adoption is rejected');
select extensions.throws_ok($$update public.component_profile_baseline_revisions set reason='tampered' where client_request_id='bf000000-0000-0000-0000-000000000011'$$,'42501',null,'baseline audit cannot be updated directly');
select extensions.throws_ok($$delete from public.component_profile_baseline_revisions where client_request_id='bf000000-0000-0000-0000-000000000011'$$,'42501',null,'baseline audit cannot be deleted directly');

-- A real replacement after adoption must use the workspace override for the next lifecycle only.
reset role;
insert into public.counter_readings(account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by)
select 'b1000000-0000-0000-0000-000000000001','b4000000-0000-0000-0000-000000000001','52000000-0000-0000-0000-000000000001',installed_counter,'2026-03-01','b0000000-0000-0000-0000-000000000001','bf000000-0000-0000-0000-000000000020','b0000000-0000-0000-0000-000000000001'
from public.machine_component_lifecycles where machine_id='b4000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C' and status='active';
set local role authenticated;
select set_config('request.jwt.claim.sub','b0000000-0000-0000-0000-000000000001',true);
select extensions.lives_ok($$select public.replace_machine_component('b1000000-0000-0000-0000-000000000001','b4000000-0000-0000-0000-000000000001',(select lifecycle_id from public.machine_component_health where machine_id='b4000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C' and lifecycle_status='active'),(select latest_effective_counter from public.machine_component_health where machine_id='b4000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C' and lifecycle_status='active'),'2026-03-02','normal_eol','worn',true,'b0000000-0000-0000-0000-000000000001','M23D Owner','Future snapshot check','bf000000-0000-0000-0000-000000000021')$$,'future replacement after adoption succeeds');
select extensions.is((select expected_at_install from public.machine_component_lifecycles where machine_id='b4000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C' and status='active'),49000::bigint,'future lifecycle snapshots adopted workspace baseline');
reset role;

select * from extensions.finish();
rollback;
