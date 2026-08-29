begin;

create extension if not exists pgtap with schema extensions;

select extensions.no_plan();

-- Privilege and SECURITY DEFINER catalog contract.
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
      'public.profiles'::regclass,
      'public.accounts'::regclass,
      'public.account_memberships'::regclass,
      'public.branches'::regclass
    ])
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no privileges on Batch 1 tables'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_attribute as attribute
    cross join lateral pg_catalog.aclexplode(attribute.attacl) as privilege
    where attribute.attrelid = any(array[
      'public.profiles'::regclass,
      'public.accounts'::regclass,
      'public.account_memberships'::regclass,
      'public.branches'::regclass
    ])
      and attribute.attnum > 0
      and not attribute.attisdropped
      and privilege.grantee = 0
  ),
  0,
  'PUBLIC has no column privileges on Batch 1 tables'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.handle_new_auth_user()'::regprocedure,
      'public.protect_membership_identity_and_last_owner()'::regprocedure,
      'public.is_account_member(uuid)'::regprocedure,
      'public.has_account_role(uuid,public.account_role[])'::regprocedure,
      'public.get_account_member_profiles(uuid)'::regprocedure,
      'public.manage_account_membership(uuid,uuid,public.account_role,public.membership_status)'::regprocedure
    ])
      and function.prosecdef
  ),
  6,
  'all Batch 1 SECURITY DEFINER functions retain the definer flag'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    join pg_catalog.pg_roles as owner on owner.oid = function.proowner
    where function.oid = any(array[
      'public.handle_new_auth_user()'::regprocedure,
      'public.protect_membership_identity_and_last_owner()'::regprocedure,
      'public.is_account_member(uuid)'::regprocedure,
      'public.has_account_role(uuid,public.account_role[])'::regprocedure,
      'public.get_account_member_profiles(uuid)'::regprocedure,
      'public.manage_account_membership(uuid,uuid,public.account_role,public.membership_status)'::regprocedure
    ])
      and owner.rolname = 'postgres'
  ),
  6,
  'all Batch 1 SECURITY DEFINER functions are owned by trusted role postgres'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.handle_new_auth_user()'::regprocedure,
      'public.protect_membership_identity_and_last_owner()'::regprocedure,
      'public.is_account_member(uuid)'::regprocedure,
      'public.has_account_role(uuid,public.account_role[])'::regprocedure,
      'public.get_account_member_profiles(uuid)'::regprocedure,
      'public.manage_account_membership(uuid,uuid,public.account_role,public.membership_status)'::regprocedure
    ])
      and function.proconfig @> array['search_path=""']::text[]
  ),
  6,
  'all Batch 1 SECURITY DEFINER functions have an empty fixed search_path'
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
      'public.handle_new_auth_user()'::regprocedure,
      'public.protect_membership_identity_and_last_owner()'::regprocedure,
      'public.is_account_member(uuid)'::regprocedure,
      'public.has_account_role(uuid,public.account_role[])'::regprocedure,
      'public.get_account_member_profiles(uuid)'::regprocedure,
      'public.manage_account_membership(uuid,uuid,public.account_role,public.membership_status)'::regprocedure
    ])
      and privilege.grantee = 0
      and privilege.privilege_type = 'EXECUTE'
  ),
  0,
  'PUBLIC cannot execute Batch 1 SECURITY DEFINER functions'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.handle_new_auth_user()'::regprocedure,
      'public.protect_membership_identity_and_last_owner()'::regprocedure,
      'public.is_account_member(uuid)'::regprocedure,
      'public.has_account_role(uuid,public.account_role[])'::regprocedure,
      'public.get_account_member_profiles(uuid)'::regprocedure,
      'public.manage_account_membership(uuid,uuid,public.account_role,public.membership_status)'::regprocedure
    ])
      and has_function_privilege('anon', function.oid, 'EXECUTE')
  ),
  0,
  'anonymous cannot execute Batch 1 SECURITY DEFINER functions'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.handle_new_auth_user()'::regprocedure,
      'public.protect_membership_identity_and_last_owner()'::regprocedure,
      'public.is_account_member(uuid)'::regprocedure,
      'public.has_account_role(uuid,public.account_role[])'::regprocedure,
      'public.get_account_member_profiles(uuid)'::regprocedure,
      'public.manage_account_membership(uuid,uuid,public.account_role,public.membership_status)'::regprocedure
    ])
      and has_function_privilege('service_role', function.oid, 'EXECUTE')
  ),
  0,
  'service_role has no direct EXECUTE on Batch 1 SECURITY DEFINER functions'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.is_account_member(uuid)'::regprocedure,
      'public.has_account_role(uuid,public.account_role[])'::regprocedure,
      'public.get_account_member_profiles(uuid)'::regprocedure,
      'public.manage_account_membership(uuid,uuid,public.account_role,public.membership_status)'::regprocedure
    ])
      and has_function_privilege('authenticated', function.oid, 'EXECUTE')
  ),
  4,
  'authenticated can execute only the four intended SECURITY DEFINER APIs'
);

