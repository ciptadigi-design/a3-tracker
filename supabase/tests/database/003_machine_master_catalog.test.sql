begin;

create extension if not exists pgtap with schema extensions;

select extensions.no_plan();

-- Batch 2 relations must never expose privileges through the implicit PUBLIC role.
select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_class as relation
    cross join lateral pg_catalog.aclexplode(
      coalesce(
        relation.relacl,
        pg_catalog.acldefault('r', relation.relowner)
      )
    ) as privilege
    where relation.oid = any(array[
      'public.manufacturers'::regclass,
      'public.machine_models'::regclass,
      'public.machines'::regclass,
      'public.counter_types'::regclass
    ])
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no privileges on Batch 2 tables'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_attribute as attribute
    cross join lateral pg_catalog.aclexplode(attribute.attacl) as privilege
    where attribute.attrelid = any(array[
      'public.manufacturers'::regclass,
      'public.machine_models'::regclass,
      'public.machines'::regclass,
      'public.counter_types'::regclass
    ])
      and attribute.attnum > 0
      and not attribute.attisdropped
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no column privileges on Batch 2 tables'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_type as type
    cross join lateral pg_catalog.aclexplode(
      coalesce(
        type.typacl,
        pg_catalog.acldefault('T', type.typowner)
      )
    ) as privilege
    where type.oid = any(array[
      'public.machine_category'::regtype,
      'public.color_capability'::regtype,
      'public.machine_status'::regtype
    ])
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no privileges on Batch 2 enum types'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.set_archivable_catalog_audit_fields()'::regprocedure,
      'public.set_machine_audit_fields()'::regprocedure
    ])
      and function.prosecdef
  ),
  0,
  'Batch 2 introduces no SECURITY DEFINER functions'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    cross join lateral pg_catalog.aclexplode(
      coalesce(
        function.proacl,
        pg_catalog.acldefault('f', function.proowner)
      )
    ) as privilege
    where function.oid = any(array[
      'public.set_archivable_catalog_audit_fields()'::regprocedure,
      'public.set_machine_audit_fields()'::regprocedure
    ])
      and privilege.grantee = 0
      and privilege.privilege_type = 'EXECUTE'
  ),
  0,
  'PUBLIC cannot execute Batch 2 trigger functions'
);

