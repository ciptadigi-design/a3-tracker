begin;

create extension if not exists pgtap with schema extensions;

select extensions.no_plan();

-- Deterministic rollback-only M2.2.1 fixtures.
insert into auth.users (
  id, aud, role, email, encrypted_password, email_confirmed_at,
  raw_app_meta_data, raw_user_meta_data, created_at, updated_at
)
values
  ('90000000-0000-0000-0000-000000000001', 'authenticated', 'authenticated', 'm221-owner-a@test.invalid', '', now(), '{}', '{"display_name":"Owner Review A"}', now(), now()),
  ('90000000-0000-0000-0000-000000000002', 'authenticated', 'authenticated', 'm221-admin-a@test.invalid', '', now(), '{}', '{"display_name":"Admin Review A"}', now(), now()),
  ('90000000-0000-0000-0000-000000000003', 'authenticated', 'authenticated', 'm221-tech-a@test.invalid', '', now(), '{}', '{"display_name":"Technician Review A"}', now(), now()),
  ('90000000-0000-0000-0000-000000000004', 'authenticated', 'authenticated', 'm221-operator-a@test.invalid', '', now(), '{}', '{"display_name":"Operator Review A"}', now(), now()),
  ('90000000-0000-0000-0000-000000000005', 'authenticated', 'authenticated', 'm221-suspended-a@test.invalid', '', now(), '{}', '{"display_name":"Suspended Review A"}', now(), now()),
  ('90000000-0000-0000-0000-000000000006', 'authenticated', 'authenticated', 'm221-owner-b@test.invalid', '', now(), '{}', '{"display_name":"Owner Review B"}', now(), now());