select extensions.is(
  (
    select count(*)::integer
    from pg_catalog.pg_proc as function
    where function.oid = any(array[
      'public.handle_new_auth_user()'::regprocedure,
      'public.protect_membership_identity_and_last_owner()'::regprocedure
    ])
      and has_function_privilege('authenticated', function.oid, 'EXECUTE')
  ),
  0,
  'authenticated cannot directly execute trigger-only SECURITY DEFINER functions'
);

-- Deterministic local-only Auth identities. Inserts are rollback-only.
insert into auth.users (
  id, aud, role, email, encrypted_password, email_confirmed_at,
  raw_app_meta_data, raw_user_meta_data, created_at, updated_at
)
values
  ('00000000-0000-0000-0000-000000000001', 'authenticated', 'authenticated', 'owner-a@test.invalid', '', now(), '{}', '{"display_name":"Owner A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000002', 'authenticated', 'authenticated', 'admin-a@test.invalid', '', now(), '{}', '{"display_name":"Admin A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000003', 'authenticated', 'authenticated', 'operator-a@test.invalid', '', now(), '{}', '{"display_name":"Operator A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000004', 'authenticated', 'authenticated', 'suspended-a@test.invalid', '', now(), '{}', '{"display_name":"Suspended A"}', now(), now()),
  ('00000000-0000-0000-0000-000000000005', 'authenticated', 'authenticated', 'owner-b@test.invalid', '', now(), '{}', '{"display_name":"Owner B"}', now(), now()),
  ('00000000-0000-0000-0000-000000000006', 'authenticated', 'authenticated', 'outsider@test.invalid', '', now(), '{}', '{"display_name":"Outsider"}', now(), now());

