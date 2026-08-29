# M2.8 Production Readiness Runbook

## Deployment

`develop` is the DEV integration branch. A push to `origin/develop` starts GitHub Database CI. CI installs pinned dependencies, builds the Vite application, reconstructs Supabase locally, runs all pgTAP files, and lints the schema. Only after the exact commit SHA is green may pending forward-only migrations be applied to Supabase DEV. Vercel deploys the same Git commit automatically; do not use a manual Vercel deployment unless the Git deployment fails and the exception is recorded.

The stable DEV URL is `https://a3-tracker-dev-nu.vercel.app`. `main` and `production-old/main` remain protected; M2.8 does not move or deploy either branch.

## Required configuration

The browser build requires `VITE_SUPABASE_URL` and `VITE_SUPABASE_PUBLISHABLE_KEY`. These are public client settings; no service-role credential may use a `VITE_` prefix or enter React code.

Edge Functions require `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_SERVICE_ROLE_KEY`, and a comma-separated `ALLOWED_ORIGINS`. `bootstrap-platform-superuser` additionally requires `PLATFORM_BOOTSTRAP_USER_ID` and `PLATFORM_BOOTSTRAP_TOKEN` only while explicit bootstrap is needed. Hosted Auth must disable public signup and allow only the intended DEV/Production redirect URLs. Keep DEV and Production projects, origins, redirect URLs, and secrets separate.

## Migration recovery

When Database CI fails, identify the exact SHA and failing step. Reproduce with `supabase db reset --local`, `supabase test db`, and `supabase db lint --local`. Inspect the local files against `supabase_migrations.schema_migrations` on DEV. Never edit an already-applied migration, manually mark an unapplied migration as complete, or rewrite ledger/history rows. Add a forward-only corrective migration, rerun clean reconstruction, and wait for exact-SHA green CI before `supabase db push`.

## Application recovery

- Blank screen: capture the route, time, selected Account/Branch, browser console diagnostic category, deployed SHA, and network failure. Use the recovery screen to reload. Verify Vercel serves the expected SHA before changing data.
- Failed login: verify the Edge Function deployment, `ALLOWED_ORIGINS`, Auth availability, public-signup setting, and whether the membership is active. Do not reveal whether a username/email exists.
- Settings missing: verify the signed-in user UUID has an active explicit `platform_user_privileges` row. Owner role, name, username, and email do not imply Platform Superuser.
- Incorrect Branch data: record the global selector value and route, then verify every operational request contains that Branch. Do not copy or insert data from another Branch to repair a projection issue.
- Failed write: record operation, route, Account, Branch, `client_request_id`, diagnostic category/code, and timestamp. Retry immutable/idempotent workflows with the same request identity where supported.
- Stale deployment: compare the Git SHA, GitHub run SHA, Vercel deployment commit, and browser asset deployment. Do not apply migrations for a SHA whose Database CI is not green.

## Data safety

Supabase PostgreSQL is authoritative. Component lifecycle, counter, inventory/FIFO, purchasing/receiving, incident, cost, audit, and other ledger/history evidence must not be manually rewritten casually. Unknown lifecycle and cost remain unknown; missing evidence remains missing. Acceptance must not create fake Machines, counters, PICs, lifecycle events, stock, purchases, receipts, incidents, or costs. Use rollback-scoped local fixtures for tests.

## Release checklist

Confirm a clean worktree; `develop == origin/develop`; exact-SHA GitHub CI green; local/remote migration ledgers aligned; local and remote schema lint clean; required Edge Functions deployed; Vercel exact SHA verified; protected branch refs unchanged; no P0/P1/security/tenant/data-integrity blockers; and no fake hosted data created. Production promotion is a separate explicit decision.
