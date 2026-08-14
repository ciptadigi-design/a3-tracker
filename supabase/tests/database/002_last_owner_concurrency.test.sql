create extension if not exists pgtap with schema extensions;
create extension if not exists dblink with schema extensions;

select extensions.no_plan();

-- Use a short-lived, least-scope login with a randomly generated password for
-- the two real database sessions. No local database credential is stored in
-- this test file.
do $$
begin
  if exists (
    select 1
    from pg_catalog.pg_roles
    where rolname = 'batch1_concurrency_test'
  ) then
    execute 'grant batch1_concurrency_test to postgres';
    execute 'drop owned by batch1_concurrency_test';
    execute 'drop role batch1_concurrency_test';
  end if;
end;
$$;

create temporary table concurrency_connection_secret (
  password text not null
);

insert into concurrency_connection_secret (password)
values (gen_random_uuid()::text);

do $$
declare
  generated_password text := (
    select password
    from concurrency_connection_secret
  );
begin
  execute format(
    'create role batch1_concurrency_test login password %L bypassrls',
    generated_password
  );
  grant usage on schema public to batch1_concurrency_test;
  grant authenticated to batch1_concurrency_test;
  grant usage on type public.account_role, public.membership_status
    to batch1_concurrency_test;
  grant select, update, delete on table public.account_memberships
    to batch1_concurrency_test;
end;
$$;

-- These fixtures must be committed because the two independent PostgreSQL
-- sessions below must be able to see them.
insert into auth.users (
  id, aud, role, email, encrypted_password, email_confirmed_at,
  raw_app_meta_data, raw_user_meta_data, created_at, updated_at
)
select
  format('40000000-0000-0000-0000-%s', lpad(identity::text, 12, '0'))::uuid,
  'authenticated',
  'authenticated',
  format('concurrency-owner-%s@test.invalid', identity),
  '', now(), '{}', '{}', now(), now()
from generate_series(1, 8) as identity;

insert into public.accounts (id, code, name)
values
  ('41000000-0000-0000-0000-000000000001', 'CONCURRENCY-DEMOTE', 'Concurrency Demote'),
  ('41000000-0000-0000-0000-000000000002', 'CONCURRENCY-SUSPEND', 'Concurrency Suspend'),
  ('41000000-0000-0000-0000-000000000003', 'CONCURRENCY-REVOKE', 'Concurrency Revoke'),
  ('41000000-0000-0000-0000-000000000004', 'CONCURRENCY-DELETE', 'Concurrency Delete');

insert into public.account_memberships (
  id, account_id, user_id, role, status, accepted_at
)
values
  ('42000000-0000-0000-0000-000000000001', '41000000-0000-0000-0000-000000000001', '40000000-0000-0000-0000-000000000001', 'owner', 'active', now()),
  ('42000000-0000-0000-0000-000000000002', '41000000-0000-0000-0000-000000000001', '40000000-0000-0000-0000-000000000002', 'owner', 'active', now()),
  ('42000000-0000-0000-0000-000000000003', '41000000-0000-0000-0000-000000000002', '40000000-0000-0000-0000-000000000003', 'owner', 'active', now()),
  ('42000000-0000-0000-0000-000000000004', '41000000-0000-0000-0000-000000000002', '40000000-0000-0000-0000-000000000004', 'owner', 'active', now()),
  ('42000000-0000-0000-0000-000000000005', '41000000-0000-0000-0000-000000000003', '40000000-0000-0000-0000-000000000005', 'owner', 'active', now()),
  ('42000000-0000-0000-0000-000000000006', '41000000-0000-0000-0000-000000000003', '40000000-0000-0000-0000-000000000006', 'owner', 'active', now()),
  ('42000000-0000-0000-0000-000000000007', '41000000-0000-0000-0000-000000000004', '40000000-0000-0000-0000-000000000007', 'owner', 'active', now()),
  ('42000000-0000-0000-0000-000000000008', '41000000-0000-0000-0000-000000000004', '40000000-0000-0000-0000-000000000008', 'owner', 'active', now());

do $$
declare
  generated_password text := (
    select password
    from concurrency_connection_secret
  );
  local_connection text := format(
    'host=host.docker.internal port=54322 dbname=%s user=batch1_concurrency_test password=%s sslmode=disable',
    current_database(),
    generated_password
  );
begin
  perform extensions.dblink_connect('owner_session_1', local_connection);
  perform extensions.dblink_connect('owner_session_2', local_connection);
end;
$$;

create temporary table concurrency_backend (
  session_name text primary key,
  pid integer not null
);

insert into concurrency_backend
select 'owner_session_1', pid
from extensions.dblink('owner_session_1', 'select pg_backend_pid()') as result(pid integer);