-- Deterministic rollback-only tenant fixtures. Shared catalog fixtures come
-- from seed.sql, while this test creates no persistent physical machine.
insert into auth.users (
  id, aud, role, email, encrypted_password, email_confirmed_at,
  raw_app_meta_data, raw_user_meta_data, created_at, updated_at
)
values
  ('60000000-0000-0000-0000-000000000001', 'authenticated', 'authenticated', 'batch2-owner-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('60000000-0000-0000-0000-000000000002', 'authenticated', 'authenticated', 'batch2-admin-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('60000000-0000-0000-0000-000000000003', 'authenticated', 'authenticated', 'batch2-technician-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('60000000-0000-0000-0000-000000000004', 'authenticated', 'authenticated', 'batch2-operator-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('60000000-0000-0000-0000-000000000005', 'authenticated', 'authenticated', 'batch2-owner-b@test.invalid', '', now(), '{}', '{}', now(), now());

insert into public.accounts (id, code, name, created_by, updated_by)
values
  ('61000000-0000-0000-0000-000000000001', 'BATCH2-A', 'Batch 2 Account A', '60000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001'),
  ('61000000-0000-0000-0000-000000000002', 'BATCH2-B', 'Batch 2 Account B', '60000000-0000-0000-0000-000000000005', '60000000-0000-0000-0000-000000000005');

insert into public.account_memberships (
  id, account_id, user_id, role, status, accepted_at, created_by, updated_by
)
values
  ('62000000-0000-0000-0000-000000000001', '61000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001', 'owner', 'active', now(), '60000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001'),
  ('62000000-0000-0000-0000-000000000002', '61000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000002', 'admin', 'active', now(), '60000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001'),
  ('62000000-0000-0000-0000-000000000003', '61000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000003', 'technician', 'active', now(), '60000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001'),
  ('62000000-0000-0000-0000-000000000004', '61000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000004', 'operator', 'active', now(), '60000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001'),
  ('62000000-0000-0000-0000-000000000005', '61000000-0000-0000-0000-000000000002', '60000000-0000-0000-0000-000000000005', 'owner', 'active', now(), '60000000-0000-0000-0000-000000000005', '60000000-0000-0000-0000-000000000005');

insert into public.branches (id, account_id, code, name, created_by, updated_by)
values
  ('63000000-0000-0000-0000-000000000001', '61000000-0000-0000-0000-000000000001', 'BATCH2-A-MAIN', 'Batch 2 A Main', '60000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001'),
  ('63000000-0000-0000-0000-000000000002', '61000000-0000-0000-0000-000000000002', 'BATCH2-B-MAIN', 'Batch 2 B Main', '60000000-0000-0000-0000-000000000005', '60000000-0000-0000-0000-000000000005');

insert into public.machines (
  id, account_id, branch_id, machine_model_id, machine_code, display_name,
  serial_number, created_by, updated_by
)
values (
  '64000000-0000-0000-0000-000000000001',
  '61000000-0000-0000-0000-000000000002',
  '63000000-0000-0000-0000-000000000002',
  '51000000-0000-0000-0000-000000000001',
  'B-01',
  'Account B Machine',
  'B-SERIAL-01',
  '60000000-0000-0000-0000-000000000005',
  '60000000-0000-0000-0000-000000000005'
);

set local role anon;
select extensions.throws_ok(
  'select * from public.manufacturers', '42501', null,
  'anonymous cannot read manufacturers'
);
select extensions.throws_ok(
  'select * from public.machine_models', '42501', null,
  'anonymous cannot read machine models'
);
select extensions.throws_ok(
  'select * from public.counter_types', '42501', null,
  'anonymous cannot read counter types'
);
select extensions.throws_ok(
  'select * from public.machines', '42501', null,
  'anonymous cannot read machines'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '60000000-0000-0000-0000-000000000003', true);
select extensions.is(
  (select count(*)::integer from public.manufacturers),
  1,
  'authenticated technician can read active manufacturers'
);
select extensions.is(
  (select count(*)::integer from public.machine_models),
  1,
  'authenticated technician can read active machine models'
);
select extensions.is(
  (select count(*)::integer from public.counter_types),
  4,
  'authenticated technician can read active counter types'
);
select extensions.is(
  (select count(*)::integer from public.machines),
  0,
  'technician cannot read another account machine'
);
select extensions.throws_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000001',
      '51000000-0000-0000-0000-000000000001',
      'TECH-01',
      'Technician Machine'
    )$$,
  '42501', null,
  'technician cannot create a machine'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '60000000-0000-0000-0000-000000000001', true);
select extensions.throws_ok(
  $$update public.manufacturers
    set name = 'Tenant Modified Manufacturer'
    where id = '50000000-0000-0000-0000-000000000001'$$,
  '42501', null,
  'tenant owner cannot update the shared manufacturer catalog'
);
select extensions.throws_ok(
  $$insert into public.counter_types (code, name, unit)
    values ('tenant_counter', 'Tenant Counter', 'units')$$,
  '42501', null,
  'tenant owner cannot insert into the shared counter catalog'
);
select extensions.lives_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name,
      serial_number, timezone
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000001',
      '51000000-0000-0000-0000-000000000001',
      'A-OWNER-01',
      'Owner Created Machine',
      'SERIAL-OWNER-01',
      'Asia/Jakarta'
    )$$,
  'owner can create a machine in their own branch'
);
select extensions.throws_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000002',
      '51000000-0000-0000-0000-000000000001',
      'CROSS-BRANCH',
      'Cross Account Branch'
    )$$,
  '23503', null,
  'cross-account branch assignment is rejected by the composite foreign key'
);
select extensions.is(
  (select count(*)::integer from public.machines),
  1,
  'owner reads only machines in their own account'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '60000000-0000-0000-0000-000000000002', true);
