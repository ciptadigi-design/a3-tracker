create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$
begin
  if exists(select 1 from pg_catalog.pg_roles where rolname='m27d_identity_race') then
    execute 'grant m27d_identity_race to postgres';
    execute 'drop owned by m27d_identity_race';
    execute 'drop role m27d_identity_race';
  end if;
end $$;
create temporary table m27d_identity_race_secret(password text not null);
insert into m27d_identity_race_secret values(gen_random_uuid()::text);
do $$
declare generated_password text:=(select password from m27d_identity_race_secret);
begin
  execute format('create role m27d_identity_race login password %L bypassrls',generated_password);
  grant usage on schema public to m27d_identity_race;
  grant authenticated to m27d_identity_race;
  grant usage on type public.account_role to m27d_identity_race;
end $$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('e7000000-0000-4000-8000-000000000001','authenticated','authenticated','platform-race-m27d@test.invalid','',now(),'{}','{"display_name":"M27D Race Platform"}',now(),now());
insert into public.platform_user_privileges(user_id,role) values('e7000000-0000-4000-8000-000000000001','superuser');
insert into public.accounts(id,code,name) values('e7100000-0000-4000-8000-000000000001','M27D-RACE','M27D Identity Race');
insert into public.branches(id,account_id,code,name)
values('e7200000-0000-4000-8000-000000000001','e7100000-0000-4000-8000-000000000001','RACE','Race');

do $$
declare generated_password text:=(select password from m27d_identity_race_secret);
  connection_string text:=format('host=%s port=%s dbname=%s user=m27d_identity_race password=%s sslmode=disable',
    host(inet_server_addr()),current_setting('port'),current_database(),generated_password);
begin
  perform extensions.dblink_connect('m27d_identity_1',connection_string);
  perform extensions.dblink_connect('m27d_identity_2',connection_string);
end $$;
select * from extensions.dblink('m27d_identity_1',
  $$select set_config('request.jwt.claims','{"sub":"e7000000-0000-4000-8000-000000000001","role":"authenticated"}',false)$$)
  as configured(value text);
select * from extensions.dblink('m27d_identity_2',
  $$select set_config('request.jwt.claims','{"sub":"e7000000-0000-4000-8000-000000000001","role":"authenticated"}',false)$$)
  as configured(value text);

select extensions.is(extensions.dblink_send_query('m27d_identity_1',$$
  with prepared as materialized (
    select (public.prepare_direct_member_provisioning(
      'e7100000-0000-4000-8000-000000000001',null,'race-one-m27d@test.invalid','Race One','same.identity','admin',
      array['e7200000-0000-4000-8000-000000000001']::uuid[],'direct_create',
      'e7900000-0000-4000-8000-000000000001')).id
  )
  select prepared.id from prepared cross join lateral(select pg_sleep(1)) hold$$),1,
  'first username claim starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m27d_identity_2',$$
  select (public.prepare_direct_member_provisioning(
    'e7100000-0000-4000-8000-000000000001',null,'race-two-m27d@test.invalid','Race Two','SAME.IDENTITY','admin',
    array['e7200000-0000-4000-8000-000000000001']::uuid[],'direct_create',
    'e7900000-0000-4000-8000-000000000002')).id$$),1,'second username claim starts');
select extensions.ok((select request_id is not null from extensions.dblink_get_result('m27d_identity_1') as result(request_id uuid)),
  'first concurrent username claim succeeds');
select extensions.is((select count(*)::integer from extensions.dblink_get_result('m27d_identity_2',false) as result(request_id uuid)),0,
  'second concurrent username claim is rejected');
select extensions.ok(position('duplicate key value' in extensions.dblink_error_message('m27d_identity_2'))>0,
  'concurrent username loser receives a uniqueness conflict');
select extensions.is((select count(*)::integer from public.member_provisioning_requests
  where username_normalized='same.identity'),1,'exactly one normalized username reservation survives');

select extensions.dblink_disconnect('m27d_identity_1');
select extensions.dblink_disconnect('m27d_identity_2');
delete from public.member_provisioning_requests where account_id='e7100000-0000-4000-8000-000000000001';
delete from public.platform_user_privileges where user_id='e7000000-0000-4000-8000-000000000001';
delete from public.branches where account_id='e7100000-0000-4000-8000-000000000001';
delete from public.accounts where id='e7100000-0000-4000-8000-000000000001';
delete from auth.users where id='e7000000-0000-4000-8000-000000000001';
grant m27d_identity_race to postgres;
drop owned by m27d_identity_race;
drop role m27d_identity_race;
select * from extensions.finish();
