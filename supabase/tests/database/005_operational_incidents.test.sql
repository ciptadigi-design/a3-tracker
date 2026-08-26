begin;

create extension if not exists pgtap with schema extensions;

select extensions.no_plan();

-- M2.2 privilege and API contract.
select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_class as relation
    cross join lateral pg_catalog.aclexplode(
      coalesce(relation.relacl, pg_catalog.acldefault('r', relation.relowner))
    ) as privilege
    where relation.oid = 'public.operational_incidents'::regclass
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no privileges on operational incidents'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_type as type
    cross join lateral pg_catalog.aclexplode(
      coalesce(type.typacl, pg_catalog.acldefault('T', type.typowner))
    ) as privilege
    where type.oid = any(array[
      'public.operational_incident_category'::regtype,
      'public.operational_incident_type'::regtype,
      'public.operational_incident_status'::regtype
    ])
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no privileges on M2.2 enum types'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    cross join lateral pg_catalog.aclexplode(
      coalesce(function.proacl, pg_catalog.acldefault('f', function.proowner))
    ) as privilege
    where function.oid = any(array[
      'public.protect_operational_incident_history()'::regprocedure,
      'public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text)'::regprocedure,
      'public.resolve_operational_incident(uuid)'::regprocedure,
      'public.void_operational_incident(uuid,text)'::regprocedure
    ])
      and privilege.grantee = 0
      and privilege.privilege_type = 'EXECUTE'
  ),
  0,
  'PUBLIC cannot execute M2.2 functions'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.create_operational_incident(uuid,uuid,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text)'::regprocedure,
      'public.resolve_operational_incident(uuid)'::regprocedure,
      'public.void_operational_incident(uuid,text)'::regprocedure
    ])
      and function.prosecdef
      and function.proconfig @> array['search_path=""']::text[]
  ),
  3,
  'incident mutation APIs are SECURITY DEFINER with an empty search_path'
);

-- Deterministic rollback-only fixtures.
insert into auth.users (
  id, aud, role, email, encrypted_password, email_confirmed_at,
  raw_app_meta_data, raw_user_meta_data, created_at, updated_at
)
values
  ('80000000-0000-0000-0000-000000000001', 'authenticated', 'authenticated', 'm22-owner-a@test.invalid', '', now(), '{}', '{"display_name":"Owner A"}', now(), now()),
  ('80000000-0000-0000-0000-000000000002', 'authenticated', 'authenticated', 'm22-admin-a@test.invalid', '', now(), '{}', '{"display_name":"Admin A"}', now(), now()),
  ('80000000-0000-0000-0000-000000000003', 'authenticated', 'authenticated', 'm22-technician-a@test.invalid', '', now(), '{}', '{"display_name":"Technician A"}', now(), now()),
  ('80000000-0000-0000-0000-000000000004', 'authenticated', 'authenticated', 'm22-operator-a@test.invalid', '', now(), '{}', '{"display_name":"Operator A"}', now(), now()),
  ('80000000-0000-0000-0000-000000000005', 'authenticated', 'authenticated', 'm22-suspended-a@test.invalid', '', now(), '{}', '{"display_name":"Suspended A"}', now(), now()),
  ('80000000-0000-0000-0000-000000000006', 'authenticated', 'authenticated', 'm22-owner-b@test.invalid', '', now(), '{}', '{"display_name":"Owner B"}', now(), now());

