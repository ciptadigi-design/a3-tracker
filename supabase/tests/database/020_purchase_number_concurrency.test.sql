create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;
select extensions.no_plan();

do $$
begin
  if exists (select 1 from pg_catalog.pg_roles where rolname='m24d_purchase_number_race') then
    execute 'grant m24d_purchase_number_race to postgres';
    execute 'drop owned by m24d_purchase_number_race';
    execute 'drop role m24d_purchase_number_race';
  end if;
end;
$$;
create temporary table purchase_number_race_secret(password text not null);
insert into purchase_number_race_secret values(gen_random_uuid()::text);
do $$
declare generated_password text := (select password from purchase_number_race_secret);
begin
  execute format('create role m24d_purchase_number_race login password %L bypassrls',generated_password);
  grant usage on schema public to m24d_purchase_number_race;
  grant authenticated to m24d_purchase_number_race;
end;
$$;

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('fc000000-0000-4000-8000-000000000001','authenticated','authenticated','m24d-number-race@test.invalid','',now(),'{}','{"display_name":"Number Race Owner"}',now(),now());
insert into public.accounts(id,code,name) values('fc100000-0000-4000-8000-000000000001','M24D-NUM','M2.4D Purchase Number Race');
insert into public.account_memberships(id,account_id,user_id,role,status,accepted_at)
values('fc200000-0000-4000-8000-000000000001','fc100000-0000-4000-8000-000000000001','fc000000-0000-4000-8000-000000000001','owner','active',now());
insert into public.branches(id,account_id,code,name)
values('fc250000-0000-4000-8000-000000000001','fc100000-0000-4000-8000-000000000001','NUM','Number Branch');
insert into public.inventory_items(id,account_id,sku,name,unit)
values('fc300000-0000-4000-8000-000000000001','fc100000-0000-4000-8000-000000000001','NUM','Number Item','pcs');
insert into public.inventory_suppliers(id,account_id,supplier_code,name)
values('fc400000-0000-4000-8000-000000000001','fc100000-0000-4000-8000-000000000001','NUM','Number Supplier');

do $$
declare generated_password text := (select password from purchase_number_race_secret);
  connection_string text := format('host=%s port=%s dbname=%s user=m24d_purchase_number_race password=%s sslmode=disable',host(inet_server_addr()),current_setting('port'),current_database(),generated_password);
begin
  perform extensions.dblink_connect('m24d_number_race_1',connection_string);
  perform extensions.dblink_connect('m24d_number_race_2',connection_string);
end;
$$;
select * from extensions.dblink('m24d_number_race_1',$$select set_config('request.jwt.claim.sub','fc000000-0000-4000-8000-000000000001',false)$$) as configured(value text);
select * from extensions.dblink('m24d_number_race_2',$$select set_config('request.jwt.claim.sub','fc000000-0000-4000-8000-000000000001',false)$$) as configured(value text);

select extensions.is(extensions.dblink_send_query('m24d_number_race_1',$$with created as materialized (
  select purchase.purchase_number from public.create_inventory_purchase_auto(
    'fc100000-0000-4000-8000-000000000001','fc250000-0000-4000-8000-000000000001','fc400000-0000-4000-8000-000000000001','2026-08-01',null,'IDR',null,
    '[{"inventory_item_id":"fc300000-0000-4000-8000-000000000001","quantity":"1","unit_price":"10"}]'::jsonb,
    'fc500000-0000-4000-8000-000000000001') purchase
) select created.purchase_number from created cross join lateral(select pg_sleep(1)) hold_lock$$),1,'first number generation starts');
do $$ begin perform pg_sleep(.15); end $$;
select extensions.is(extensions.dblink_send_query('m24d_number_race_2',$$select purchase.purchase_number from public.create_inventory_purchase_auto(
  'fc100000-0000-4000-8000-000000000001','fc250000-0000-4000-8000-000000000001','fc400000-0000-4000-8000-000000000001','2026-08-02',null,'IDR',null,
  '[{"inventory_item_id":"fc300000-0000-4000-8000-000000000001","quantity":"1","unit_price":"20"}]'::jsonb,
  'fc500000-0000-4000-8000-000000000002') purchase$$),1,'second number generation starts');
select extensions.is((select purchase_number from extensions.dblink_get_result('m24d_number_race_1') as result(purchase_number text)),'PUR-202608-0001','first concurrent purchase gets first monthly number');
select extensions.is((select purchase_number from extensions.dblink_get_result('m24d_number_race_2') as result(purchase_number text)),'PUR-202608-0002','second concurrent purchase gets next monthly number');
select extensions.is((select count(distinct purchase_number)::int from public.inventory_purchases where account_id='fc100000-0000-4000-8000-000000000001'),2,'concurrent generation produces two unique internal numbers');
select extensions.is((select last_value from public.inventory_purchase_number_sequences where account_id='fc100000-0000-4000-8000-000000000001' and period_start='2026-08-01'),2,'database sequence records both concurrent allocations');

select extensions.dblink_disconnect('m24d_number_race_1');
select extensions.dblink_disconnect('m24d_number_race_2');
grant m24d_purchase_number_race to postgres;
drop owned by m24d_purchase_number_race;
drop role m24d_purchase_number_race;
select * from extensions.finish();
