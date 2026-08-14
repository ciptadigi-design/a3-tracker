# CI database testing

## When it runs

The `Database CI` GitHub Actions workflow runs on:

- every pull request whose base branch is `develop`;
- every push to `develop`.

It runs on an ephemeral Ubuntu GitHub-hosted runner with read-only repository
permissions. Concurrent runs for the same ref are cancelled when a newer commit
supersedes them.

## What it validates

The workflow validates both the application build and the version-controlled
database contract:

1. installs JavaScript dependencies with `npm ci`;
2. builds the application with `npm run build`;
3. verifies and uses Supabase CLI `2.114.0`;
4. starts a clean local Supabase stack;
5. reconstructs PostgreSQL from `supabase/migrations/` and `supabase/seed.sql`;
6. runs every pgTAP test under `supabase/tests/database/`;
7. runs Supabase database lint against the local database;
8. stops and deletes the runner's local Supabase state.

The workflow does not run the existing unrelated application lint debt and does
not run `npm audit fix` or otherwise mutate dependencies.

## Why hosted credentials are not needed

All database work occurs inside Docker containers on the disposable GitHub
runner. The workflow does not contain or consume a Supabase access token, hosted
database password, hosted project ref, hosted service-role key, or any GitHub
environment containing deployment credentials.

The checkout does not include `supabase/.temp`, which is ignored by Git. The
runner therefore has no linked project metadata. The workflow uses only local
commands: `supabase start`, `supabase test db`, `supabase db lint --local`, and
`supabase stop --no-backup`. It never links a project, pushes a database, resets
a remote database, or performs remote migration operations.

Repository permissions are limited to `contents: read`, so the workflow is a
test gate rather than a deployment workflow.

## Clean migration reconstruction

GitHub-hosted runners are ephemeral and begin without this project's Supabase
containers or Docker volumes. `supabase start` initializes a new local Postgres
17 database from `supabase/config.toml`, installs Supabase-managed schemas, and
applies the migration files in `supabase/migrations/` in version order. The
configured empty Batch 1 seed then runs.

No hosted database dump or hosted schema is restored. A missing, out-of-order,
or invalid migration therefore prevents the local stack from starting and fails
the workflow. This proves that a brand-new database can be reconstructed from
the repository.

## pgTAP security tests

The workflow runs:

```sh
npx --yes "supabase@2.114.0" test db
```

The Supabase CLI discovers the SQL tests in `supabase/tests/database/`. Batch 1
currently includes:

- `001_auth_tenant_foundation.test.sql`, covering catalog privileges,
  SECURITY DEFINER properties, RLS authorization, tenant isolation, profile
  leakage, membership controls, and suspended-user isolation;
- `002_last_owner_concurrency.test.sql`, using two real PostgreSQL sessions to
  verify the account-row locking invariant for concurrent owner demotion,
  suspension, revocation, and deletion.

Any failed assertion, SQL error, missing plan, or non-zero pgTAP result fails the
job. The concurrency test is not skipped or replaced in CI. It derives the
database container's TCP address at runtime, so it does not depend on a Docker
Desktop-specific hostname.

## Database lint

After pgTAP passes, the workflow runs:

```sh
npx --yes "supabase@2.114.0" db lint --local
```

The command analyzes the locally reconstructed `public` and extension schemas.
Actual schema errors return a non-zero exit status and fail the job. No lint
rules are weakened or ignored by the workflow.

## Interpreting failures

- `npm ci` failure: the lockfile and package manifest may disagree, or the npm
  registry may be unavailable.
- Application build failure: inspect the Vite compiler output before considering
  database changes.
- Supabase start failure: inspect migration ordering, SQL errors, configured
  Postgres version, port conflicts, or transient container-image pulls.
- pgTAP failure: the output identifies the failing SQL file and assertion. Treat
  security or isolation regressions as blocking.
- Concurrency failure: distinguish a failed lock/invariant assertion from a
  Docker networking or secondary-session authentication error; do not skip it.
- Database lint failure: fix the reported schema error rather than suppressing
  the lint command.
- Timeout or image-pull failure: rerun once to distinguish transient GitHub or
  registry availability from a deterministic repository failure.

The cleanup step uses `if: always()` so local runner containers are stopped even
when an earlier validation step fails.

## Adding future migration batches

For each future batch:

1. add ordered migration files under `supabase/migrations/`;
2. add rollback-safe functional and catalog tests under
   `supabase/tests/database/` with the next numeric prefix;
3. add real multi-session tests when correctness depends on locking or
   transaction ordering;
4. keep fixtures deterministic and clean them up when they must be committed for
   visibility across sessions;
5. run the same pinned CLI commands locally when Docker is available;
6. require this workflow to pass before merging to `develop`.

Do not add hosted credentials to make a test pass. Tests requiring remote data
or deployment belong in a separately reviewed workflow with an explicit safety
model, not in this local database gate.

## Local Docker versus GitHub Actions

Once this workflow is proven working, Docker Desktop is optional for normal
development. GitHub Actions is the routine migration, RLS, security, concurrency,
and database-lint gate.

Local Docker remains useful only for fast local database debugging and testing
before a push. Developers with a Docker-compatible runtime can run:

```sh
npx --yes "supabase@2.114.0" start
npx --yes "supabase@2.114.0" test db
npx --yes "supabase@2.114.0" db lint --local
```

Local success is helpful feedback, but it does not replace the required GitHub
Actions result on the shared branch.