insert into public.accounts (id, code, name, created_by, updated_by)
values
  ('81000000-0000-0000-0000-000000000001', 'M22-A', 'M2.2 Account A', '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('81000000-0000-0000-0000-000000000002', 'M22-B', 'M2.2 Account B', '80000000-0000-0000-0000-000000000006', '80000000-0000-0000-0000-000000000006');

insert into public.account_memberships (
  id, account_id, user_id, role, status, accepted_at, created_by, updated_by
)
values
  ('82000000-0000-0000-0000-000000000001', '81000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001', 'owner', 'active', now(), '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('82000000-0000-0000-0000-000000000002', '81000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000002', 'admin', 'active', now(), '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('82000000-0000-0000-0000-000000000003', '81000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000003', 'technician', 'active', now(), '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('82000000-0000-0000-0000-000000000004', '81000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000004', 'operator', 'active', now(), '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('82000000-0000-0000-0000-000000000005', '81000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000005', 'operator', 'suspended', now(), '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('82000000-0000-0000-0000-000000000006', '81000000-0000-0000-0000-000000000002', '80000000-0000-0000-0000-000000000006', 'owner', 'active', now(), '80000000-0000-0000-0000-000000000006', '80000000-0000-0000-0000-000000000006');

insert into public.branches (id, account_id, code, name, created_by, updated_by)
values
  ('83000000-0000-0000-0000-000000000001', '81000000-0000-0000-0000-000000000001', 'M22-A-MAIN', 'M2.2 A Main', '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('83000000-0000-0000-0000-000000000002', '81000000-0000-0000-0000-000000000002', 'M22-B-MAIN', 'M2.2 B Main', '80000000-0000-0000-0000-000000000006', '80000000-0000-0000-0000-000000000006');

insert into public.machines (
  id, account_id, branch_id, machine_model_id, machine_code, display_name,
  created_by, updated_by
)
values
  ('84000000-0000-4000-8000-000000000001', '81000000-0000-0000-0000-000000000001', '83000000-0000-0000-0000-000000000001', '51000000-0000-0000-0000-000000000001', 'M22-A-01', 'M2.2 Account A Machine', '80000000-0000-0000-0000-000000000001', '80000000-0000-0000-0000-000000000001'),
  ('84000000-0000-4000-8000-000000000002', '81000000-0000-0000-0000-000000000002', '83000000-0000-0000-0000-000000000002', '51000000-0000-0000-0000-000000000001', 'M22-B-01', 'M2.2 Account B Machine', '80000000-0000-0000-0000-000000000006', '80000000-0000-0000-0000-000000000006');

set local role anon;
select extensions.throws_ok(
  'select * from public.operational_incidents', '42501', null,
  'anonymous cannot read incidents'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 09:00+07',
    target_category => 'prosedur'::public.operational_incident_category,
    target_incident_type => 'human',
    target_description => 'Anonymous attempt',
    target_client_request_id => '85000000-0000-4000-8000-000000000099'
  )$$,
  '42501', null,
  'anonymous cannot execute incident creation'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '80000000-0000-0000-0000-000000000001', true);
select extensions.lives_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 09:00+07',
    target_category => 'desain',
    target_incident_type => 'human',
    target_description => 'Wrong design version printed',
    target_client_request_id => '85000000-0000-4000-8000-000000000001',
    target_machine_id => '84000000-0000-4000-8000-000000000001',
    target_customer_name => 'Customer A',
    target_product_name => 'Brochure',
    target_qty_affected => 25,
    target_responsible_user_id => '80000000-0000-0000-0000-000000000004',
    target_material_loss => 125000,
    target_service_loss => 75000
  )$$,
  'owner can create an operational incident'
);
select extensions.is(
  (select assessed_loss from public.operational_incidents where client_request_id = '85000000-0000-4000-8000-000000000001'),
  200000::numeric,
  'assessed loss is generated from material plus service loss at multiplier one'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000002',
    target_branch_id => '83000000-0000-0000-0000-000000000002',
    target_occurred_at => '2026-08-25 09:30+07',
    target_category => 'bahan',
    target_incident_type => 'human',
    target_description => 'Cross-account attempt',
    target_client_request_id => '85000000-0000-4000-8000-000000000090'
  )$$,
  '42501', null,
  'cross-account incident creation is denied'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000002',
    target_occurred_at => '2026-08-25 09:30+07',
    target_category => 'bahan',
    target_incident_type => 'human',
    target_description => 'Cross-account branch attempt',
    target_client_request_id => '85000000-0000-4000-8000-000000000091'
  )$$,
  'P0002', null,
  'cross-account branch is denied'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 09:30+07',
    target_category => 'bahan',
    target_incident_type => 'machine_operation',
    target_description => 'Cross-account machine attempt',
    target_client_request_id => '85000000-0000-4000-8000-000000000092',
    target_machine_id => '84000000-0000-4000-8000-000000000002'
  )$$,
  'P0002', null,
  'cross-account machine is denied'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 09:30+07',
    target_category => 'bahan',
    target_incident_type => 'human',
    target_description => 'Cross-account PIC attempt',
    target_client_request_id => '85000000-0000-4000-8000-000000000093',
    target_responsible_user_id => '80000000-0000-0000-0000-000000000006'
  )$$,
  '23503', null,
  'cross-account responsible user is denied'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 09:30+07',
    target_category => 'bahan',
    target_incident_type => 'human',
    target_description => 'Negative material loss',
    target_client_request_id => '85000000-0000-4000-8000-000000000094',
    target_material_loss => -1
  )$$,
  '22003', null,
  'negative material loss is denied'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 09:30+07',
    target_category => 'bahan',
    target_incident_type => 'human',
    target_description => 'Negative service loss',
    target_client_request_id => '85000000-0000-4000-8000-000000000095',
    target_service_loss => -1
  )$$,
  '22003', null,
  'negative service loss is denied'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '80000000-0000-0000-0000-000000000002', true);
select extensions.lives_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 10:00+07',
    target_category => 'kesesuaian',
    target_incident_type => 'test_print',
    target_description => 'Test print specification mismatch',
    target_client_request_id => '85000000-0000-4000-8000-000000000002'
  )$$,
  'admin can create an incident without a machine'
);
select extensions.ok(
  (select machine_id is null from public.operational_incidents where client_request_id = '85000000-0000-4000-8000-000000000002'),
  'nullable machine is stored safely'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '80000000-0000-0000-0000-000000000003', true);