insert into public.accounts (id, code, name, created_by, updated_by)
values
  ('91000000-0000-0000-0000-000000000001', 'M221-A', 'M2.2.1 Account A', '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('91000000-0000-0000-0000-000000000002', 'M221-B', 'M2.2.1 Account B', '90000000-0000-0000-0000-000000000006', '90000000-0000-0000-0000-000000000006');

insert into public.account_memberships (
  id, account_id, user_id, role, status, accepted_at, created_by, updated_by
)
values
  ('92000000-0000-0000-0000-000000000001', '91000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001', 'owner', 'active', now(), '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('92000000-0000-0000-0000-000000000002', '91000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000002', 'admin', 'active', now(), '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('92000000-0000-0000-0000-000000000003', '91000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000003', 'technician', 'active', now(), '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('92000000-0000-0000-0000-000000000004', '91000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000004', 'operator', 'active', now(), '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('92000000-0000-0000-0000-000000000005', '91000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000005', 'admin', 'suspended', now(), '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('92000000-0000-0000-0000-000000000006', '91000000-0000-0000-0000-000000000002', '90000000-0000-0000-0000-000000000006', 'owner', 'active', now(), '90000000-0000-0000-0000-000000000006', '90000000-0000-0000-0000-000000000006');

insert into public.branches (id, account_id, code, name, created_by, updated_by)
values
  ('93000000-0000-0000-0000-000000000001', '91000000-0000-0000-0000-000000000001', 'M221-A-MAIN', 'M2.2.1 A Main', '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('93000000-0000-0000-0000-000000000002', '91000000-0000-0000-0000-000000000002', 'M221-B-MAIN', 'M2.2.1 B Main', '90000000-0000-0000-0000-000000000006', '90000000-0000-0000-0000-000000000006');

insert into public.machines (
  id, account_id, branch_id, machine_model_id, machine_code, display_name,
  created_by, updated_by
)
values
  ('94000000-0000-4000-8000-000000000001', '91000000-0000-0000-0000-000000000001', '93000000-0000-0000-0000-000000000001', '51000000-0000-0000-0000-000000000001', 'M221-A-01', 'M2.2.1 Account A Machine', '90000000-0000-0000-0000-000000000001', '90000000-0000-0000-0000-000000000001'),
  ('94000000-0000-4000-8000-000000000002', '91000000-0000-0000-0000-000000000002', '93000000-0000-0000-0000-000000000002', '51000000-0000-0000-0000-000000000001', 'M221-B-01', 'M2.2.1 Account B Machine', '90000000-0000-0000-0000-000000000006', '90000000-0000-0000-0000-000000000006');

-- M2.7B legacy-fixture continuity: scoped memberships and Operational People
-- are explicitly assigned to the fixture branches that were account-wide before M2.7B.
insert into public.account_membership_branches (account_id, membership_id, branch_id, assigned_by, updated_by)
select membership.account_id, membership.id, branch.id, membership.created_by, membership.created_by
from public.account_memberships membership
join public.branches branch on branch.account_id = membership.account_id
where membership.role <> 'owner'
on conflict (membership_id, branch_id) do update set is_active = true;

insert into public.operational_person_branches (account_id, operational_person_id, branch_id, assigned_by, updated_by)
select person.account_id, person.id, branch.id, person.created_by, person.created_by
from public.operational_people person
join public.branches branch on branch.account_id = person.account_id
on conflict (operational_person_id, branch_id) do update set is_active = true;

set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000001', true);

select public.create_operational_incident(
  target_account_id => '91000000-0000-0000-0000-000000000001',
  target_branch_id => '93000000-0000-0000-0000-000000000001',
  target_occurred_at => '2026-08-26 08:00+07',
  target_category => 'prosedur',
  target_incident_type => 'human',
  target_description => 'Owner editable incident',
  target_client_request_id => '95000000-0000-4000-8000-000000000001',
  target_machine_id => '94000000-0000-4000-8000-000000000001',
  target_material_loss => 2000,
  target_service_loss => 3000,
  target_cause => 'Operator salah setting',
  target_prevention => 'Periksa file sebelum cetak'
);

select public.create_operational_incident(
  target_account_id => '91000000-0000-0000-0000-000000000001',
  target_branch_id => '93000000-0000-0000-0000-000000000001',
  target_occurred_at => '2026-08-26 09:00+07',
  target_category => 'kualitas',
  target_incident_type => 'test_print',
  target_description => 'Admin editable incident',
  target_client_request_id => '95000000-0000-4000-8000-000000000002'
);

select public.create_operational_incident(
  target_account_id => '91000000-0000-0000-0000-000000000001',
  target_branch_id => '93000000-0000-0000-0000-000000000001',
  target_occurred_at => '2026-08-26 10:00+07',
  target_category => 'bahan',
  target_incident_type => 'human',
  target_description => 'Incident to solve',
  target_client_request_id => '95000000-0000-4000-8000-000000000003'
);

select public.create_operational_incident(
  target_account_id => '91000000-0000-0000-0000-000000000001',
  target_branch_id => '93000000-0000-0000-0000-000000000001',
  target_occurred_at => '2026-08-26 11:00+07',
  target_category => 'desain',
  target_incident_type => 'human',
  target_description => 'Incident to void',
  target_client_request_id => '95000000-0000-4000-8000-000000000004'
);

select public.void_operational_incident(
  (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000004'),
  'Confirmed duplicate'
);

-- Owner edit, exact atomic revision, changed fields, and loss recalculation.
select extensions.lives_ok(
  $$select public.update_operational_incident(
    target_incident_id => (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_occurred_at => '2026-08-26 08:00+07',
    target_category => 'prosedur',
    target_incident_type => 'human',
    target_description => 'Owner editable incident',
    target_machine_id => '94000000-0000-4000-8000-000000000001',
    target_material_loss => 3500,
    target_service_loss => 3000,
    target_cause => 'SOP verifikasi ukuran belum dijalankan',
    target_prevention => 'Wajib verifikasi ukuran terhadap invoice',
    target_change_reason => 'Hasil evaluasi produksi pagi'
  )$$,
  'owner can edit an open incident'
);

select extensions.is(
  (select count(*)::integer from public.operational_incident_revisions where incident_id = (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001')),
  1,
  'one successful edit creates exactly one revision'
);

select extensions.is(
  (select old_values ->> 'cause' from public.operational_incident_revisions where incident_id = (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001')),
  'Operator salah setting',
  'revision captures the old value'
);

select extensions.is(
  (select new_values ->> 'cause' from public.operational_incident_revisions where incident_id = (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001')),
  'SOP verifikasi ukuran belum dijalankan',
  'revision captures the new value'
);

select extensions.is(
  (select changed_fields from public.operational_incident_revisions where incident_id = (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001')),
  array['material_loss', 'cause', 'prevention']::text[],
  'revision changed_fields contains only authored changes in stable order'
);

select extensions.is(
  (select assessed_loss from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
  6500::numeric,
  'material loss edit recalculates assessed loss on the server'
);

select extensions.is(
  (select (new_values ->> 'assessed_loss')::numeric from public.operational_incident_revisions where incident_id = (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001')),
  6500::numeric,
  'revision snapshot includes the recalculated assessed loss'
);

-- Stale update is rejected and leaves both current truth and history unchanged.
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_base_updated_at => '2000-01-01 00:00+00',
    target_occurred_at => '2026-08-26 08:00+07',
    target_category => 'prosedur',
    target_incident_type => 'human',
    target_description => 'Should rollback',
    target_material_loss => 9999,
    target_service_loss => 3000
  )$$,
  '40001', null,
  'stale edit is rejected atomically'
);

select extensions.is(
  (select description from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
  'Owner editable incident',
  'failed update does not change the incident'
);

select extensions.is(
  (select count(*)::integer from public.operational_incident_revisions where incident_id = (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001')),
  1,
  'failed update does not append a revision'
);

reset role;

-- Admin can edit and service loss also drives generated assessed loss.
set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000002', true);
select extensions.lives_ok(
  $$select public.update_operational_incident(
    target_incident_id => (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000002'),
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000002'),
    target_occurred_at => '2026-08-26 09:00+07',
    target_category => 'kualitas',
    target_incident_type => 'test_print',
    target_description => 'Admin editable incident',
    target_material_loss => 1000,
    target_service_loss => 4000
  )$$,
  'admin can edit an open incident'
);
select extensions.is(
  (select assessed_loss from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000002'),
  5000::numeric,
  'service loss edit recalculates assessed loss'
);
reset role;

-- Operator, technician, suspended, anonymous, and cross-account edit denial.
select set_config(
  'a3tracker.test_incident_id',
  (select id::text from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
  true
);

set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000004', true);
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_occurred_at => '2026-08-26 08:00+07', target_category => 'prosedur',
    target_incident_type => 'human', target_description => 'Operator attempt'
  )$$,
  '42501', null,
  'operator cannot edit a posted incident'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000003', true);
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_occurred_at => '2026-08-26 08:00+07', target_category => 'prosedur',
    target_incident_type => 'human', target_description => 'Technician attempt'
  )$$,
  '42501', null,
  'technician existing resolve permission does not expand to Edit Log'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000005', true);
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => current_setting('a3tracker.test_incident_id')::uuid,
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_occurred_at => '2026-08-26 08:00+07', target_category => 'prosedur',
    target_incident_type => 'human', target_description => 'Suspended attempt'
  )$$,
  '42501', null,
  'suspended member cannot edit'
);
reset role;

set local role anon;
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => '00000000-0000-0000-0000-000000000000',
    target_base_updated_at => now(), target_occurred_at => now(),
    target_category => 'prosedur', target_incident_type => 'human',
    target_description => 'Anonymous attempt'
  )$$,
  '42501', null,
  'anonymous cannot execute Edit Log'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000006', true);
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => current_setting('a3tracker.test_incident_id')::uuid,
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000001'),
    target_occurred_at => '2026-08-26 08:00+07', target_category => 'prosedur',
    target_incident_type => 'human', target_description => 'Cross account attempt'
  )$$,
  '42501', null,
  'cross-account edit is denied'
);
select extensions.is(
  (select count(*)::integer from public.operational_incident_revisions),
  0,
  'cross-account member cannot read revision history'
);
reset role;

-- Owner/admin solve, metadata, read-only enforcement, and void preservation.
set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000001', true);
select extensions.lives_ok(
  $$select public.solve_operational_incident(
    (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000003'),
    'Disepakati dalam briefing produksi'
  )$$,
  'owner can deliberately solve an open incident'
);
select extensions.ok(
  (select status = 'resolved' and resolved_by = '90000000-0000-0000-0000-000000000001' and resolved_at is not null and resolution_note = 'Disepakati dalam briefing produksi'
   from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000003'),
  'solved metadata is populated using the stable resolved status'
);
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000003'),
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000003'),
    target_occurred_at => '2026-08-26 10:00+07', target_category => 'bahan',
    target_incident_type => 'human', target_description => 'Solved rewrite'
  )$$,
  '42501', null,
  'resolved incident cannot be normally edited'
);
select extensions.throws_ok(
  $$select public.update_operational_incident(
    target_incident_id => (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000004'),
    target_base_updated_at => (select updated_at from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000004'),
    target_occurred_at => '2026-08-26 11:00+07', target_category => 'desain',
    target_incident_type => 'human', target_description => 'Voided rewrite'
  )$$,
  '42501', null,
  'voided incident cannot be edited'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000002', true);
select public.create_operational_incident(
  target_account_id => '91000000-0000-0000-0000-000000000001',
  target_branch_id => '93000000-0000-0000-0000-000000000001',
  target_occurred_at => '2026-08-26 12:00+07', target_category => 'kesesuaian',
  target_incident_type => 'human', target_description => 'Admin solve incident',
  target_client_request_id => '95000000-0000-4000-8000-000000000005'
);
select extensions.lives_ok(
  $$select public.solve_operational_incident(
    (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000005'), null
  )$$,
  'admin can solve an open incident'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '90000000-0000-0000-0000-000000000004', true);
select extensions.throws_ok(
  $$select public.solve_operational_incident(
    (select id from public.operational_incidents where client_request_id = '95000000-0000-4000-8000-000000000002'), null
  )$$,
  '42501', null,
  'operator cannot solve an incident'
);
select extensions.is(
  (select count(*)::integer from public.operational_incident_revisions),
  2,
  'normal account reader can view account revision history'
);
select extensions.throws_ok(
  $$update public.operational_incident_revisions set change_reason = 'Tampered'$$,
  '42501', null,
  'authenticated client cannot update audit rows'
);
select extensions.throws_ok(
  $$delete from public.operational_incident_revisions$$,
  '42501', null,
  'authenticated client cannot delete audit rows'
);
reset role;

-- Table/function ACLs remain least privilege and audit rows do not inflate totals.
select extensions.is(
  (select count(*)::integer
   from pg_catalog.pg_class as relation
   cross join lateral pg_catalog.aclexplode(coalesce(relation.relacl, pg_catalog.acldefault('r', relation.relowner))) as privilege
   where relation.oid = 'public.operational_incident_revisions'::regclass
     and privilege.grantee = 0),
  0,
  'PUBLIC has no revision table privileges'
);

select extensions.is(
  (select count(*)::integer
   from pg_catalog.pg_proc as function
   cross join lateral pg_catalog.aclexplode(coalesce(function.proacl, pg_catalog.acldefault('f', function.proowner))) as privilege
   where function.oid = any(array[
     'public.update_operational_incident(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text,text)'::regprocedure,
     'public.solve_operational_incident(uuid,text)'::regprocedure
   ])
     and privilege.grantee = 0
     and privilege.privilege_type = 'EXECUTE'),
  0,
  'PUBLIC cannot execute M2.2.1 mutation APIs'
);

select extensions.is(
  (select count(*)::integer
   from pg_catalog.pg_proc as function
   where function.oid = any(array[
     'public.update_operational_incident(uuid,timestamptz,timestamptz,public.operational_incident_category,public.operational_incident_type,text,uuid,text,text,text,integer,uuid,text,numeric,numeric,text,text,text,text)'::regprocedure,
     'public.solve_operational_incident(uuid,text)'::regprocedure
   ])
     and function.prosecdef
     and function.proconfig @> array['search_path=""']::text[]),
  2,
  'review APIs are SECURITY DEFINER with an empty search path'
);

select extensions.is(
  (select count(*)::integer from public.operational_incidents),
  5,
  'existing M2.2 records remain readable and revisions are not incidents'
);

select extensions.is(
  (select sum(assessed_loss) from public.operational_incidents where status <> 'voided'),
  11500::numeric,
  'incident summary uses current rows only and revisions do not inflate loss totals'
);

select extensions.finish();

rollback;
