create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$
begin
  if exists (select 1 from pg_catalog.pg_roles where rolname='m24b_replacement_stock_race') then
    execute 'grant m24b_replacement_stock_race to postgres';
    execute 'drop owned by m24b_replacement_stock_race';
    execute 'drop role m24b_replacement_stock_race';
  end if;
end;
$$;
create temporary table replacement_stock_race_secret(password text not null);
insert into replacement_stock_race_secret values(gen_random_uuid()::text);
do $$
declare generated_password text := (select password from replacement_stock_race_secret);
begin
  execute format('create role m24b_replacement_stock_race login password %L bypassrls',generated_password);
  grant usage on schema public to m24b_replacement_stock_race;
  grant authenticated to m24b_replacement_stock_race;
end;
$$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('d0000000-0000-4000-8000-000000000001','authenticated','authenticated','m24b-race@test.invalid','',now(),'{}','{"display_name":"M24B Race Operator"}',now(),now());
insert into public.accounts(id,code,name) values('d1000000-0000-4000-8000-000000000001','M24B-RACE','M2.4B Replacement Stock Race');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values('d2000000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','d0000000-0000-4000-8000-000000000001','operator','active',now());
insert into public.branches(id,account_id,code,name)
values('d3000000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','RACE','Race Branch');
insert into public.account_membership_branches(account_id,membership_id,branch_id)
values('d1000000-0000-4000-8000-000000000001','d2000000-0000-4000-8000-000000000001','d3000000-0000-4000-8000-000000000001');
insert into public.operational_people(id,account_id,name,linked_user_id)
values('d3100000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','M24B Race Operator','d0000000-0000-4000-8000-000000000001');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id)
values('d1000000-0000-4000-8000-000000000001','d3100000-0000-4000-8000-000000000001','d3000000-0000-4000-8000-000000000001');
insert into public.machines(id,account_id,branch_id,machine_model_id,machine_code,display_name) values
('d4000000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','d3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-RACE-1','Race Machine One'),
('d4000000-0000-4000-8000-000000000002','d1000000-0000-4000-8000-000000000001','d3000000-0000-4000-8000-000000000001','51000000-0000-0000-0000-000000000001','M24B-RACE-2','Race Machine Two');
insert into public.counter_readings(id,account_id,machine_id,counter_type_id,reading_value,observed_at,entered_by,client_request_id,created_by) values
('d5000000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','d4000000-0000-4000-8000-000000000001','52000000-0000-0000-0000-000000000001',1000,'2026-08-01','d0000000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000001','d0000000-0000-4000-8000-000000000001'),
('d5000000-0000-4000-8000-000000000002','d1000000-0000-4000-8000-000000000001','d4000000-0000-4000-8000-000000000002','52000000-0000-0000-0000-000000000001',1000,'2026-08-01','d0000000-0000-4000-8000-000000000001','d5100000-0000-4000-8000-000000000002','d0000000-0000-4000-8000-000000000001');
insert into public.machine_component_lifecycles(id,account_id,branch_id,machine_id,model_component_profile_id,component_id,slot_code,status,installed_counter,installed_at,installation_source,baseline_expected_clicks_snapshot,expected_at_install) values
('d6000000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','d3000000-0000-4000-8000-000000000001','d4000000-0000-4000-8000-000000000001','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',900,'2026-07-01','tracking_start',40000,40000),
('d6000000-0000-4000-8000-000000000002','d1000000-0000-4000-8000-000000000001','d3000000-0000-4000-8000-000000000001','d4000000-0000-4000-8000-000000000002','54000000-0000-0000-0000-000000000001','53000000-0000-0000-0000-000000000001','CHARGING_CORONA_C','active',900,'2026-07-01','tracking_start',40000,40000);
insert into public.inventory_items(id,account_id,component_id,sku,name,unit)
values('d7000000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','53000000-0000-0000-0000-000000000001','LAST-C','Last Corona Cyan','pcs');
insert into public.inventory_locations(id,account_id,branch_id,code,name)
values('d7100000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','d3000000-0000-4000-8000-000000000001','WH','Last Stock Warehouse');
insert into public.inventory_movements(id,account_id,inventory_item_id,location_id,movement_type,quantity,unit_snapshot,occurred_at,operational_person_name_snapshot,reference_type,client_request_id,created_by,created_by_name_snapshot)
values('d7200000-0000-4000-8000-000000000001','d1000000-0000-4000-8000-000000000001','d7000000-0000-4000-8000-000000000001','d7100000-0000-4000-8000-000000000001','opening_balance',1,'pcs','2026-08-10','Race Operator','opening_balance','d7300000-0000-4000-8000-000000000001','d0000000-0000-4000-8000-000000000001','M24B Race Operator');