select extensions.lives_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 11:00+07',
    target_category => 'kualitas',
    target_incident_type => 'machine_operation',
    target_description => 'Incorrect production setup',
    target_client_request_id => '85000000-0000-4000-8000-000000000003',
    target_machine_id => '84000000-0000-4000-8000-000000000001'
  )$$,
  'technician can create an operational machine-usage incident'
);
select extensions.lives_ok(
  $$select public.resolve_operational_incident(
    (select id from public.operational_incidents where client_request_id = '85000000-0000-4000-8000-000000000003')
  )$$,
  'technician can resolve an incident'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '80000000-0000-0000-0000-000000000004', true);
select extensions.lives_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 12:00+07',
    target_category => 'prosedur',
    target_incident_type => 'human',
    target_description => 'Production procedure skipped',
    target_client_request_id => '85000000-0000-4000-8000-000000000004',
    target_responsible_name => 'Operator Lapangan'
  )$$,
  'operator can create an incident with a PIC snapshot'
);
select extensions.is(
  (select count(*)::integer from public.operational_incidents),
  4,
  'operator can read all incidents in their own account'
);
select extensions.throws_ok(
  $$select public.void_operational_incident(
    (select id from public.operational_incidents where client_request_id = '85000000-0000-4000-8000-000000000004'),
    'Operator void attempt'
  )$$,
  '42501', null,
  'operator cannot void an incident'
);
select extensions.throws_ok(
  $$update public.operational_incidents
    set description = 'Silent rewrite'
    where client_request_id = '85000000-0000-4000-8000-000000000004'$$,
  '42501', null,
  'operator cannot rewrite posted incident content'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '80000000-0000-0000-0000-000000000005', true);
select extensions.is(
  (select count(*)::integer from public.operational_incidents),
  0,
  'suspended member cannot read incidents'
);
select extensions.throws_ok(
  $$select public.create_operational_incident(
    target_account_id => '81000000-0000-0000-0000-000000000001',
    target_branch_id => '83000000-0000-0000-0000-000000000001',
    target_occurred_at => '2026-08-25 13:00+07',
    target_category => 'bahan',
    target_incident_type => 'human',
    target_description => 'Suspended attempt',
    target_client_request_id => '85000000-0000-4000-8000-000000000096'
  )$$,
  '42501', null,
  'suspended member cannot create incidents'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '80000000-0000-0000-0000-000000000001', true);
select extensions.lives_ok(
  $$select public.void_operational_incident(
    (select id from public.operational_incidents where client_request_id = '85000000-0000-4000-8000-000000000004'),
    'Duplicate operational log confirmed'
  )$$,
  'owner can void with a required reason'
);
select extensions.is(
  (select status::text from public.operational_incidents where client_request_id = '85000000-0000-4000-8000-000000000004'),
  'voided',
  'owner void preserves the incident with voided status'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '80000000-0000-0000-0000-000000000002', true);
select extensions.lives_ok(
  $$select public.void_operational_incident(
    (select id from public.operational_incidents where client_request_id = '85000000-0000-4000-8000-000000000002'),
    'Admin verified this was a duplicate'
  )$$,
  'admin can void with a required reason'
);
reset role;

select extensions.is(
  array_to_string(enum_range(null::public.operational_incident_category), ','),
  'kesesuaian,kualitas,desain,bahan,prosedur',
  'the five approved operational categories are exact and stable'
);
select extensions.throws_ok(
  $$select 'technical_fault'::public.operational_incident_category$$,
  '22P02', null,
  'invalid operational category is rejected'
);
select extensions.is(
  array_to_string(enum_range(null::public.operational_incident_type), ','),
  'machine_operation,human,test_print',
  'the three approved operational incident types are exact and stable'
);
select extensions.throws_ok(
  $$select 'machine_fault_code'::public.operational_incident_type$$,
  '22P02', null,
  'invalid incident type is rejected'
);

-- Base constraints remain authoritative outside the controlled APIs.
select extensions.throws_ok(
  $$insert into public.operational_incidents (
    account_id, branch_id, occurred_at, category, incident_type,
    material_loss, service_loss, description, client_request_id,
    created_by, updated_by
  ) values (
    '81000000-0000-0000-0000-000000000001',
    '83000000-0000-0000-0000-000000000001',
    '2026-08-25 14:00+07', 'bahan', 'human', -1, 0,
    'Direct negative loss', '85000000-0000-4000-8000-000000000097',
    '80000000-0000-0000-0000-000000000001',
    '80000000-0000-0000-0000-000000000001'
  )$$,
  '23514', null,
  'database constraint rejects negative material loss'
);

select extensions.finish();

rollback;