insert into concurrency_backend
select 'owner_session_2', pid
from extensions.dblink('owner_session_2', 'select pg_backend_pid()') as result(pid integer);

-- The first mutation holds the shared account-row lock for one second. The
-- second session changes the other owner and must wait on that same row. Once
-- released, its trigger sees only one active owner and rejects the mutation.
select extensions.is(
  extensions.dblink_send_query(
    'owner_session_1',
    $$with changed as (
        update public.account_memberships
        set role = 'admin'
        where id = '42000000-0000-0000-0000-000000000001'
        returning 1
      )
      select count(*) from changed
      cross join lateral (select pg_sleep(1)) as account_lock_hold$$
  ),
  1,
  'demotion session one starts'
);
do $$ begin perform pg_sleep(0.15); end $$;
select extensions.is(
  extensions.dblink_send_query(
    'owner_session_2',
    $$update public.account_memberships
      set role = 'admin'
      where id = '42000000-0000-0000-0000-000000000002'
      returning id$$
  ),
  1,
  'demotion session two starts'
);
do $$
begin
  for attempt in 1..40 loop
    exit when exists (
      select 1 from pg_catalog.pg_stat_activity
      where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
        and wait_event_type = 'Lock'
    );
    perform pg_sleep(0.05);
  end loop;
end;
$$;
select extensions.ok(
  exists (
    select 1 from pg_catalog.pg_locks
    where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
      and locktype = 'transactionid'
      and not granted
  ),
  'concurrent demotion waits on the account-row transaction lock'
);
select extensions.is(
  (select changed_count from extensions.dblink_get_result('owner_session_1') as result(changed_count bigint)),
  1::bigint,
  'first concurrent demotion succeeds'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'second concurrent demotion is rejected'
);
select extensions.ok(
  position('account must retain at least one active owner' in extensions.dblink_error_message('owner_session_2')) > 0,
  'concurrent demotion fails on the last-owner invariant'
);
select extensions.is(
  (select count(*)::integer from public.account_memberships
   where account_id = '41000000-0000-0000-0000-000000000001'
     and role = 'owner' and status = 'active'),
  1,
  'concurrent demotions leave one active owner'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_1', false) as result(changed_count bigint)),
  0,
  'demotion session one result stream is drained'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'demotion session two result stream is drained'
);

select extensions.is(
  extensions.dblink_send_query(
    'owner_session_1',
    $$with changed as (
        update public.account_memberships
        set status = 'suspended'
        where id = '42000000-0000-0000-0000-000000000003'
        returning 1
      )
      select count(*) from changed
      cross join lateral (select pg_sleep(1)) as account_lock_hold$$
  ), 1, 'suspension session one starts'
);
do $$ begin perform pg_sleep(0.15); end $$;
select extensions.is(
  extensions.dblink_send_query(
    'owner_session_2',
    $$update public.account_memberships
      set status = 'suspended'
      where id = '42000000-0000-0000-0000-000000000004'
      returning id$$
  ), 1, 'suspension session two starts'
);
do $$
begin
  for attempt in 1..40 loop
    exit when exists (
      select 1 from pg_catalog.pg_stat_activity
      where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
        and wait_event_type = 'Lock'
    );
    perform pg_sleep(0.05);
  end loop;
end;
$$;
select extensions.ok(
  exists (
    select 1 from pg_catalog.pg_locks
    where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
      and locktype = 'transactionid' and not granted
  ),
  'concurrent suspension waits on the account-row transaction lock'
);
select extensions.is(
  (select changed_count from extensions.dblink_get_result('owner_session_1') as result(changed_count bigint)),
  1::bigint,
  'first concurrent suspension succeeds'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'second concurrent suspension is rejected'
);
select extensions.ok(
  position('account must retain at least one active owner' in extensions.dblink_error_message('owner_session_2')) > 0,
  'concurrent suspension rejects the second owner mutation'
);
select extensions.is(
  (select count(*)::integer from public.account_memberships
   where account_id = '41000000-0000-0000-0000-000000000002'
     and role = 'owner' and status = 'active'),
  1,
  'concurrent suspensions leave one active owner'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_1', false) as result(changed_count bigint)),
  0,
  'suspension session one result stream is drained'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'suspension session two result stream is drained'
);

select extensions.is(
  extensions.dblink_send_query(
    'owner_session_1',
    $$with changed as (
        update public.account_memberships
        set status = 'revoked'
        where id = '42000000-0000-0000-0000-000000000005'
        returning 1
      )
      select count(*) from changed
      cross join lateral (select pg_sleep(1)) as account_lock_hold$$
  ), 1, 'revocation session one starts'
);
do $$ begin perform pg_sleep(0.15); end $$;
select extensions.is(
  extensions.dblink_send_query(
    'owner_session_2',
    $$update public.account_memberships
      set status = 'revoked'
      where id = '42000000-0000-0000-0000-000000000006'
      returning id$$
  ), 1, 'revocation session two starts'
);
do $$
begin
  for attempt in 1..40 loop
    exit when exists (
      select 1 from pg_catalog.pg_stat_activity
      where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
        and wait_event_type = 'Lock'
    );
    perform pg_sleep(0.05);
  end loop;
