begin;

create extension if not exists pgtap with schema extensions;

select extensions.no_plan();

-- M2.1 privilege and SECURITY DEFINER contract.
select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_class as relation
    cross join lateral pg_catalog.aclexplode(
      coalesce(relation.relacl, pg_catalog.acldefault('r', relation.relowner))
    ) as privilege
    where relation.oid = any(array[
      'public.counter_readings'::regclass,
      'public.machine_counter_history'::regclass
    ])
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no privileges on M2.1 relations'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_type as type
    cross join lateral pg_catalog.aclexplode(
      coalesce(type.typacl, pg_catalog.acldefault('T', type.typowner))
    ) as privilege
    where type.oid = 'public.counter_reading_status'::regtype
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no privileges on the counter reading status type'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.record_machine_counter(uuid,uuid,numeric,timestamptz,uuid,uuid,text,text,text)'::regprocedure,
      'public.correct_machine_counter(uuid,text,numeric,uuid,text)'::regprocedure
    ])
      and function.prosecdef
  ),
  2,
  'both counter write APIs are SECURITY DEFINER'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    join pg_catalog.pg_roles as owner on owner.oid = function.proowner
    where function.oid = any(array[
      'public.record_machine_counter(uuid,uuid,numeric,timestamptz,uuid,uuid,text,text,text)'::regprocedure,
      'public.correct_machine_counter(uuid,text,numeric,uuid,text)'::regprocedure
    ])
      and owner.rolname = 'postgres'
      and function.proconfig @> array['search_path=""']::text[]
  ),
  2,
  'counter write APIs are postgres-owned with an empty fixed search_path'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    cross join lateral pg_catalog.aclexplode(
      coalesce(function.proacl, pg_catalog.acldefault('f', function.proowner))
    ) as privilege
    where function.oid = any(array[
      'public.protect_counter_reading_history()'::regprocedure,
      'public.record_machine_counter(uuid,uuid,numeric,timestamptz,uuid,uuid,text,text,text)'::regprocedure,
      'public.correct_machine_counter(uuid,text,numeric,uuid,text)'::regprocedure
    ])
      and privilege.grantee = 0
      and privilege.privilege_type = 'EXECUTE'
  ),
  0,
  'PUBLIC cannot execute M2.1 functions'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.record_machine_counter(uuid,uuid,numeric,timestamptz,uuid,uuid,text,text,text)'::regprocedure,
      'public.correct_machine_counter(uuid,text,numeric,uuid,text)'::regprocedure
    ])
      and has_function_privilege('authenticated', function.oid, 'EXECUTE')
  ),
  2,
  'authenticated users can execute only the intended counter APIs'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.protect_counter_reading_history()'::regprocedure,
      'public.record_machine_counter(uuid,uuid,numeric,timestamptz,uuid,uuid,text,text,text)'::regprocedure,
      'public.correct_machine_counter(uuid,text,numeric,uuid,text)'::regprocedure
    ])
      and (
        has_function_privilege('anon', function.oid, 'EXECUTE')
        or has_function_privilege('service_role', function.oid, 'EXECUTE')
      )
  ),
  0,
  'anonymous and service roles cannot execute counter APIs or trigger functions'
);

