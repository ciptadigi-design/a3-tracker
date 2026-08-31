# M2.12 Hostinger production readiness

Status: **CONDITIONAL — repository readiness work documented; live Hostinger preflight and complete Laravel frontend cutover remain required.**

This milestone does not deploy, change DNS, create a Production database, mutate DEV data, disable Vercel/Supabase, or start Maintenance.

## Architecture and compatibility

The target is one HTTPS origin, `https://a3.ciptagrafika.com`, with static Vite assets and Laravel `/api/v1/*` backed by MySQL/InnoDB. Node is build-time only. Laravel requires PHP `^8.2` (PHP 8.3 is preferred if Hostinger offers it), Composer 2, PDO MySQL, and the framework extensions `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `curl`, `bcmath`, and JSON. ZIP is useful for Composer but not a runtime requirement. MySQL 8.0.34+ with InnoDB and `utf8mb4` is the supported target; MariaDB is not accepted without a full compatibility run. These Hostinger facts are **PENDING HOSTINGER PREFLIGHT**.

| Dependency | Classification | Evidence / action |
|---|---|---|
| PHP 8.2+ and listed extensions | CONFIGURATION REQUIRED | `backend/composer.json`; run `scripts/deployment/hostinger-readiness.sh` |
| MySQL 8+, InnoDB, utf8mb4, PDO MySQL | CONFIGURATION REQUIRED | Laravel migrations and CI use MySQL 8; verify host variables and collation |
| Apache, `.htaccess`, SSL, document root | PENDING HOSTINGER PREFLIGHT | verify with Hostinger operator |
| Node/npm | NOT REQUIRED at runtime | build `npm ci && npm run build` before upload |
| Redis, WebSockets, Supervisor, systemd, Docker, root | NOT REQUIRED | current code has no persistent worker/realtime requirement |
| SSH, Composer, Git deployment, cron | PENDING HOSTINGER PREFLIGHT | either SSH Composer or packaged `vendor/`; cron is not required at launch |
| writable storage and bootstrap cache | CONFIGURATION REQUIRED | readiness helper checks both; never expose them publicly |

## Important current blocker

The repository is dual-stack, not yet a complete Laravel production frontend. `src/lib/api` has Laravel adapters for selected domains (including Reports), but `AuthProvider`, Tenant, and most feature pages still import `src/services/supabase/*`. Selecting `VITE_DATA_BACKEND=laravel` therefore does not currently provide a complete authenticated Laravel application. There is no silent Laravel→Supabase fallback in the Laravel report adapter, but production cutover must not be authorized until authentication, tenant context, and every launch page are ported behind the same adapter boundary. Supabase remains the reference implementation and is intentionally preserved.

## Runtime contract

Production must use `VITE_DATA_BACKEND=laravel`, `VITE_API_BASE_URL=/api/v1`, `APP_ENV=production`, `APP_DEBUG=false`, a unique operator-generated `APP_KEY`, `DB_CONNECTION=mysql`, `DB_CHARSET=utf8mb4`, and an HTTPS `APP_URL`. Secrets (DB password, APP_KEY, session secrets, SSH/API credentials) live only in Hostinger configuration. A safe variable-name inventory and environment comparison are in the cutover runbook. Current defaults are development-oriented; do not copy them verbatim.

Use database sessions only after confirming the `sessions` migration and writable MySQL target; file sessions are the documented shared-hosting fallback. Use `CACHE_STORE=file` (or database if host limits and the cache migration are confirmed) and `QUEUE_CONNECTION=sync`; no persistent worker is required. Scheduler required at launch: **NO**. If a future scheduled task is approved, add a Hostinger cron entry for `php artisan schedule:run` at the supported cadence.

Local filesystem storage is private under `storage/app`; public uploads require an explicitly approved `storage:link` or a non-symlink copy strategy. Logs use Laravel's daily file channel and must be inspected through SSH/File Manager, never through a public URL.

## Web root and routing

Preferred layout keeps the Laravel project outside the public document root and publishes the compiled `dist/` plus a controlled front controller. If Hostinger requires one document root, use Laravel `public/` as the document root, copy static frontend assets into a dedicated public subdirectory, and use an explicit rewrite file: real assets first; `/api/*`, `/sanctum/*`, `/up`, and `/api/v1/version` to Laravel; all other application paths to the React `index.html`. API paths must never be swallowed by SPA fallback. `.env`, `.git`, `composer.json`, `artisan`, `vendor`, migrations, database files, logs, and private storage must be denied.

The current in-app router supports `/`, `/daily`, `/components`, `/inventory`, `/machine-cost`, `/errors`, `/reports`, and `/settings`; direct-refresh verification belongs in the Hostinger preflight. Same-origin API avoids permissive credentialed CORS. Cookies must be Secure, HttpOnly where applicable, SameSite=Lax/Strict, and scoped to the production origin over HTTPS.

## Release identity and checks

`GET /api/v1/version` already returns `git_sha` from `APP_GIT_SHA` and the migration batch. Each package must include a generated, secret-free release manifest containing approved Git SHA, build timestamp, frontend build identifier, and expected migration ledger. The version endpoint and manifest are the acceptance evidence for “what exact commit is running”. The non-destructive checker is `scripts/deployment/hostinger-readiness.sh [backend-dir]`.

Deployment order is: backup files and database; upload an immutable package; supply `.env`; run `composer install --no-dev --classmap-authoritative` either remotely or in CI; run `php artisan config:cache`, `route:cache`, and `view:cache` only after config validation; run `php artisan migrate --force`; verify health/version and protected API; activate the release. Never use `migrate:fresh`, `migrate:refresh`, or `db:wipe` in Production.

Fresh and no-op migration behavior is covered by the existing Laravel/MySQL CI (`33400207117` on the accepted baseline). A local Docker rehearsal could not run because this workstation has Docker Engine but no Compose plugin; this is recorded as a limitation, not fabricated as a pass. Existing disposable migration/backup/rollback artifacts under `scripts/migration/reconciliation/m2-11/` are non-production evidence.

## Security and operations

Sanctum, active-user middleware, account/branch/machine resolvers, and Settings Superuser policy are the Laravel target controls. Validate anonymous denial, disabled-user denial, membership and branch isolation, Counter Operator capability, and Operator/PIC semantics before cutover. Health returns status/database reachability/environment/version without credentials; exception responses use a generic 500 message and request ID.

Backups: preferred Hostinger/native backup plus an independent logical `mysqldump --single-transaction --routines --triggers` through SSH; emergency fallback is a phpMyAdmin export. Restore only into a disposable/approved target before go/no-go. Back up `.env`, `storage`, current release, and public assets. Rollback is release-only first, then application plus known-good DB restore, then abort DNS/return traffic to the legacy system.

## Readiness classification

Repository architecture, migration history, version endpoint, security model, and runbook artifacts are documented. Hostinger-specific PHP/Apache/SSH/permissions/SSL/backup checks are **PENDING HOSTINGER PREFLIGHT**. Complete Laravel frontend/auth adapter coverage is a **non-destructive blocker before deployment authorization**. Vercel/Supabase DEV and legacy Production remain preserved; DNS and Hostinger remain untouched. Advanced Economics and Maintenance remain out of scope.
> M2.12A update: production runtime is selected with `VITE_DATA_BACKEND=laravel`; frontend auth/account flows use Laravel session APIs and mobile navigation exposes My Account and Logout from the drawer. Supabase-specific implementation names are retained only in reference adapters and developer documentation.
## Application backend parity closure

M2.12A.1 keeps the existing backend-neutral service selector and closes the visible Laravel-mode governance, inventory, incident, component, machine-cost, and account operations. Mobile users reach identity, My Account, and Logout from the scrollable hamburger drawer; desktop profile controls remain unchanged. Production-facing copy does not expose Supabase implementation details. This document does not authorize deployment, DNS changes, or a Hostinger preflight; those belong to M2.12B.