do $$
declare generated_password text := (select password from replacement_stock_race_secret);
  connection_string text := format('host=%s port=%s dbname=%s user=m24b_replacement_stock_race password=%s sslmode=disable',host(inet_server_addr()),current_setting('port'),current_database(),generated_password);
begin
  perform extensions.dblink_connect('m24b_stock_race_1',connection_string);
  perform extensions.dblink_connect('m24b_stock_race_2',connection_string);
end;
$$;
select * from extensions.dblink('m24b_stock_race_1',$$select set_config('request.jwt.claim.sub','d0000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
select * from extensions.dblink('m24b_stock_race_2',$$select set_config('request.jwt.claim.sub','d0000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
create temporary table replacement_stock_race_backend(session_name text primary key,pid integer not null);
insert into replacement_stock_race_backend
select 'm24b_stock_race_1',pid from extensions.dblink('m24b_stock_race_1','select pg_backend_pid()') as result(pid integer)
union all select 'm24b_stock_race_2',pid from extensions.dblink('m24b_stock_race_2','select pg_backend_pid()') as result(pid integer);

select extensions.is(extensions.dblink_send_query('m24b_stock_race_1',$$with replaced as materialized (
  select event.id from public.replace_machine_component(
    'd1000000-0000-4000-8000-000000000001','d4000000-0000-4000-8000-000000000001','d6000000-0000-4000-8000-000000000001',
    1000,'2026-08-20','normal_eol','worn',true,'d0000000-0000-4000-8000-000000000001','Race Operator',null,
    'd8000000-0000-4000-8000-000000000001','inventory','d7000000-0000-4000-8000-000000000001','d7100000-0000-4000-8000-000000000001',1,null) event
) select replaced.id from replaced cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first last-stock replacement starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m24b_stock_race_2',$$select event.id from public.replace_machine_component(
  'd1000000-0000-4000-8000-000000000001','d4000000-0000-4000-8000-000000000002','d6000000-0000-4000-8000-000000000002',
  1100,'2026-08-20','normal_eol','worn',true,'d0000000-0000-4000-8000-000000000001','Race Operator',null,
  'd8000000-0000-4000-8000-000000000002','inventory','d7000000-0000-4000-8000-000000000001','d7100000-0000-4000-8000-000000000001',1,null) event$$),1,'second last-stock replacement starts');
do $$
begin
  for attempt in 1..40 loop
    exit when exists(select 1 from pg_catalog.pg_stat_activity where pid=(select pid from replacement_stock_race_backend where session_name='m24b_stock_race_2') and wait_event_type='Lock');
    perform pg_sleep(.05);
  end loop;
end;
$$;
select extensions.ok((select event_id is not null from extensions.dblink_get_result('m24b_stock_race_1') as result(event_id uuid)),'first last-stock consumer succeeds');
select extensions.is((select count(*)::int from extensions.dblink_get_result('m24b_stock_race_2',false) as result(event_id uuid)),0,'second last-stock consumer returns no event');
select extensions.ok(position('tidak mencukupi' in extensions.dblink_error_message('m24b_stock_race_2'))>0,'concurrent loser receives readable insufficient-stock error');
select extensions.is((select count(*)::int from public.component_replacement_events where account_id='d1000000-0000-4000-8000-000000000001'),1,'race commits exactly one replacement');
select extensions.is((select count(*)::int from public.inventory_movements where account_id='d1000000-0000-4000-8000-000000000001' and movement_type='issue'),1,'race commits exactly one inventory issue');
select extensions.is((select count(*)::int from public.inventory_cost_allocations where account_id='d1000000-0000-4000-8000-000000000001'),1,'race commits exactly one FIFO cost allocation');
select extensions.is((select sum(quantity) from public.inventory_cost_allocations where account_id='d1000000-0000-4000-8000-000000000001'),1::numeric,'competing sessions cannot double-allocate the last cost lot');
select extensions.is((select remaining_quantity from public.inventory_cost_lot_balances where account_id='d1000000-0000-4000-8000-000000000001'),0::numeric,'winning session exhausts the lot exactly once');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='d7000000-0000-4000-8000-000000000001'),0::numeric,'last-stock race ends at zero, never negative');
select extensions.is((select status::text from public.machine_component_lifecycles where id='d6000000-0000-4000-8000-000000000002'),'active','losing replacement lifecycle remains active');
select extensions.is((select count(*)::int from public.counter_readings where client_request_id='d8000000-0000-4000-8000-000000000002'),0,'losing replacement creates no higher counter reading');

select extensions.dblink_disconnect('m24b_stock_race_1');
select extensions.dblink_disconnect('m24b_stock_race_2');
grant m24b_replacement_stock_race to postgres;
drop owned by m24b_replacement_stock_race;
drop role m24b_replacement_stock_race;
select * from extensions.finish();
