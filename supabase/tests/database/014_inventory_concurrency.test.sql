create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$
begin
  if exists (select 1 from pg_catalog.pg_roles where rolname='m24a_inventory_race') then
    execute 'grant m24a_inventory_race to postgres';
    execute 'drop owned by m24a_inventory_race';
    execute 'drop role m24a_inventory_race';
  end if;
end;
$$;
create temporary table inventory_race_secret(password text not null);
insert into inventory_race_secret values(gen_random_uuid()::text);
do $$
declare generated_password text := (select password from inventory_race_secret);
begin
  execute format('create role m24a_inventory_race login password %L bypassrls',generated_password);
  grant usage on schema public to m24a_inventory_race;
  grant authenticated to m24a_inventory_race;
end;
$$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('f5000000-0000-4000-8000-000000000001','authenticated','authenticated','m24a-race@test.invalid','',now(),'{}','{"display_name":"Inventory Race Owner"}',now(),now());
insert into public.accounts(id,code,name) values('f5100000-0000-4000-8000-000000000001','M24A-RACE','M2.4A Race');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values('f5200000-0000-4000-8000-000000000001','f5100000-0000-4000-8000-000000000001','f5000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.branches(id,account_id,code,name)
values('f5250000-0000-4000-8000-000000000001','f5100000-0000-4000-8000-000000000001','RACE','Race Branch');
insert into public.operational_people(id,account_id,name)
values('f5300000-0000-4000-8000-000000000001','f5100000-0000-4000-8000-000000000001','Race PIC');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id)
values('f5100000-0000-4000-8000-000000000001','f5300000-0000-4000-8000-000000000001','f5250000-0000-4000-8000-000000000001');
insert into public.inventory_items(id,account_id,sku,name,unit)
values('f5400000-0000-4000-8000-000000000001','f5100000-0000-4000-8000-000000000001','RACE','Race Item','pcs');
insert into public.inventory_locations(id,account_id,branch_id,code,name)
values('f5500000-0000-4000-8000-000000000001','f5100000-0000-4000-8000-000000000001','f5250000-0000-4000-8000-000000000001','RACE','Race Location');
insert into public.inventory_movements(id,account_id,inventory_item_id,location_id,movement_type,quantity,unit_snapshot,
  occurred_at,operational_person_id,operational_person_name_snapshot,reference_type,client_request_id,created_by,created_by_name_snapshot)
values('f5600000-0000-4000-8000-000000000001','f5100000-0000-4000-8000-000000000001','f5400000-0000-4000-8000-000000000001',
  'f5500000-0000-4000-8000-000000000001','opening_balance',5,'pcs','2026-08-27 08:00+07',
  'f5300000-0000-4000-8000-000000000001','Race PIC','opening_balance','f5700000-0000-4000-8000-000000000001',
  'f5000000-0000-4000-8000-000000000001','Inventory Race Owner');

do $$
declare generated_password text := (select password from inventory_race_secret);
  connection_string text := format('host=%s port=%s dbname=%s user=m24a_inventory_race password=%s sslmode=disable',host(inet_server_addr()),current_setting('port'),current_database(),generated_password);
begin
  perform extensions.dblink_connect('inventory_race_1',connection_string);
  perform extensions.dblink_connect('inventory_race_2',connection_string);
end;
$$;
select * from extensions.dblink('inventory_race_1',$$select set_config('request.jwt.claim.sub','f5000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
select * from extensions.dblink('inventory_race_2',$$select set_config('request.jwt.claim.sub','f5000000-0000-4000-8000-000000000001',false)$$) as configured(value text);

create temporary table inventory_race_backend(session_name text primary key,pid integer not null);
insert into inventory_race_backend
select 'inventory_race_1',pid from extensions.dblink('inventory_race_1','select pg_backend_pid()') as result(pid integer)
union all select 'inventory_race_2',pid from extensions.dblink('inventory_race_2','select pg_backend_pid()') as result(pid integer);

select extensions.is(extensions.dblink_send_query('inventory_race_1',$$with adjusted as materialized (
  select movement.id from public.adjust_inventory_stock(
    'f5100000-0000-4000-8000-000000000001','f5400000-0000-4000-8000-000000000001','f5500000-0000-4000-8000-000000000001',
    -4,'2026-08-27 09:00+07','f5300000-0000-4000-8000-000000000001','Race one',null,'f5700000-0000-4000-8000-000000000002') movement
) select adjusted.id from adjusted cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first concurrent stock deduction starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('inventory_race_2',$$select movement.id from public.adjust_inventory_stock(
  'f5100000-0000-4000-8000-000000000001','f5400000-0000-4000-8000-000000000001','f5500000-0000-4000-8000-000000000001',
  -4,'2026-08-27 09:01+07','f5300000-0000-4000-8000-000000000001','Race two',null,'f5700000-0000-4000-8000-000000000003') movement$$),1,'second concurrent stock deduction starts');
do $$
begin
  for attempt in 1..40 loop
    exit when exists(select 1 from pg_catalog.pg_stat_activity where pid=(select pid from inventory_race_backend where session_name='inventory_race_2') and wait_event_type='Lock');
    perform pg_sleep(.05);
  end loop;
end;
$$;
select extensions.ok((select movement_id is not null from extensions.dblink_get_result('inventory_race_1') as result(movement_id uuid)),'first stock deduction succeeds');
select extensions.is((select count(*)::int from extensions.dblink_get_result('inventory_race_2',false) as result(movement_id uuid)),0,'second overselling deduction returns no movement');
select extensions.ok(position('insufficient stock' in extensions.dblink_error_message('inventory_race_2'))>0,'concurrent loser receives insufficient-stock error');
select extensions.is((select quantity from public.inventory_stock_balances where inventory_item_id='f5400000-0000-4000-8000-000000000001'),1::numeric,'serialized deductions never make stock negative');

select extensions.dblink_disconnect('inventory_race_1');
select extensions.dblink_disconnect('inventory_race_2');
grant m24a_inventory_race to postgres;
drop owned by m24a_inventory_race;
drop role m24a_inventory_race;
select * from extensions.finish();
