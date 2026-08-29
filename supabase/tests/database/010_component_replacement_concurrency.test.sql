create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$
begin
  if exists (select 1 from pg_catalog.pg_roles where rolname = 'm23c_replacement_race') then
    execute 'grant m23c_replacement_race to postgres';
    execute 'drop owned by m23c_replacement_race';
    execute 'drop role m23c_replacement_race';
  end if;
end;
$$;

create temporary table replacement_race_secret(password text not null);
insert into replacement_race_secret values (gen_random_uuid()::text);

do $$
declare generated_password text := (select password from replacement_race_secret);
begin
  execute format('create role m23c_replacement_race login password %L bypassrls', generated_password);
  grant usage on schema public to m23c_replacement_race;
  grant authenticated to m23c_replacement_race;
end;
$$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values ('a0000000-0000-0000-0000-000000000001','authenticated','authenticated','m23c-race@test.invalid','',now(),'{}','{"display_name":"Race Operator"}',now(),now());
insert into public.accounts(id,code,name,created_by,updated_by)
values ('a1000000-0000-0000-0000-000000000001','M23C-RACE','M2.3C Race','a0000000-0000-0000-0000-000000000001','a0000000-0000-0000-0000-000000000001');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values ('a2000000-0000-0000-0000-000000000001','a1000000-0000-0000-0000-000000000001','a0000000-0000-0000-0000-000000000001','operator','active',now());
insert into public.branches(id,account_id,code,name)
values ('a3000000-0000-0000-0000-000000000001','a1000000-0000-0000-0000-000000000001','RACE','Race Branch');
insert into public.account_membership_branches(account_id,membership_id,branch_id)
values ('a1000000-0000-0000-0000-000000000001','a2000000-0000-0000-0000-000000000001','a3000000-0000-0000-0000-000000000001');
insert into public.operational_people(id,account_id,name,linked_user_id)
values ('a3100000-0000-0000-0000-000000000001','a1000000-0000-0000-0000-000000000001','Race Operator','a0000000-0000-0000-0000-000000000001');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id)
values ('a1000000-0000-0000-0000-000000000001','a3100000-0000-0000-0000-000000000001','a3000000-0000-0000-0000-000000000001');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name)
values ('a4000000-0000-0000-0000-000000000001','a1000000-0000-0000-0000-000000000001','a3000000-0000-0000-0000-000000000001','51000000-0000-0000-0000-000000000001','M23C-RACE-01','Race Machine');
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by)
values ('a5000000-0000-0000-0000-000000000001','a1000000-0000-0000-0000-000000000001','a4000000-0000-0000-0000-000000000001','52000000-0000-0000-0000-000000000001',1000000,'2026-01-01','a0000000-0000-0000-0000-000000000001','a5000000-0000-0000-0000-000000000011','a0000000-0000-0000-0000-000000000001');
insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install)
values ('a6000000-0000-0000-0000-000000000001','a1000000-0000-0000-0000-000000000001','a3000000-0000-0000-0000-000000000001','a4000000-0000-0000-0000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',960000,'2025-12-01','tracking_start',40000,40000);

do $$
declare
  generated_password text := (select password from replacement_race_secret);
  connection_string text := format(
    'host=%s port=%s dbname=%s user=m23c_replacement_race password=%s sslmode=disable',
    host(inet_server_addr()), current_setting('port'), current_database(), generated_password
  );
begin
  perform extensions.dblink_connect('replacement_race_1', connection_string);
  perform extensions.dblink_connect('replacement_race_2', connection_string);
end;
$$;

select * from extensions.dblink(
  'replacement_race_1',
  $$select set_config('request.jwt.claim.sub','a0000000-0000-0000-0000-000000000001',false)$$
) as configured(value text);
select * from extensions.dblink(
  'replacement_race_2',
  $$select set_config('request.jwt.claim.sub','a0000000-0000-0000-0000-000000000001',false)$$
) as configured(value text);

create temporary table replacement_race_backend(session_name text primary key, pid integer not null);
insert into replacement_race_backend
select 'replacement_race_1', pid from extensions.dblink('replacement_race_1','select pg_backend_pid()') as result(pid integer)
union all
select 'replacement_race_2', pid from extensions.dblink('replacement_race_2','select pg_backend_pid()') as result(pid integer);

select extensions.is(
  extensions.dblink_send_query(
    'replacement_race_1',
    $$with replaced as materialized (
      select event.id
      from public.replace_machine_component(
        'a1000000-0000-0000-0000-000000000001',
        'a4000000-0000-0000-0000-000000000001',
        'a6000000-0000-0000-0000-000000000001',
        1000000,'2026-02-01','normal_eol','worn',null,
        'a0000000-0000-0000-0000-000000000001','Race Operator',null,
        'a7000000-0000-0000-0000-000000000001',
        'external_untracked',null,null,null,'Regression fixture'
      ) event
    )
    select replaced.id from replaced cross join lateral (select pg_sleep(1)) hold_lock$$
  ),
  1,
  'first simultaneous replacement starts'
);
do $$ begin perform pg_sleep(0.15); end $$;
select extensions.is(
  extensions.dblink_send_query(
    'replacement_race_2',
    $$select event.id
      from public.replace_machine_component(
        'a1000000-0000-0000-0000-000000000001',
        'a4000000-0000-0000-0000-000000000001',
        'a6000000-0000-0000-0000-000000000001',
        1000000,'2026-02-01','failure','failed',false,
        'a0000000-0000-0000-0000-000000000001','Race Operator',null,
        'a7000000-0000-0000-0000-000000000002',
        'external_untracked',null,null,null,'Regression fixture'
      ) event$$
  ),
  1,
  'second simultaneous replacement starts'
);

do $$
begin
  for attempt in 1..40 loop
    exit when exists (
      select 1 from pg_catalog.pg_stat_activity
      where pid = (select pid from replacement_race_backend where session_name='replacement_race_2')
        and wait_event_type = 'Lock'
    );
    perform pg_sleep(0.05);
  end loop;
end;
$$;

select extensions.ok(
  (select event_id is not null from extensions.dblink_get_result('replacement_race_1') as result(event_id uuid)),
  'first simultaneous replacement wins'
);
select extensions.is(
  (select count(*)::int from extensions.dblink_get_result('replacement_race_2',false) as result(event_id uuid)),
  0,
  'second simultaneous replacement returns no event'
);
select extensions.ok(
  position('no longer active' in extensions.dblink_error_message('replacement_race_2')) > 0,
  'second simultaneous replacement receives a clear lifecycle conflict'
);
select extensions.is((select count(*)::int from public.component_replacement_events where previous_lifecycle_id='a6000000-0000-0000-0000-000000000001'),1,'race creates one replacement event');
select extensions.is((select count(*)::int from public.machine_component_lifecycles where machine_id='a4000000-0000-0000-0000-000000000001' and slot_code='CHARGING_CORONA_C' and status='active'),1,'race leaves one active lifecycle');

select extensions.dblink_disconnect('replacement_race_1');
select extensions.dblink_disconnect('replacement_race_2');
grant m23c_replacement_race to postgres;
drop owned by m23c_replacement_race;
drop role m23c_replacement_race;

select * from extensions.finish();
