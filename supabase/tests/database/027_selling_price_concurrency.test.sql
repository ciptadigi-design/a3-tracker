create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$ begin
  if exists(select 1 from pg_catalog.pg_roles where rolname='m25c_price_race') then
    execute 'grant m25c_price_race to postgres'; execute 'drop owned by m25c_price_race'; execute 'drop role m25c_price_race';
  end if;
end $$;
create temporary table price_race_secret(password text not null);
insert into price_race_secret values(gen_random_uuid()::text);
do $$ declare generated_password text:=(select password from price_race_secret); begin
  execute format('create role m25c_price_race login password %L bypassrls',generated_password);
  grant usage on schema public to m25c_price_race; grant authenticated to m25c_price_race;
end $$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('d6000000-0000-4000-8000-000000000001','authenticated','authenticated','m25c-race@test.invalid','',now(),'{}','{"display_name":"Price Race Owner"}',now(),now());
insert into public.accounts(id,code,name) values('d6100000-0000-4000-8000-000000000001','M25C-RACE','M2.5C Price Race');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values('d6200000-0000-4000-8000-000000000001','d6100000-0000-4000-8000-000000000001','d6000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.branches(id,account_id,code,name) values('d6300000-0000-4000-8000-000000000001','d6100000-0000-4000-8000-000000000001','RACE','Race Branch');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('d6400000-0000-4000-8000-000000000001','d6100000-0000-4000-8000-000000000001','d6300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','RACE-1','Distinct Price Race'),
('d6400000-0000-4000-8000-000000000002','d6100000-0000-4000-8000-000000000001','d6300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','RACE-2','Boundary Race'),
('d6400000-0000-4000-8000-000000000003','d6100000-0000-4000-8000-000000000001','d6300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','RACE-3','Retry Race'),
('d6400000-0000-4000-8000-000000000004','d6100000-0000-4000-8000-000000000001','d6300000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','RACE-4','Conflict Race');

do $$ declare generated_password text:=(select password from price_race_secret);
  connection_string text:=format('host=%s port=%s dbname=%s user=m25c_price_race password=%s sslmode=disable',host(inet_server_addr()),current_setting('port'),current_database(),generated_password);
begin
  perform extensions.dblink_connect('m25c_price_1',connection_string); perform extensions.dblink_connect('m25c_price_2',connection_string);
end $$;
select * from extensions.dblink('m25c_price_1',$$select set_config('request.jwt.claim.sub','d6000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
select * from extensions.dblink('m25c_price_2',$$select set_config('request.jwt.claim.sub','d6000000-0000-4000-8000-000000000001',false)$$) as configured(value text);

-- Distinct effective starts serialize on the machine and become adjacent derived intervals.
select extensions.is(extensions.dblink_send_query('m25c_price_1',$$with created as materialized (
  select price.id from public.create_machine_selling_price('d6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000001',800,'2026-08-01 00:00+07',null,'d6500000-0000-4000-8000-000000000001') price
) select created.id from created cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first simultaneous price change starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m25c_price_2',$$select price.id from public.create_machine_selling_price(
  'd6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000001',850,'2026-08-16 00:00+07',null,'d6500000-0000-4000-8000-000000000002') price$$),1,'second simultaneous price change starts');
select extensions.ok((select id is not null from extensions.dblink_get_result('m25c_price_1') as result(id uuid)),'first simultaneous price succeeds');
select extensions.ok((select id is not null from extensions.dblink_get_result('m25c_price_2') as result(id uuid)),'second simultaneous price succeeds after serialization');
select extensions.is((select count(*)::int from public.machine_selling_prices where machine_id='d6400000-0000-4000-8000-000000000001' and status='posted'),2,'serialized distinct changes preserve two non-overlapping evidence starts');
select extensions.is((select effective_to from public.machine_selling_price_history where machine_id='d6400000-0000-4000-8000-000000000001' and price_per_click=800),'2026-08-15 17:00+00'::timestamptz,'derived first interval closes exactly at concurrent later start');

-- Contradictory same-boundary changes: exactly one can remain active.
select extensions.is(extensions.dblink_send_query('m25c_price_1',$$with created as materialized (
  select price.id from public.create_machine_selling_price('d6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000002',800,'2026-08-01 00:00+07',null,'d6500000-0000-4000-8000-000000000011') price
) select created.id from created cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first overlapping-boundary request starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m25c_price_2',$$select price.id from public.create_machine_selling_price(
  'd6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000002',825,'2026-08-01 00:00+07',null,'d6500000-0000-4000-8000-000000000012') price$$),1,'second overlapping-boundary request starts');
select extensions.ok((select id is not null from extensions.dblink_get_result('m25c_price_1') as result(id uuid)),'first boundary request succeeds');
select extensions.is((select count(*)::int from extensions.dblink_get_result('m25c_price_2',false) as result(id uuid)),0,'contradictory boundary request returns no row');
select extensions.ok(position('active selling price already starts' in extensions.dblink_error_message('m25c_price_2'))>0,'contradictory boundary request is database-rejected');
select extensions.is((select count(*)::int from public.machine_selling_prices where machine_id='d6400000-0000-4000-8000-000000000002'),1,'boundary race leaves one active price');

-- Identical same-key retry returns the same evidence after waiting.
select extensions.is(extensions.dblink_send_query('m25c_price_1',$$with created as materialized (
  select price.id from public.create_machine_selling_price('d6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000003',900,'2026-08-01 00:00+07','Retry','d6500000-0000-4000-8000-000000000021') price
) select created.id from created cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first identical retry starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m25c_price_2',$$select price.id from public.create_machine_selling_price(
  'd6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000003',900,'2026-08-01 00:00+07','Retry','d6500000-0000-4000-8000-000000000021') price$$),1,'second identical retry starts');
