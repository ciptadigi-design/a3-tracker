create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$
begin
  if exists (select 1 from pg_catalog.pg_roles where rolname='m24c_receiving_race') then
    execute 'grant m24c_receiving_race to postgres';
    execute 'drop owned by m24c_receiving_race';
    execute 'drop role m24c_receiving_race';
  end if;
end;
$$;
create temporary table receiving_race_secret(password text not null);
insert into receiving_race_secret values(gen_random_uuid()::text);
do $$
declare generated_password text := (select password from receiving_race_secret);
begin
  execute format('create role m24c_receiving_race login password %L bypassrls',generated_password);
  grant usage on schema public to m24c_receiving_race;
  grant authenticated to m24c_receiving_race;
end;
$$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('ee000000-0000-4000-8000-000000000001','authenticated','authenticated','m24c-race@test.invalid','',now(),'{}','{"display_name":"M24C Race Owner"}',now(),now());
insert into public.accounts(id,code,name) values('ee100000-0000-4000-8000-000000000001','M24C-RACE','M2.4C Receiving Race');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values('ee200000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','ee000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.branches(id,account_id,code,name)
values('ee250000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','RACE','Race Branch');
insert into public.operational_people(id,account_id,name)
values('ee300000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','Race Receiver');
insert into public.operational_person_branches(account_id,operational_person_id,branch_id)
values('ee100000-0000-4000-8000-000000000001','ee300000-0000-4000-8000-000000000001','ee250000-0000-4000-8000-000000000001');
insert into public.inventory_items(id,account_id,sku,name,unit)
values('ee400000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','RACE','Race Receipt Item','bottle');
insert into public.inventory_locations(id,account_id,branch_id,code,name)
values('ee500000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','ee250000-0000-4000-8000-000000000001','RACE','Race Warehouse');
insert into public.inventory_suppliers(id,account_id,supplier_code,name)
values('ee600000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','RACE','Race Supplier');
insert into public.inventory_purchases(id,account_id,branch_id,supplier_id,purchase_number,purchase_date,currency_code,status,
  supplier_code_snapshot,supplier_name_snapshot,client_request_id,created_by,created_by_name_snapshot,updated_by)
values('ee700000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','ee250000-0000-4000-8000-000000000001','ee600000-0000-4000-8000-000000000001',
  'PUR-RACE','2026-08-27','IDR','draft','RACE','Race Supplier','ee710000-0000-4000-8000-000000000001',
  'ee000000-0000-4000-8000-000000000001','M24C Race Owner','ee000000-0000-4000-8000-000000000001');
insert into public.inventory_purchase_lines(id,account_id,purchase_id,inventory_item_id,ordered_quantity,unit_price,
  item_sku_snapshot,item_name_snapshot,unit_snapshot)
values('ee800000-0000-4000-8000-000000000001','ee100000-0000-4000-8000-000000000001','ee700000-0000-4000-8000-000000000001',
  'ee400000-0000-4000-8000-000000000001',4,100,'RACE','Race Receipt Item','bottle');

do $$
declare generated_password text := (select password from receiving_race_secret);
  connection_string text := format('host=%s port=%s dbname=%s user=m24c_receiving_race password=%s sslmode=disable',host(inet_server_addr()),current_setting('port'),current_database(),generated_password);
begin
  perform extensions.dblink_connect('m24c_receiving_race_1',connection_string);
  perform extensions.dblink_connect('m24c_receiving_race_2',connection_string);
end;
$$;
select * from extensions.dblink('m24c_receiving_race_1',$$select set_config('request.jwt.claim.sub','ee000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
select * from extensions.dblink('m24c_receiving_race_2',$$select set_config('request.jwt.claim.sub','ee000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
create temporary table receiving_race_backend(session_name text primary key,pid integer not null);
insert into receiving_race_backend
select 'm24c_receiving_race_1',pid from extensions.dblink('m24c_receiving_race_1','select pg_backend_pid()') as result(pid integer)
union all select 'm24c_receiving_race_2',pid from extensions.dblink('m24c_receiving_race_2','select pg_backend_pid()') as result(pid integer);

select extensions.is(extensions.dblink_send_query('m24c_receiving_race_1',$$with received as materialized (
  select receipt.id from public.receive_inventory_purchase(
    'ee100000-0000-4000-8000-000000000001','ee700000-0000-4000-8000-000000000001','ee500000-0000-4000-8000-000000000001',
    '2026-08-27 10:00+07','ee300000-0000-4000-8000-000000000001','Race one',
    '[{"purchase_line_id":"ee800000-0000-4000-8000-000000000001","quantity":"4"}]'::jsonb,
    'ee900000-0000-4000-8000-000000000001') receipt
) select received.id from received cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first final-quantity receipt starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m24c_receiving_race_2',$$select receipt.id from public.receive_inventory_purchase(
  'ee100000-0000-4000-8000-000000000001','ee700000-0000-4000-8000-000000000001','ee500000-0000-4000-8000-000000000001',
  '2026-08-27 10:01+07','ee300000-0000-4000-8000-000000000001','Race two',
  '[{"purchase_line_id":"ee800000-0000-4000-8000-000000000001","quantity":"4"}]'::jsonb,
  'ee900000-0000-4000-8000-000000000002') receipt$$),1,'second final-quantity receipt starts');
do $$
begin
  for attempt in 1..40 loop
    exit when exists(select 1 from pg_catalog.pg_stat_activity where pid=(select pid from receiving_race_backend where session_name='m24c_receiving_race_2') and wait_event_type='Lock');
    perform pg_sleep(.05);
  end loop;
end;
$$;
select extensions.ok((select receipt_id is not null from extensions.dblink_get_result('m24c_receiving_race_1') as result(receipt_id uuid)),'first concurrent receipt succeeds');
select extensions.is((select count(*)::int from extensions.dblink_get_result('m24c_receiving_race_2',false) as result(receipt_id uuid)),0,'second concurrent receipt returns no receipt');
select extensions.ok(position('already fully received' in extensions.dblink_error_message('m24c_receiving_race_2'))>0,'concurrent loser receives fully-received error');
select extensions.is((select count(*)::int from public.inventory_receipts where purchase_id='ee700000-0000-4000-8000-000000000001'),1,'race commits exactly one receipt');
select extensions.is((select count(*)::int from public.inventory_movements where reference_type='purchase_receipt' and account_id='ee100000-0000-4000-8000-000000000001'),1,'race commits exactly one receipt movement');
select extensions.is((select received_quantity from public.inventory_purchase_line_status where purchase_line_id='ee800000-0000-4000-8000-000000000001'),4::numeric,'race cannot over-receive ordered quantity');
select extensions.is((select quantity from public.inventory_item_totals where inventory_item_id='ee400000-0000-4000-8000-000000000001'),4::numeric,'concurrent receiving adds stock exactly once');
select extensions.is((select status::text from public.inventory_purchases where id='ee700000-0000-4000-8000-000000000001'),'received','winning receipt completes purchase');

select extensions.dblink_disconnect('m24c_receiving_race_1');
select extensions.dblink_disconnect('m24c_receiving_race_2');
grant m24c_receiving_race to postgres;
drop owned by m24c_receiving_race;
drop role m24c_receiving_race;
select * from extensions.finish();