-- Deterministic rollback-only tenant and machine fixtures.
insert into auth.users (
  id, aud, role, email, encrypted_password, email_confirmed_at,
  raw_app_meta_data, raw_user_meta_data, created_at, updated_at
)
values
  ('70000000-0000-0000-0000-000000000001', 'authenticated', 'authenticated', 'm21-owner-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('70000000-0000-0000-0000-000000000002', 'authenticated', 'authenticated', 'm21-admin-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('70000000-0000-0000-0000-000000000003', 'authenticated', 'authenticated', 'm21-technician-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('70000000-0000-0000-0000-000000000004', 'authenticated', 'authenticated', 'm21-operator-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('70000000-0000-0000-0000-000000000005', 'authenticated', 'authenticated', 'm21-suspended-a@test.invalid', '', now(), '{}', '{}', now(), now()),
  ('70000000-0000-0000-0000-000000000006', 'authenticated', 'authenticated', 'm21-owner-b@test.invalid', '', now(), '{}', '{}', now(), now());

insert into public.accounts (id, code, name, created_by, updated_by)
values
  ('71000000-0000-0000-0000-000000000001', 'M21-A', 'M2.1 Account A', '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('71000000-0000-0000-0000-000000000002', 'M21-B', 'M2.1 Account B', '70000000-0000-0000-0000-000000000006', '70000000-0000-0000-0000-000000000006');

insert into public.account_memberships (
  id, account_id, user_id, role, status, accepted_at, created_by, updated_by
)
values
  ('72000000-0000-0000-0000-000000000001', '71000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001', 'owner', 'active', now(), '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('72000000-0000-0000-0000-000000000002', '71000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000002', 'admin', 'active', now(), '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('72000000-0000-0000-0000-000000000003', '71000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000003', 'technician', 'active', now(), '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('72000000-0000-0000-0000-000000000004', '71000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000004', 'operator', 'active', now(), '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('72000000-0000-0000-0000-000000000005', '71000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000005', 'operator', 'suspended', now(), '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('72000000-0000-0000-0000-000000000006', '71000000-0000-0000-0000-000000000002', '70000000-0000-0000-0000-000000000006', 'owner', 'active', now(), '70000000-0000-0000-0000-000000000006', '70000000-0000-0000-0000-000000000006');

insert into public.branches (id, account_id, code, name, created_by, updated_by)
values
  ('73000000-0000-0000-0000-000000000001', '71000000-0000-0000-0000-000000000001', 'M21-A-MAIN', 'M2.1 A Main', '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('73000000-0000-0000-0000-000000000002', '71000000-0000-0000-0000-000000000002', 'M21-B-MAIN', 'M2.1 B Main', '70000000-0000-0000-0000-000000000006', '70000000-0000-0000-0000-000000000006');

insert into public.operational_people (id, account_id, name, code, created_by, updated_by)
values
  ('76000000-0000-4000-8000-000000000001'::uuid, '71000000-0000-0000-0000-000000000001', 'M2.1 Test PIC', 'M21-PIC', '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001');

insert into public.machines (
  id, account_id, branch_id, machine_model_id, machine_code, display_name,
  created_by, updated_by
)
values
  ('74000000-0000-4000-8000-000000000001', '71000000-0000-0000-0000-000000000001', '73000000-0000-0000-0000-000000000001', '51000000-0000-0000-0000-000000000001', 'M21-A-01', 'M2.1 Account A Machine 1', '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('74000000-0000-4000-8000-000000000002', '71000000-0000-0000-0000-000000000001', '73000000-0000-0000-0000-000000000001', '51000000-0000-0000-0000-000000000001', 'M21-A-02', 'M2.1 Account A Machine 2', '70000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001'),
  ('74000000-0000-4000-8000-000000000003', '71000000-0000-0000-0000-000000000002', '73000000-0000-0000-0000-000000000002', '51000000-0000-0000-0000-000000000001', 'M21-B-01', 'M2.1 Account B Machine', '70000000-0000-0000-0000-000000000006', '70000000-0000-0000-0000-000000000006');

set local role anon;
select extensions.throws_ok(
  'select * from public.counter_readings', '42501', null,
  'anonymous cannot read counter readings'
);
select extensions.throws_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    1, '2026-08-23 08:00+07',
    '75000000-0000-4000-8000-000000000001',
    '76000000-0000-4000-8000-000000000001'::uuid
  )$$,
  '42501', null,
  'anonymous cannot execute counter submission'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '70000000-0000-0000-0000-000000000001', true);
select extensions.throws_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    99, '2026-08-23 07:00+07',
    '75000000-0000-4000-8000-000000000097'
  )$$,
  '42501', null,
  'legacy counter API cannot bypass required operator selection'
);
select extensions.lives_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    100, '2026-08-23 08:00+07',
    '75000000-0000-4000-8000-000000000001',
    '76000000-0000-4000-8000-000000000001'::uuid,
    null, 'First baseline'
  )$$,
  'owner can create a counter reading'
);
select extensions.is(
  (
    select usage
    from public.machine_counter_history
    where client_request_id = '75000000-0000-4000-8000-000000000001'
  ),
  null::numeric,
  'first reading produces no usage baseline'
);
select extensions.throws_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000003',
    1, '2026-08-23 09:00+07',
    '75000000-0000-4000-8000-000000000099',
    '76000000-0000-4000-8000-000000000001'::uuid
  )$$,
  'P0002', null,
  'cross-account machine submission is denied'
);
select extensions.throws_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    -1, '2026-08-23 09:00+07',
    '75000000-0000-4000-8000-000000000098',
    '76000000-0000-4000-8000-000000000001'::uuid
  )$$,
  '22003', null,
  'negative counter submission is denied'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '70000000-0000-0000-0000-000000000002', true);
select extensions.lives_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    150, '2026-08-23 10:00+07',
    '75000000-0000-4000-8000-000000000002',
    '76000000-0000-4000-8000-000000000001'::uuid,
    'S1'
  )$$,
  'admin can create an S1 reading'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '70000000-0000-0000-0000-000000000003', true);
select extensions.lives_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    180, '2026-08-23 14:00+07',
    '75000000-0000-4000-8000-000000000003',
    '76000000-0000-4000-8000-000000000001'::uuid,
    'S2'
  )$$,
  'technician can create an S2 reading'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '70000000-0000-0000-0000-000000000004', true);
