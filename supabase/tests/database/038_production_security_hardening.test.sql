begin;
create extension if not exists pgtap with schema extensions;
select extensions.no_plan();

select extensions.is(
  (select count(*)::integer from pg_catalog.pg_class relation
    join pg_catalog.pg_namespace namespace on namespace.oid=relation.relnamespace
    where namespace.nspname='public' and relation.relkind='r' and not relation.relrowsecurity),
  0,
  'every public application table has RLS enabled'
);

select extensions.is(
  (select count(*)::integer from pg_catalog.pg_proc procedure
    join pg_catalog.pg_namespace namespace on namespace.oid=procedure.pronamespace
    where namespace.nspname='public' and procedure.prosecdef
      and not exists(select 1 from unnest(coalesce(procedure.proconfig,'{}'::text[])) setting where setting like 'search_path=%')),
  0,
  'every public SECURITY DEFINER function controls search_path'
);

select extensions.is(
  (select count(*)::integer from pg_catalog.pg_proc procedure
    join pg_catalog.pg_namespace namespace on namespace.oid=procedure.pronamespace
    where namespace.nspname='public' and pg_catalog.has_function_privilege('anon',procedure.oid,'execute')),
  0,
  'anonymous users cannot directly execute public application functions'
);

select extensions.is(
  (select count(*)::integer from pg_catalog.pg_class relation
    join pg_catalog.pg_namespace namespace on namespace.oid=relation.relnamespace
    where namespace.nspname='public' and relation.relkind='r'
      and (pg_catalog.has_table_privilege('anon',relation.oid,'select')
        or pg_catalog.has_table_privilege('anon',relation.oid,'insert')
        or pg_catalog.has_table_privilege('anon',relation.oid,'update')
        or pg_catalog.has_table_privilege('anon',relation.oid,'delete'))),
  0,
  'anonymous users have no application table privileges'
);

insert into auth.users(id,aud,role,email,encrypted_password,email_confirmed_at,raw_app_meta_data,raw_user_meta_data,created_at,updated_at)
values('f8000000-0000-4000-8000-000000000001','authenticated','authenticated','suspended-m28@test.invalid','x',now(),'{}','{}',now(),now());
insert into public.accounts(id,code,name) values('f8100000-0000-4000-8000-000000000001','M28-SUSP','M2.8 Suspended');
insert into public.branches(id,account_id,code,name) values('f8200000-0000-4000-8000-000000000001','f8100000-0000-4000-8000-000000000001','SUSP','Suspended Branch');
insert into public.account_memberships(id,account_id,user_id,role,status)
values('f8300000-0000-4000-8000-000000000001','f8100000-0000-4000-8000-000000000001','f8000000-0000-4000-8000-000000000001','admin','suspended');

set local role authenticated;
select set_config('request.jwt.claims','{"sub":"f8000000-0000-4000-8000-000000000001","role":"authenticated"}',true);
select extensions.is((select count(*)::integer from public.accounts where id='f8100000-0000-4000-8000-000000000001'),0,
  'suspended membership cannot read its account');
select extensions.is((select count(*)::integer from public.branches where id='f8200000-0000-4000-8000-000000000001'),0,
  'suspended membership cannot read its Branch');

select * from extensions.finish();
rollback;
