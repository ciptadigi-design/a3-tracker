# Batch 1 security remediation

## Scope

This remediation changes only Batch 1 database privileges and security tests. It
does not change the Batch 1 product model, add application behavior, or deploy a
database migration.

## PUBLIC privileges

The access migration explicitly revokes all table privileges from `PUBLIC`,
`anon`, and `authenticated` on:

- `public.profiles`
- `public.accounts`
- `public.account_memberships`
- `public.branches`

It then restores only the existing intended column/table grants to
`authenticated`. No table grant is added for `PUBLIC`, `anon`, or
`service_role`.

All six `SECURITY DEFINER` functions explicitly revoke `EXECUTE` from `PUBLIC`.
The two trigger-only functions also revoke it from `anon`, `authenticated`, and
`service_role` and receive no client-role grant. The four callable tenant APIs
first revoke all client-role exposure, then grant `EXECUTE` only to
`authenticated`.

## SECURITY DEFINER ownership strategy

The trusted expected owner is `postgres`. Supabase documents `postgres` as its
default administrative Postgres role, and its hosted ownership model assigns
user-created database objects to `postgres`. Supabase also documents that the
default `postgres` role applies database migrations and may be unable to alter
objects created under unsupported custom owners:

- <https://supabase.com/docs/guides/database/postgres/roles>
- <https://supabase.com/docs/guides/self-hosting/remove-superuser-access>
- <https://supabase.com/docs/guides/deployment/managing-environments>

The migrations therefore do not use `ALTER FUNCTION ... OWNER TO ...`. A blind
ownership change could fail in hosted Supabase or create an ownership model that
the CLI cannot subsequently manage. Instead, the pgTAP catalog contract asserts
that all six installed functions are owned by `postgres`. A failure blocks the
Batch 1 push and identifies that the project migration execution context does
not match the supported ownership model.

Every definer function retains `set search_path = ''`; protected tables,
functions, types, and Auth helpers are schema-qualified.

## Concurrency test

`002_last_owner_concurrency.test.sql` uses two independent PostgreSQL sessions
through `dblink`. Each scenario starts with two active owners. Session one
demotes, suspends, revokes, or deletes one owner and holds the trigger-acquired
account-row lock. Session two concurrently applies the same class of mutation to
the other owner.

The test checks that session two has an ungranted transaction-ID lock while the
two sessions are changing different membership rows. Because the membership
rows differ, the common contended row is the account row locked by the trigger.
After session one commits, session two must fail with the last-owner constraint,
and the test asserts that one active owner remains.

The test uses committed, fixed-ID fixtures so both sessions can observe them,
then disconnects and removes those fixtures. It is local-test-only and must run
against the disposable Supabase local database.

## Required local environment

The official Supabase CLI local stack requires a Docker-compatible container
runtime. Install and start Docker Desktop, OrbStack with Docker compatibility,
or another runtime that makes a working `docker` command available. Verify with
`docker info`, then run `npx supabase start` before the Supabase database tests
and lint. A linked remote database is not an acceptable substitute.