create temporary table identical_retry_ids(id uuid);
insert into identical_retry_ids select id from extensions.dblink_get_result('m25c_price_1') as result(id uuid);
select extensions.is((select id from extensions.dblink_get_result('m25c_price_2') as result(id uuid)),(select id from identical_retry_ids),'identical concurrent retry returns the existing row');
select extensions.is((select count(*)::int from public.machine_selling_prices where machine_id='d6400000-0000-4000-8000-000000000003'),1,'identical retry creates one row');

-- Same key with changed payload is rejected after serialization.
select extensions.is(extensions.dblink_send_query('m25c_price_1',$$with created as materialized (
  select price.id from public.create_machine_selling_price('d6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000004',700,'2026-08-01 00:00+07',null,'d6500000-0000-4000-8000-000000000031') price
) select created.id from created cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first conflicting retry starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m25c_price_2',$$select price.id from public.create_machine_selling_price(
  'd6100000-0000-4000-8000-000000000001','d6400000-0000-4000-8000-000000000004',701,'2026-08-01 00:00+07',null,'d6500000-0000-4000-8000-000000000031') price$$),1,'second conflicting retry starts');
select extensions.ok((select id is not null from extensions.dblink_get_result('m25c_price_1') as result(id uuid)),'first conflicting-key request succeeds');
select extensions.is((select count(*)::int from extensions.dblink_get_result('m25c_price_2',false) as result(id uuid)),0,'changed-payload retry returns no row');
select extensions.ok(position('client request id was already used' in extensions.dblink_error_message('m25c_price_2'))>0,'changed-payload concurrent retry is rejected');
select extensions.is((select count(*)::int from public.machine_selling_prices where machine_id='d6400000-0000-4000-8000-000000000004'),1,'conflicting retry leaves one immutable row');

select extensions.dblink_disconnect('m25c_price_1'); select extensions.dblink_disconnect('m25c_price_2');
grant m25c_price_race to postgres; drop owned by m25c_price_race; drop role m25c_price_race;
select * from extensions.finish();
