# M2.12 Hostinger cutover rehearsal runbook

M2.12D preparation status: **CONDITIONAL**. See [M2.12D production cutover preparation](M2_12D_PRODUCTION_CUTOVER_PREPARATION.md) for the locked data inventory, rehearsal evidence, GO/NO-GO matrix, and exact remaining authorization gates. The procedure below is executable only after those gates are explicitly approved.

All Production steps below are **DOCUMENTED / NOT YET EXECUTED IN M2.12**. This is a human-operated plan; it is not a one-click deployment script.

## Prerequisites and go/no-go

- [ ] Hostinger staging rehearsal is accepted (M2.12C SHA `b69c7e125f083f52dc519f4a3cc3d401ba5a64b0`; CI 33470449131/33470449136). Production preflight and target backup remain pending.
- [ ] Laravel frontend/auth/tenant adapter coverage is complete; `VITE_DATA_BACKEND=laravel` is the only Production backend.
- [ ] Approved Git SHA, release manifest, migration status, data crosswalk, operators, rollback owner, and business acceptance owner are recorded.
- [ ] Vercel + Supabase DEV and legacy Production remain available as reference/rollback systems.

## Freeze, snapshot, and backup

1. Obtain explicit Production cutover authorization, announce a controlled write freeze, and record the Asia/Jakarta timestamp; do not activate it during M2.12D.
2. Technically disable writes at the source (application maintenance/read-only gate or Supabase policy switch) and verify rejected writes.
3. Capture UTC timestamp, source project identifier, operator, row counts, and SHA-256 fingerprints for the final accepted snapshot.
4. Classify post-legacy DEV deltas using `docs/M2_12_DATA_CUTOVER_MATRIX.md`; never clone DEV blindly.
5. Back up the target database and files before any release or import. Preferred SQL command is `mysqldump --single-transaction --routines --triggers`; use Hostinger backup tooling or phpMyAdmin only as documented fallback.

## Build and upload

1. From the approved SHA run `npm ci && npm run build`.
2. Build Laravel dependencies with `composer install --no-dev --classmap-authoritative` locally/CI if remote Composer is unavailable.
3. Package `dist/`, Laravel application code, `vendor/` (if needed), `public/`, and a secret-free release manifest. Exclude `node_modules`, `.git`, tests, local `.env`, dumps, logs, and development artifacts.
4. Upload to a staged release directory. Keep the current release intact until verification.
5. Supply a unique Hostinger `.env` with `APP_ENV=production`, `APP_DEBUG=false`, Laravel backend settings, MySQL credentials, secure cookies, and `APP_GIT_SHA`.

## Database and bootstrap

1. Confirm `php artisan migrate:status` against the intended Production database (read-only preflight first).
2. Run `php artisan migrate --force` only; migrations are forward-only and must be additive/compatible or explicitly planned.
3. Bootstrap the first Platform Superuser with the explicit, audited `php artisan platform:bootstrap-superuser <user_id> --confirm=GRANT_PLATFORM_SUPERUSER` workflow after setting a one-time credential out of band; no public signup or committed password.
4. Bootstrap account, branches, machines, models, profiles, components, inventory masters, memberships, and operational people by deterministic natural keys/crosswalks. DEV UUID equality is never assumed.
5. Import only approved historical rows and validate source counts, dispositions, fingerprints, economic totals, snapshots, and ledger totals.

## Verification and activation

Read-only order: health → version/release SHA → login/logout → disabled-user denial → membership/branch scope → direct route refresh → Reports → Counter → Components → Inventory → Machine Cost → Errors → Settings policy. API routes must return JSON and never React HTML. Sensitive-file probes for `/.env`, `/.git/`, `/composer.json`, `/artisan`, `/storage/logs`, `/database`, and `/vendor` must be denied.

Activate the staged release only after every M2.12D GO gate passes. DNS change and Production traffic cutover are separate, explicitly authorized actions and are **not executed in M2.12D**. Monitor logs, health, authentication, and scoped read traffic before unfreezing writes.

## Rollback

- **Level 1:** restore the previous application release when schema is backward compatible.
- **Level 2:** restore the known-good database backup and previous release in a controlled window; never manually delete rows or run `migrate:fresh`.
- **Level 3:** abort DNS cutover or return traffic to legacy Production while preserving source and target evidence.

After rollback, verify health/version, auth, scope, and data fingerprints, then document the decision and keep the freeze until an owner approves unfreeze.
