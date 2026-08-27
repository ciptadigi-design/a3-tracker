begin;
create extension if not exists pgtap with schema extensions;
select extensions.plan(28);

select extensions.is((select count(*)::int from pg_catalog.pg_class c cross join lateral pg_catalog.aclexplode(coalesce(c.relacl,pg_catalog.acldefault('r',c.relowner))) a where c.oid=any(array['public.components'::regclass,'public.machine_model_components'::regclass]) and a.grantee=0),0,'PUBLIC has no component table privileges');
select extensions.ok((select relrowsecurity from pg_class where oid='public.components'::regclass),'components has RLS');
select extensions.ok((select relrowsecurity from pg_class where oid='public.machine_model_components'::regclass),'profiles has RLS');

insert into auth.users (id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values
('70000000-0000-0000-0000-000000000001','authenticated','authenticated','m23-owner@test.invalid','',now(),'{}','{}',now(),now()),
('70000000-0000-0000-0000-000000000002','authenticated','authenticated','m23-admin@test.invalid','',now(),'{}','{}',now(),now()),
('70000000-0000-0000-0000-000000000003','authenticated','authenticated','m23-tech@test.invalid','',now(),'{}','{}',now(),now()),
('70000000-0000-0000-0000-000000000004','authenticated','authenticated','m23-operator@test.invalid','',now(),'{}','{}',now(),now()),
('70000000-0000-0000-0000-000000000005','authenticated','authenticated','m23-other@test.invalid','',now(),'{}','{}',now(),now());
insert into public.accounts(id,code,name,created_by,updated_by) values
('71000000-0000-0000-0000-000000000001','M23-A','M23 Account A','70000000-0000-0000-0000-000000000001','70000000-0000-0000-0000-000000000001'),
('71000000-0000-0000-0000-000000000002','M23-B','M23 Account B','70000000-0000-0000-0000-000000000005','70000000-0000-0000-0000-000000000005');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values
('72000000-0000-0000-0000-000000000001','71000000-0000-0000-0000-000000000001','70000000-0000-0000-0000-000000000001','owner','active',now()),
('72000000-0000-0000-0000-000000000002','71000000-0000-0000-0000-000000000001','70000000-0000-0000-0000-000000000002','admin','active',now()),
('72000000-0000-0000-0000-000000000003','71000000-0000-0000-0000-000000000001','70000000-0000-0000-0000-000000000003','technician','active',now()),
('72000000-0000-0000-0000-000000000004','71000000-0000-0000-0000-000000000001','70000000-0000-0000-0000-000000000004','operator','active',now()),
('72000000-0000-0000-0000-000000000005','71000000-0000-0000-0000-000000000002','70000000-0000-0000-0000-000000000005','owner','active',now());

set local role anon;
select extensions.throws_ok('select * from public.components','42501',null,'anonymous component read denied');
select extensions.throws_ok('select * from public.machine_model_components','42501',null,'anonymous profile read denied');
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub','70000000-0000-0000-0000-000000000003',true);
select extensions.is((select count(*)::int from public.components where account_id is null),28,'technician reads shared catalog');
select extensions.throws_ok($$insert into public.components(account_id,code,name) values('71000000-0000-0000-0000-000000000001','TECH','Tech')$$,'42501',null,'technician write denied');
select set_config('request.jwt.claim.sub','70000000-0000-0000-0000-000000000004',true);
select extensions.throws_ok($$insert into public.components(account_id,code,name) values('71000000-0000-0000-0000-000000000001','OP','Operator')$$,'42501',null,'operator write denied');

select set_config('request.jwt.claim.sub','70000000-0000-0000-0000-000000000001',true);
select extensions.lives_ok($$insert into public.components(account_id,code,name) values('71000000-0000-0000-0000-000000000001','OWNER_PART','Owner Part')$$,'owner component create');
select extensions.throws_ok($$insert into public.components(account_id,code,name) values('71000000-0000-0000-0000-000000000002','CROSS','Cross')$$,'42501',null,'cross-account component create denied');
select set_config('request.jwt.claim.sub','70000000-0000-0000-0000-000000000002',true);
select extensions.lives_ok($$insert into public.components(account_id,code,name) values('71000000-0000-0000-0000-000000000001','ADMIN_PART','Admin Part')$$,'admin component create');
select extensions.lives_ok($$insert into public.machine_model_components(account_id,machine_model_id,component_id,slot_code,tracking_method,baseline_expected_clicks) values('71000000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001',(select id from public.components where code='OWNER_PART'),'OWNER_SLOT','counter_based',1000)$$,'admin model profile create');
select extensions.lives_ok($$update public.machine_model_components set notes='updated' where account_id='71000000-0000-0000-0000-000000000001' and slot_code='OWNER_SLOT'$$,'admin model profile update');
select extensions.lives_ok($$update public.machine_model_components set baseline_expected_clicks=13200 where account_id='71000000-0000-0000-0000-000000000001' and slot_code='OWNER_SLOT'$$,'expected clicks editable');
select extensions.throws_ok($$update public.machine_model_components set baseline_expected_clicks=-1 where account_id='71000000-0000-0000-0000-000000000001' and slot_code='OWNER_SLOT'$$,'23514',null,'negative expected clicks denied');
select extensions.throws_ok($$insert into public.machine_model_components(account_id,machine_model_id,component_id,slot_code,tracking_method) values('71000000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001',(select id from public.components where code='ADMIN_PART'),'owner_slot','counter_based')$$,'23505',null,'duplicate active slot denied');
select extensions.lives_ok($$update public.machine_model_components set is_active=false where account_id='71000000-0000-0000-0000-000000000001' and slot_code='OWNER_SLOT'$$,'profile archive allowed');
select extensions.ok((select archived_at is not null from public.machine_model_components where account_id='71000000-0000-0000-0000-000000000001' and slot_code='OWNER_SLOT'),'archived profile remains readable');
select extensions.lives_ok($$delete from public.components where account_id='71000000-0000-0000-0000-000000000001' and code='ADMIN_PART'$$,'unused component hard delete allowed');
select extensions.throws_ok($$delete from public.components where account_id='71000000-0000-0000-0000-000000000001' and code='OWNER_PART'$$,'23503',null,'referenced component hard delete denied');
select extensions.lives_ok($$update public.components set is_active=false where account_id='71000000-0000-0000-0000-000000000001' and code='OWNER_PART'$$,'referenced component can archive');
reset role;

select extensions.is((select count(*)::int from public.machine_model_components where account_id is null and machine_model_id='51000000-0000-0000-0000-000000000001'),28,'exactly 28 authoritative C1070 profiles are seeded');
select extensions.is((select count(*)::int from public.machine_model_components where account_id is null and tracking_method='consumption_based'),4,'four toner profiles are consumption based');
select extensions.is((select count(*)::int from public.machine_model_components where account_id is null and tracking_method='counter_based'),24,'supplied mechanical profiles are counter based');
select extensions.is((select sum(baseline_expected_clicks)::bigint from public.machine_model_components where account_id is null),3126000::bigint,'all supplied exact baselines have expected checksum');
select extensions.is((select count(*)::int from (values
('CHARGING_CORONA_C',40000::bigint),('CHARGING_CORONA_M',40000),('CHARGING_CORONA_Y',40000),('CHARGING_CORONA_K',40000),('CLEANING_BLADE',100000),('CLEANING_UNIT',200000),
('DEVELOPER_C',100000),('DEVELOPER_M',100000),('DEVELOPER_Y',100000),('DEVELOPER_K',100000),('DEVELOPING_UNIT_C',200000),('DEVELOPING_UNIT_M',200000),('DEVELOPING_UNIT_Y',200000),('DEVELOPING_UNIT_K',200000),
('DRUM_C',40000),('DRUM_M',40000),('DRUM_Y',40000),('DRUM_K',40000),('FUSER_BELT',200000),('GEAR',150000),('IBT',200000),('LASER_UNIT',300000),('ROLL_MESIN',150000),('SENSOR',250000),
('TONER_C',14000),('TONER_M',14000),('TONER_Y',14000),('TONER_K',14000)
) expected(slot_code,clicks) left join public.machine_model_components p on p.account_id is null and p.slot_code=expected.slot_code and p.baseline_expected_clicks=expected.clicks where p.id is null),0,'every supplied slot has its exact baseline');
select extensions.is((select baseline_expected_clicks from public.machine_model_components where id='54000000-0000-0000-0000-000000000025'),14000::bigint,'Toner Cyan baseline exact');
select extensions.is((select baseline_expected_clicks from public.machine_model_components where id='54000000-0000-0000-0000-000000000022'),300000::bigint,'Laser Unit baseline exact');

select * from extensions.finish();
rollback;
