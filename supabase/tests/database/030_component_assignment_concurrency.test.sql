create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$ begin if exists(select 1 from pg_roles where rolname='component_assignment_race') then execute 'grant component_assignment_race to postgres'; execute 'drop owned by component_assignment_race'; execute 'drop role component_assignment_race'; end if; end $$;
create temporary table component_race_secret(password text not null);
insert into component_race_secret values(gen_random_uuid()::text);
do $$ declare password text:=(select component_race_secret.password from component_race_secret); begin execute format('create role component_assignment_race login password %L bypassrls',password); grant usage on schema public to component_assignment_race; grant authenticated to component_assignment_race; end $$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at) values ('f0000000-0000-4000-8000-000000000001','authenticated','authenticated','component-race@test.invalid','',now(),'{}','{}',now(),now());
insert into public.accounts(id,code,name) values ('f0100000-0000-4000-8000-000000000001','COMP-RACE','Component Race');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at) values ('f0200000-0000-4000-8000-000000000001','f0100000-0000-4000-8000-000000000001','f0000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.branches(id,account_id,code,name) values ('f0300000-0000-4000-8000-000000000001','f0100000-0000-4000-8000-000000000001','RACE','Race');
insert into public.manufacturers(id,code,name) values ('f0400000-0000-4000-8000-000000000001','COMP-RACE','Component Race');
insert into public.machine_models(id,manufacturer_id,model_code,name,machine_category,color_capability) values ('f0500000-0000-4000-8000-000000000001','f0400000-0000-4000-8000-000000000001','RACE','Race','digital_a3','color');
insert into public.components(id,account_id,code,name) values
('f0600000-0000-4000-8000-000000000001','f0100000-0000-4000-8000-000000000001','RACE-A','Race A'),
('f0600000-0000-4000-8000-000000000002','f0100000-0000-4000-8000-000000000001','RACE-B','Race B');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values ('f0800000-0000-4000-8000-000000000001','f0100000-0000-4000-8000-000000000001','f0300000-0000-4000-8000-000000000001','f0500000-0000-4000-8000-000000000001','RACE-01','Race Machine');

do $$ declare password text:=(select component_race_secret.password from component_race_secret); connection text:=format('host=%s port=%s dbname=%s user=component_assignment_race password=%s sslmode=disable',host(inet_server_addr()),current_setting('port'),current_database(),password); begin perform extensions.dblink_connect('component_race_1',connection); perform extensions.dblink_connect('component_race_2',connection); end $$;
select * from extensions.dblink('component_race_1',$$select set_config('request.jwt.claim.sub','f0000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
select * from extensions.dblink('component_race_2',$$select set_config('request.jwt.claim.sub','f0000000-0000-4000-8000-000000000001',false)$$) as configured(value text);

select extensions.is(extensions.dblink_send_query('component_race_1',$$with saved as materialized (select profile.id from public.save_machine_model_component_profile('f0100000-0000-4000-8000-000000000001','f0500000-0000-4000-8000-000000000001',null,'f0600000-0000-4000-8000-000000000001','RACE-SLOT',1,'counter_based',10000,true,30,15,5,0,null,'f0900000-0000-4000-8000-000000000001') profile) select saved.id from saved cross join lateral(select pg_sleep(1)) hold$$),1,'first same-slot profile creation starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('component_race_2',$$select profile.id from public.save_machine_model_component_profile('f0100000-0000-4000-8000-000000000001','f0500000-0000-4000-8000-000000000001',null,'f0600000-0000-4000-8000-000000000002',' race-slot ',2,'counter_based',12000,true,30,15,5,0,null,'f0900000-0000-4000-8000-000000000002') profile$$),1,'second same-slot profile creation starts');
select extensions.ok((select id is not null from extensions.dblink_get_result('component_race_1') as result(id uuid)),'first profile creation wins');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_2',false) as result(id uuid)),0,'conflicting profile creation returns no row');
select extensions.ok(position('duplicate key' in extensions.dblink_error_message('component_race_2'))>0,'normalized same-slot race is database-rejected');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_1',false) as result(id uuid)),0,'first profile result drained');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_2',false) as result(id uuid)),0,'second profile result drained');
select extensions.is((select count(*)::int from public.machine_model_components where machine_model_id='f0500000-0000-4000-8000-000000000001' and is_active),1,'same-slot race leaves one active profile');
select extensions.is((select count(*)::int from public.machine_component_assignments where machine_id='f0800000-0000-4000-8000-000000000001' and status='configured'),1,'profile race leaves one machine slot');

select extensions.is(extensions.dblink_send_query('component_race_1',$$with synced as materialized(select public.sync_machine_component_assignments('f0100000-0000-4000-8000-000000000001','f0800000-0000-4000-8000-000000000001') value) select synced.value from synced cross join lateral(select pg_sleep(1)) hold$$),1,'first simultaneous sync starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('component_race_2',$$select public.sync_machine_component_assignments('f0100000-0000-4000-8000-000000000001','f0800000-0000-4000-8000-000000000001')$$),1,'second simultaneous sync starts');
select extensions.ok((select value>=0 from extensions.dblink_get_result('component_race_1') as result(value integer)),'first sync succeeds');
select extensions.ok((select value>=0 from extensions.dblink_get_result('component_race_2') as result(value integer)),'second sync succeeds after serialization');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_1',false) as result(value integer)),0,'first sync drained');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_2',false) as result(value integer)),0,'second sync drained');
select extensions.is((select count(*)::int from public.machine_component_assignments where machine_id='f0800000-0000-4000-8000-000000000001' and status='configured'),1,'simultaneous sync creates no duplicate slot');