end;
$$;
select extensions.ok(
  exists (
    select 1 from pg_catalog.pg_locks
    where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
      and locktype = 'transactionid' and not granted
  ),
  'concurrent revocation waits on the account-row transaction lock'
);
select extensions.is(
  (select changed_count from extensions.dblink_get_result('owner_session_1') as result(changed_count bigint)),
  1::bigint,
  'first concurrent revocation succeeds'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'second concurrent revocation is rejected'
);
select extensions.ok(
  position('account must retain at least one active owner' in extensions.dblink_error_message('owner_session_2')) > 0,
  'concurrent revocation rejects the second owner mutation'
);
select extensions.is(
  (select count(*)::integer from public.account_memberships
   where account_id = '41000000-0000-0000-0000-000000000003'
     and role = 'owner' and status = 'active'),
  1,
  'concurrent revocations leave one active owner'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_1', false) as result(changed_count bigint)),
  0,
  'revocation session one result stream is drained'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'revocation session two result stream is drained'
);

select extensions.is(
  extensions.dblink_send_query(
    'owner_session_1',
    $$with changed as (
        delete from public.account_memberships
        where id = '42000000-0000-0000-0000-000000000007'
        returning 1
      )
      select count(*) from changed
      cross join lateral (select pg_sleep(1)) as account_lock_hold$$
  ), 1, 'deletion session one starts'
);
do $$ begin perform pg_sleep(0.15); end $$;
select extensions.is(
  extensions.dblink_send_query(
    'owner_session_2',
    $$delete from public.account_memberships
      where id = '42000000-0000-0000-0000-000000000008'
      returning id$$
  ), 1, 'deletion session two starts'
);
do $$
begin
  for attempt in 1..40 loop
    exit when exists (
      select 1 from pg_catalog.pg_stat_activity
      where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
        and wait_event_type = 'Lock'
    );
    perform pg_sleep(0.05);
  end loop;
end;
$$;
select extensions.ok(
  exists (
    select 1 from pg_catalog.pg_locks
    where pid = (select pid from concurrency_backend where session_name = 'owner_session_2')
      and locktype = 'transactionid' and not granted
  ),
  'concurrent deletion waits on the account-row transaction lock'
);
select extensions.is(
  (select changed_count from extensions.dblink_get_result('owner_session_1') as result(changed_count bigint)),
  1::bigint,
  'first concurrent deletion succeeds'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'second concurrent deletion is rejected'
);
select extensions.ok(
  position('account must retain at least one active owner' in extensions.dblink_error_message('owner_session_2')) > 0,
  'concurrent deletion rejects the second owner mutation'
);
select extensions.is(
  (select count(*)::integer from public.account_memberships
   where account_id = '41000000-0000-0000-0000-000000000004'
     and role = 'owner' and status = 'active'),
  1,
  'concurrent deletions leave one active owner'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_1', false) as result(changed_count bigint)),
  0,
  'deletion session one result stream is drained'
);
select extensions.is(
  (select count(*)::integer from extensions.dblink_get_result('owner_session_2', false) as result(membership_id uuid)),
  0,
  'deletion session two result stream is drained'
);

do $$
begin
  perform extensions.dblink_disconnect('owner_session_1');
  perform extensions.dblink_disconnect('owner_session_2');
end;
$$;

-- Remove committed fixtures without weakening the production invariant outside
-- this local-only cleanup window.
alter table public.account_memberships
  disable trigger account_memberships_protect_identity_and_last_owner;
delete from public.account_memberships
where account_id in (
  '41000000-0000-0000-0000-000000000001',
  '41000000-0000-0000-0000-000000000002',
  '41000000-0000-0000-0000-000000000003',
  '41000000-0000-0000-0000-000000000004'
);
alter table public.account_memberships
  enable trigger account_memberships_protect_identity_and_last_owner;
delete from public.accounts
where id in (
  '41000000-0000-0000-0000-000000000001',
  '41000000-0000-0000-0000-000000000002',
  '41000000-0000-0000-0000-000000000003',
  '41000000-0000-0000-0000-000000000004'
);
delete from auth.users
where id::text like '40000000-0000-0000-0000-%';

grant batch1_concurrency_test to postgres;
drop owned by batch1_concurrency_test;
drop role batch1_concurrency_test;

select extensions.finish();