select extensions.throws_ok(
  $$update public.machine_models
    set name = 'Admin Modified Shared Model'
    where id = '51000000-0000-0000-0000-000000000001'$$,
  '42501', null,
  'tenant admin cannot update the shared machine model catalog'
);
select extensions.lives_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name,
      serial_number
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000001',
      '51000000-0000-0000-0000-000000000001',
      'A-ADMIN-01',
      'Admin Created Machine',
      'SERIAL-ADMIN-01'
    )$$,
  'admin can create a machine'
);
select extensions.results_eq(
  $$update public.machines
    set display_name = 'Admin Updated Machine', status = 'maintenance'
    where account_id = '61000000-0000-0000-0000-000000000001'
      and machine_code = 'A-ADMIN-01'
    returning display_name$$,
  $$values ('Admin Updated Machine'::text)$$,
  'admin can update a machine'
);
select extensions.throws_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000001',
      '51000000-0000-0000-0000-000000000001',
      ' a-admin-01 ',
      'Duplicate Machine Code'
    )$$,
  '23505', null,
  'normalized machine code is unique within an account'
);
select extensions.throws_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name,
      serial_number
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000001',
      '51000000-0000-0000-0000-000000000001',
      'A-DUP-SERIAL',
      'Duplicate Serial',
      ' serial-admin-01 '
    )$$,
  '23505', null,
  'duplicate normalized serial is blocked within account and model'
);
select extensions.lives_ok(
  $$update public.machines
    set is_active = false
    where account_id = '61000000-0000-0000-0000-000000000001'
      and machine_code = 'A-ADMIN-01'$$,
  'admin can archive and retire a machine'
);
select extensions.is(
  (
    select status::text
    from public.machines
    where account_id = '61000000-0000-0000-0000-000000000001'
      and machine_code = 'A-ADMIN-01'
  ),
  'retired',
  'archiving a machine sets retired status'
);
select extensions.throws_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000001',
      '51000000-0000-0000-0000-000000000001',
      ' A-ADMIN-01 ',
      'Archived Code Reuse'
    )$$,
  '23505', null,
  'archived machine code remains reserved'
);
select extensions.throws_ok(
  $$insert into public.machines (
      account_id, branch_id, machine_model_id, machine_code, display_name,
      status
    ) values (
      '61000000-0000-0000-0000-000000000001',
      '63000000-0000-0000-0000-000000000001',
      '51000000-0000-0000-0000-000000000001',
      'BAD-STATUS',
      'Bad Status',
      'offline'
    )$$,
  '22P02', null,
  'invalid machine status is rejected'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '60000000-0000-0000-0000-000000000004', true);
select extensions.is_empty(
  $$update public.machines
    set display_name = 'Operator Updated Machine'
    where account_id = '61000000-0000-0000-0000-000000000001'
    returning 1$$,
  'operator cannot update a machine'
);
select extensions.is(
  (select count(*)::integer from public.machines),
  2,
  'operator can read machines in their account'
);
reset role;

select extensions.throws_ok(
  $$insert into public.machine_models (
      manufacturer_id, model_code, name, machine_category, color_capability
    ) values (
      '50000000-0000-0000-0000-000000000001',
      'BAD-CATEGORY',
      'Bad Category',
      'offset',
      'color'
    )$$,
  '22P02', null,
  'invalid machine category is rejected'
);
select extensions.throws_ok(
  $$insert into public.machine_models (
      manufacturer_id, model_code, name, machine_category, color_capability
    ) values (
      '50000000-0000-0000-0000-000000000001',
      'BAD-COLOR',
      'Bad Color Capability',
      'digital_a3',
      'spot_color'
    )$$,
  '22P02', null,
  'invalid color capability is rejected'
);

select extensions.finish();

rollback;