select extensions.is(extensions.dblink_send_query('component_race_1',$$with removed as materialized(select assignment.id from public.remove_machine_component_assignment('f0100000-0000-4000-8000-000000000001',(select id from public.machine_component_assignments where machine_id='f0800000-0000-4000-8000-000000000001' and status='configured'),'Concurrent removal','f0900000-0000-4000-8000-000000000003') assignment) select removed.id from removed cross join lateral(select pg_sleep(1)) hold$$),1,'remove-while-sync race starts with remove');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('component_race_2',$$select public.sync_machine_component_assignments('f0100000-0000-4000-8000-000000000001','f0800000-0000-4000-8000-000000000001')$$),1,'concurrent sync starts');
select extensions.ok((select id is not null from extensions.dblink_get_result('component_race_1') as result(id uuid)),'remove succeeds');
select extensions.ok((select value>=0 from extensions.dblink_get_result('component_race_2') as result(value integer)),'sync succeeds after remove');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_1',false) as result(id uuid)),0,'remove drained');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_2',false) as result(value integer)),0,'post-remove sync drained');
select extensions.is((select count(*)::int from public.machine_component_configuration where machine_id='f0800000-0000-4000-8000-000000000001'),0,'exclusion wins remove-versus-sync race');

select extensions.is(extensions.dblink_send_query('component_race_1',$$with archived as materialized(select profile.id from public.manage_machine_component_profile('f0100000-0000-4000-8000-000000000001',(select id from public.machine_model_components where slot_code='RACE-SLOT'),'archive','f0900000-0000-4000-8000-000000000004') profile) select archived.id from archived cross join lateral(select pg_sleep(1)) hold$$),1,'archive-while-sync race starts with archive');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('component_race_2',$$select public.sync_machine_component_assignments('f0100000-0000-4000-8000-000000000001','f0800000-0000-4000-8000-000000000001')$$),1,'sync waits behind profile archive');
select extensions.ok((select id is not null from extensions.dblink_get_result('component_race_1') as result(id uuid)),'profile archive succeeds');
select extensions.ok((select value>=0 from extensions.dblink_get_result('component_race_2') as result(value integer)),'sync succeeds after archive');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_1',false) as result(id uuid)),0,'archive result drained');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_2',false) as result(value integer)),0,'archive sync result drained');
select extensions.ok(not (select is_active from public.machine_model_components where slot_code='RACE-SLOT'),'archive wins without duplicate assignment');

select extensions.is(extensions.dblink_send_query('component_race_1',$$with restored as materialized(select profile.id from public.manage_machine_component_profile('f0100000-0000-4000-8000-000000000001',(select id from public.machine_model_components where slot_code='RACE-SLOT'),'restore','f0900000-0000-4000-8000-000000000005') profile) select restored.id from restored cross join lateral(select pg_sleep(1)) hold$$),1,'restore-versus-conflicting-create race starts with restore');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('component_race_2',$$select profile.id from public.save_machine_model_component_profile('f0100000-0000-4000-8000-000000000001','f0500000-0000-4000-8000-000000000001',null,'f0600000-0000-4000-8000-000000000002',' race-slot ',2,'counter_based',12000,true,30,15,5,0,null,'f0900000-0000-4000-8000-000000000006') profile$$),1,'conflicting create waits behind restore');
select extensions.ok((select id is not null from extensions.dblink_get_result('component_race_1') as result(id uuid)),'profile restore succeeds');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_2',false) as result(id uuid)),0,'conflicting create returns no row');
select extensions.ok(position('duplicate key' in extensions.dblink_error_message('component_race_2'))>0,'restore versus create conflict is database-rejected');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_1',false) as result(id uuid)),0,'restore result drained');
select extensions.is((select count(*)::int from extensions.dblink_get_result('component_race_2',false) as result(id uuid)),0,'conflicting create result drained');
select extensions.ok((select is_active from public.machine_model_components where slot_code='RACE-SLOT'),'restore leaves profile active');
select extensions.is((select count(*)::int from public.machine_component_configuration where machine_id='f0800000-0000-4000-8000-000000000001'),0,'restore and sync preserve explicit machine exclusion');

select extensions.dblink_disconnect('component_race_1'); select extensions.dblink_disconnect('component_race_2');
grant component_assignment_race to postgres; drop owned by component_assignment_race; drop role component_assignment_race;
select * from extensions.finish();