insert into public.accounts (id, code, name, created_by, updated_by)
values
  ('10000000-0000-0000-0000-000000000001', 'ACCOUNT-A', 'Account A', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001'),
  ('10000000-0000-0000-0000-000000000002', 'ACCOUNT-B', 'Account B', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000005');

insert into public.account_memberships (
  id, account_id, user_id, role, status, accepted_at, created_by, updated_by
)
values
  ('20000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 'owner', 'active', now(), '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001'),
  ('20000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 'admin', 'active', now(), '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001'),
  ('20000000-0000-0000-0000-000000000003', '10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000003', 'operator', 'active', now(), '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001'),
  ('20000000-0000-0000-0000-000000000004', '10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000004', 'operator', 'suspended', null, '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001'),
  ('20000000-0000-0000-0000-000000000005', '10000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000005', 'owner', 'active', now(), '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000005');

insert into public.branches (id, account_id, code, name, created_by, updated_by)
values
  ('30000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000001', 'A-MAIN', 'Account A Main', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001'),
  ('30000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000002', 'B-MAIN', 'Account B Main', '00000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000005');

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

set local role anon;
select extensions.throws_ok(
  'select * from public.accounts', '42501', null,
  'anonymous cannot read tenant tables'
);
select extensions.throws_ok(
  $$select public.is_account_member('10000000-0000-0000-0000-000000000001')$$,
  '42501', null,
  'anonymous cannot invoke tenant SECURITY DEFINER helpers'
);
reset role;

select set_config('request.jwt.claim.sub', '', true);
select extensions.is(
  public.is_account_member('10000000-0000-0000-0000-000000000001'),
  false,
  'NULL auth.uid() never satisfies membership checks'
);
select extensions.throws_ok(
  $$select public.manage_account_membership(
      '10000000-0000-0000-0000-000000000001',
      '00000000-0000-0000-0000-000000000006',
      'operator', 'active'
    )$$,
  '42501', 'authentication required',
  'membership mutation rejects NULL auth.uid()'
);

set local role authenticated;
select set_config('request.jwt.claim.sub', '00000000-0000-0000-0000-000000000006', true);
select extensions.is((select count(*)::integer from public.accounts), 0, 'outsider cannot read accounts');
select extensions.is((select count(*)::integer from public.profiles), 1, 'profiles expose only the caller profile');
select extensions.is(
  (select count(*)::integer from public.profiles where user_id = '00000000-0000-0000-0000-000000000001'),
  0,
  'outsider cannot leak another user profile'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '00000000-0000-0000-0000-000000000003', true);
select extensions.is((select count(*)::integer from public.accounts), 1, 'active member reads only their account');
select extensions.is(
  (select count(*)::integer from public.get_account_member_profiles('10000000-0000-0000-0000-000000000001')),
  3,
  'member profile RPC returns only invited or active members in the requested account'
);
select extensions.is(
  (select count(*)::integer from public.get_account_member_profiles('10000000-0000-0000-0000-000000000002')),
  0,
  'member profile RPC does not leak profiles across accounts'
);
select extensions.throws_ok(
  $$insert into public.branches (account_id, code, name)
    values ('10000000-0000-0000-0000-000000000001', 'OP-CREATE', 'Operator Create')$$,
  '42501', null, 'operator cannot create a branch'
);
select extensions.is_empty(
  $$update public.branches set name = 'Operator Update'
    where id = '30000000-0000-0000-0000-000000000001' returning 1$$,
  'operator cannot update a branch'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '00000000-0000-0000-0000-000000000002', true);
select extensions.throws_ok(
  $$insert into public.branches (account_id, code, name)
    values ('10000000-0000-0000-0000-000000000001', 'ADMIN-CREATE', 'Admin Create')$$,
  '42501', null, 'admin cannot administer branches in their account'
);
select extensions.throws_ok(
  $$insert into public.branches (account_id, code, name)
    values ('10000000-0000-0000-0000-000000000002', 'CROSS-CREATE', 'Cross Account')$$,
  '42501', null, 'admin cannot insert a branch into another account'
);
select extensions.is_empty(
  $$update public.branches set name = 'Cross Account Update'
    where id = '30000000-0000-0000-0000-000000000002' returning 1$$,
  'admin cannot update a branch in another account'
);
select extensions.throws_ok(
  $$select public.manage_account_membership(
      '10000000-0000-0000-0000-000000000001',
      '00000000-0000-0000-0000-000000000002',
      'owner', 'active'
    )$$,
  '42501', null, 'user cannot self-promote'
);
select extensions.throws_ok(
  $$select public.manage_account_membership(
      '10000000-0000-0000-0000-000000000001',
      '00000000-0000-0000-0000-000000000006',
      'owner', 'active'
    )$$,
  '42501', null, 'admin cannot promote a membership to owner'
);
select extensions.throws_ok(
  $$update public.account_memberships set role = 'admin'
    where id = '20000000-0000-0000-0000-000000000003'$$,
  '42501', null, 'authenticated users cannot mutate memberships directly'
);
reset role;

set local role authenticated;
select set_config('request.jwt.claim.sub', '00000000-0000-0000-0000-000000000001', true);
select extensions.throws_ok(
  $$select public.manage_account_membership(
      '10000000-0000-0000-0000-000000000002',
      '00000000-0000-0000-0000-000000000006',
      'operator', 'active'
    )$$,
  '42501', null, 'owner cannot mutate membership in another account'
);
reset role;

select extensions.throws_ok(
  $$update public.account_memberships set role = 'admin'
    where id = '20000000-0000-0000-0000-000000000001'$$,
  '23514', null, 'last active owner cannot be demoted'
);
select extensions.throws_ok(
  $$update public.account_memberships set status = 'suspended'
    where id = '20000000-0000-0000-0000-000000000001'$$,
  '23514', null, 'last active owner cannot be suspended'
);
select extensions.throws_ok(
  $$update public.account_memberships set status = 'revoked'
    where id = '20000000-0000-0000-0000-000000000001'$$,
  '23514', null, 'last active owner cannot be revoked'
);
select extensions.throws_ok(
  $$delete from public.account_memberships
    where id = '20000000-0000-0000-0000-000000000001'$$,
  '23514', null, 'last active owner cannot be deleted'
);

set local role authenticated;
select set_config('request.jwt.claim.sub', '00000000-0000-0000-0000-000000000004', true);
select extensions.is((select count(*)::integer from public.accounts), 0, 'suspended membership cannot read accounts');
select extensions.is((select count(*)::integer from public.branches), 0, 'suspended membership cannot read branches');
select extensions.is((select count(*)::integer from public.account_memberships), 0, 'suspended membership cannot read memberships');
select extensions.is(
  (select count(*)::integer from public.get_account_member_profiles('10000000-0000-0000-0000-000000000001')),
  0,
  'suspended membership cannot read account member profiles'
);
reset role;

select extensions.finish();

rollback;