select extensions.lives_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    200, '2026-08-23 20:00+07',
    '75000000-0000-4000-8000-000000000004',
    '76000000-0000-4000-8000-000000000001'::uuid,
    'S2'
  )$$,
  'operator can create another valid reading on the same day'
);
select extensions.is(
  (
    select count(*)::integer
    from public.counter_readings
    where machine_id = '74000000-0000-4000-8000-000000000001'
      and observed_at::date = '2026-08-23'
  ),
  4,
  'multiple readings on the same day are stored'
);
select extensions.is(
  (
    select previous_reading_id
    from public.counter_readings
    where client_request_id = '75000000-0000-4000-8000-000000000004'
  ),
  (
    select id
    from public.counter_readings
    where client_request_id = '75000000-0000-4000-8000-000000000003'
  ),
  'previous_reading_id resolves to the prior effective reading'
);
select extensions.is(
  (
    select usage
    from public.machine_counter_history
    where client_request_id = '75000000-0000-4000-8000-000000000004'
  ),
  20::numeric,
  'history derives the correct usage delta'
);
select extensions.lives_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000002',
    50, '2026-08-23 20:00+07',
    '75000000-0000-4000-8000-000000000005',
    '76000000-0000-4000-8000-000000000001'::uuid,
    'S2'
  )$$,
  'missing S1 does not block an S2 baseline on another machine'
);
select extensions.throws_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    190, '2026-08-23 21:00+07',
    '75000000-0000-4000-8000-000000000006',
    '76000000-0000-4000-8000-000000000001'::uuid
  )$$,
  '22003', null,
  'monotonic regression is denied'
);
select extensions.is(
  (
    select (public.record_machine_counter(
      '71000000-0000-0000-0000-000000000001',
      '74000000-0000-4000-8000-000000000001',
      200, '2026-08-23 20:00+07',
      '75000000-0000-4000-8000-000000000004',
      '76000000-0000-4000-8000-000000000001'::uuid,
      'S2'
    )).id
  ),
  (
    select id
    from public.counter_readings
    where client_request_id = '75000000-0000-4000-8000-000000000004'
  ),
  'duplicate client request safely returns the original reading'
);
select extensions.is(
  (
    select count(*)::integer
    from public.counter_readings
    where client_request_id = '75000000-0000-4000-8000-000000000004'
  ),
  1,
  'idempotent retry creates no duplicate row'
);
select extensions.throws_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    201, '2026-08-23 20:00+07',
    '75000000-0000-4000-8000-000000000004',
    '76000000-0000-4000-8000-000000000001'::uuid,
    'S2'
  )$$,
  '23505', null,
  'reusing a client request id for different data is rejected'
);
select extensions.throws_ok(
  $$update public.counter_readings
    set reading_value = 999
    where client_request_id = '75000000-0000-4000-8000-000000000004'$$,
  '42501', null,
  'operator cannot silently modify historical reading values'
);
select extensions.throws_ok(
  $$select public.correct_machine_counter(
    (select id from public.counter_readings where client_request_id = '75000000-0000-4000-8000-000000000004'),
    'Operator correction attempt', 205,
    '75000000-0000-4000-8000-000000000007'
  )$$,
  '42501', null,
  'operator cannot correct a reading'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '70000000-0000-0000-0000-000000000001', true);
select extensions.lives_ok(
  $$select public.correct_machine_counter(
    (select id from public.counter_readings where client_request_id = '75000000-0000-4000-8000-000000000004'),
    'Meter was transcribed incorrectly', 205,
    '75000000-0000-4000-8000-000000000007',
    'Owner-corrected latest reading'
  )$$,
  'owner can supersede the latest effective reading'
);
select extensions.is(
  (
    select status::text
    from public.counter_readings
    where client_request_id = '75000000-0000-4000-8000-000000000004'
  ),
  'superseded',
  'superseded source reading remains in history'
);
select extensions.is(
  (
    select usage
    from public.machine_counter_history
    where client_request_id = '75000000-0000-4000-8000-000000000007'
  ),
  25::numeric,
  'replacement reading derives usage from the prior effective baseline'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '70000000-0000-0000-0000-000000000002', true);
select extensions.lives_ok(
  $$select public.correct_machine_counter(
    (select id from public.counter_readings where client_request_id = '75000000-0000-4000-8000-000000000007'),
    'Reading should not have been recorded'
  )$$,
  'admin can void the latest effective reading'
);
select extensions.is(
  (
    select status::text
    from public.counter_readings
    where client_request_id = '75000000-0000-4000-8000-000000000007'
  ),
  'voided',
  'voided reading remains auditable'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '70000000-0000-0000-0000-000000000005', true);
select extensions.is(
  (select count(*)::integer from public.counter_readings),
  0,
  'suspended member cannot read counter history'
);
select extensions.throws_ok(
  $$select public.record_machine_counter(
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    210, '2026-08-23 22:00+07',
    '75000000-0000-4000-8000-000000000008',
    '76000000-0000-4000-8000-000000000001'::uuid
  )$$,
  '42501', null,
  'suspended member cannot submit counter readings'
);
reset role;

-- The base constraint remains authoritative outside the API as well.
select extensions.throws_ok(
  $$insert into public.counter_readings (
    account_id, machine_id, counter_type_id, reading_value, observed_at,
    entered_by, client_request_id, created_by
  ) values (
    '71000000-0000-0000-0000-000000000001',
    '74000000-0000-4000-8000-000000000001',
    '52000000-0000-0000-0000-000000000001',
    -1, now(),
    '70000000-0000-0000-0000-000000000001',
    '75000000-0000-4000-8000-000000000009',
    '70000000-0000-0000-0000-000000000001'
  )$$,
  '23514', null,
  'database check constraint rejects a negative reading'
);

select extensions.finish();

rollback;
