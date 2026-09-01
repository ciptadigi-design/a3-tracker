# M2.12E — Production cutover execution

## Phase 1 — read-only preflight

Status: **M2_12E_PRODUCTION_CUTOVER_PHASE1_CONDITIONAL**

This phase performed read-only repository, Hostinger, and public HTTP checks only. Production remained untouched: no database, files, `public_html`, `.env`, APP_KEY, release, symlink, DNS, SSL, cache/CDN, user, or traffic mutation was performed. `u777904340_NX8rN` was not accessed.

### Repository baseline

- Starting and current repository SHA: `5166e4d73e478625a17b54223691a72326ff68e1` (M2.12D documentation closure; no application code changed after the browser-tested application SHA).
- Branch: `develop`; worktree clean; `develop == origin/develop`.
- `main`: `7f35603fc9b1eeed7b85901ab77a6e121b57a005`.
- `production-old/main`: no remote ref returned; no mutation attempted.
- M2.12C browser-tested application SHA selected for eventual cutover: `b69c7e125f083f52dc519f4a3cc3d401ba5a64b0`. `git diff b69..HEAD -- ':!docs'` is empty; later commits are documentation-only.
- Accepted staging evidence remains authoritative: responsive authenticated smoke passed, Inventory regression passed, zero operational Supabase requests, Database CI `33470449131`, Laravel MySQL Target CI `33470449136`.

### Hostinger identity and Production root

Authenticated SSH target `u777904340@145.223.108.179:65002` reported hostname `id-dci-web1761.main-hosting.eu`, `whoami=u777904340`, uid `777904340`, gid `2006597395`, and `HOME=/home/u777904340`. CLI tools are available: PHP 8.2.30, Composer 2.9.8, MariaDB client 11.8.8, rsync, tar, and git. Home filesystem reported 21T total / 64% used and inode usage 60%; no mutable resource action was taken.

The verified Production document root is `/home/u777904340/domains/a3.ciptagrafika.com/public_html`, owner `u777904340`, group `o1006597395`, mode `755`. Metadata-only listing:

- `default.php` — regular file, owner `u777904340`, mode `644`, Hostinger placeholder.
- `staging/` — directory, owner `u777904340`, mode `755`, containing only another Hostinger `default.php` placeholder (not an application release).
- No `.htaccess`, `index.php`, release directory, symlink, Laravel files, or other unexpected application files.

`default.php` SHA-256 is exactly `aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45`. The public HTTPS probe confirms Hostinger/HCDN headers and the default page: `/` is HTTP 200 Default page; `/up`, `/api/v1/health`, and `/api/v1/version` are HTTP 404 placeholder responses. No mutating endpoint was called.

### Private root and database target plan

The intended private Production root is `/home/u777904340/a3-production-app` with `shared/`, immutable `releases/<SHA>/`, and `current` symlink. Read-only inspection confirms this path is currently absent. It must not be created until Phase 2 GO authorization.

Production requires a fresh, dedicated Hostinger database and user. `u777904340_NX8rN` is permanently excluded and was not inspected. Existing staging databases are not reusable Production targets. Repository evidence does not yet lock a Hostinger suffix; the deterministic naming proposal for the Phase-2 hPanel action is suffix `a3production`, yielding `u777904340_a3production` for both database and dedicated user, subject to hPanel acceptance. No database/user was created in Phase 1. If hPanel rejects that suffix, stop and obtain an approved replacement before any migration work.

Expected manual Phase-2 hPanel action (not performed): Databases → MySQL Databases → create database suffix `a3production`; create a separate user suffix `a3production`; grant that user privileges only on the new database; record the exact Hostinger-prefixed names without posting the password. Password entry must be hidden TTY input; no credentials belong in Git, docs, arguments, logs, or chat.

### Migration source and identity plan

The canonical source mechanism is the read-only `scripts/migration/capture-legacy-snapshot.mjs`, which calls the explicitly configured `LEGACY_SUPABASE_URL` and `LEGACY_SUPABASE_ANON_KEY` through an allow-listed GET-only audit and writes a mode-600 snapshot outside the repository. Historical M2.10B evidence records project `wtslqxjwjqyjgcapfrrz` as the legacy source and shows two GET-only captures with identical accepted counts/fingerprints. The M2.11 rehearsal target was instead the local Docker container `supabase_db_konica-tracker-next` (`DISPOSABLE_REHEARSAL`, hosted project ref null). Thus the identities are distinct: `wtslqxjwjqyjgcapfrrz` is the authoritative legacy-source project; the local container is the rehearsal target. Supabase remains reference/oracle only until a separately authorized freeze. The final source is **not** the forbidden operational DB by inference.

At Phase 2 freeze, the operator must inject source credentials through a secure TTY, stop and verify rejected writes, capture the final snapshot, counts, fingerprints, deterministic counter timestamps, and a compressed logical source backup, then hash and test the archive. The accepted baseline (526 rows and five fingerprints) is a reference, not a requirement that the final frozen snapshot remain identical after legitimate writes.

Production crosswalks use exact account/branch/machine/model/location/person/catalog/profile/slot business keys. DEV UUIDs are reference evidence only. Required unresolved mappings must equal zero; Graha legacy rows, fake stock, and zero-coerced unknown costs must remain zero.

### Authentication and release plan

Auth users remain distinct from operational people. Phase 2 will create the approved first user out of band, set its password through hidden TTY input, run `php artisan platform:bootstrap-superuser <user_id> --confirm=GRANT_PLATFORM_SUPERUSER`, and bootstrap Cipta Grafika/Tuparev membership and branch assignment transactionally. No Production user was created.

The selected release is application SHA `b69c7e125f083f52dc519f4a3cc3d401ba5a64b0`; documentation commits must not be deployed as a different application release. Build plan: `VITE_DATA_BACKEND=laravel`, no Supabase frontend variables, `npm ci && npm run build`; package Laravel with `composer install --no-dev --classmap-authoritative`; validate `config/view.php`; inject `APP_ENV=production`, `APP_DEBUG=false`, `APP_GIT_SHA=b69...`; build config/route/view caches only after environment validation; set public `.htaccess` mode `644`.

### Secret, freeze, backup, and Phase-2 order

Production `.env` and APP_KEY are not created in Phase 1. Phase 2 uses hidden TTY injection for APP_KEY and the dedicated DB name/user/password, with `SESSION_DRIVER=file`, secure cookies, `SANCTUM_STATEFUL_DOMAINS=a3.ciptagrafika.com`, `FRONTEND_URL=https://a3.ciptagrafika.com`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, daily error logging, and local private storage.

Mutation order is: final GO authorization → source freeze → source backup/hash/integrity → Production `public_html`/placeholder/private-root backup → dedicated DB create/identity proof → private release root → secret injection → Laravel migrations → master/auth bootstrap → approved import → reconciliation → release install → cache build → public-root preparation → atomic activation → health/version → Sanctum/auth → operational/responsive smoke → observation → acceptance. Any failed invariant immediately stops and invokes the documented release/database/DNS rollback decision tree.

### GO / NO-GO gate

The following rehearsal/staging gates are proven: deterministic crosswalk, unresolved mappings `0`, Graha leakage `0`, fake stock `0`, unknown-cost coercion `0`, migration counts, counter/purchase/incident/replacement reconciliation, staging health/version/Sanctum/authenticated smoke, responsive smoke, Supabase runtime requests `0`, and rollback rehearsal. Phase 1 remains NO-GO for execution because these Production-specific gates are not yet authorized or captured:

- `SOURCE_FREEZE_RECORDED`, final frozen-source counts/fingerprints, and source backup verification.
- `PRODUCTION_BACKUP_VERIFIED` for the current root and any target state.
- Dedicated Production DB/user creation and `SELECT DATABASE()` identity proof.
- Production-specific crosswalk/bootstrap approval and secure secret injection authorization.
- Final owner authorization to begin Phase 2.

**STOP POINT:** Phase 1 ends here. Await explicit user authorization before source freeze, any hPanel database/user creation, Production backup archive creation, secret injection, release creation/upload, migrations, user bootstrap, `public_html` mutation, or traffic activation.

### Safe local verification

- Frontend build: passed.
- Migration/reconciliation tests: `43 passed, 1 skipped`.
- Laravel tests: `54 passed, 2 skipped` (MySQL integration assertion is reserved for target CI).
- Application tree comparison against b69: no non-documentation differences.
- Existing exact-SHA CI: Database `33470449131` success; Laravel MySQL Target `33470449136` success.

## Phase 2A — freeze, verified backups, and dry-run evidence

- Freeze recorded: `2026-09-01T13:15:57+07:00` (`2026-09-01T06:15:57Z`); the authoritative source remained operationally frozen.
- Final frozen source remains the original mode-600 file supplied for this phase: `/var/folders/44/bzxc_f7n7r72ht1vgnh43fxm0000gn/T//a3-m212e-source.8SIKM9/final-frozen-source.json`.
- GET-only stability re-check passed: `SOURCE_CHANGED_DURING_CAPTURE=NO`.
- Final source totals: 529 rows — `click_history=185`, `part_replacements=70`, `error_logs=91`, `inventory_parts=22`, `part_purchases=161`. The +3 delta versus the 526-row baseline is exactly three newer `click_history` rows (counters 1,440,494; 1,440,859; 1,441,597).
- Final fingerprints: click history `a577d071b32150c7ad13fd4b70c0fe6d9c4d3a7be08c27ad3efb8679cdeca537`; all other table fingerprints remain the accepted baseline values.
- Final source backup: `/var/folders/44/bzxc_f7n7r72ht1vgnh43fxm0000gn/T//a3-m212e-backup.zTSJgQ/final-frozen-source-20260901T131557+0700.json.gz`, 26,955 bytes, SHA-256 `33b0cf4f3be5ff2a03cf8c1156c7e556fdf762a5f971975b984fadcc233d036f`; `gzip -t` passed; mode 600.
- Production root backup: `/home/u777904340/m2-12e-backups/20260901T131557+0700/production-public_html.tar.gz`, 6,034 bytes, SHA-256 `92f9facfcbcbd60505d40f9500f05412b52e27bb3ff939c6a1275569caebc76d`; gzip integrity passed. Metadata manifest is `/home/u777904340/m2-12e-backups/20260901T131557+0700/production-public_html.manifest` (mode 600). The archive includes `default.php` and `staging/`; backed-up `default.php` hash remains `aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45`.
- `/home/u777904340/a3-production-app` remains absent. No Production file was altered or removed.
- Final-source dry-run planning accounted for all 529 rows: `IMPORT=480` (477 baseline imports plus the three new counters), `MERGE=0`, `SKIP_DUPLICATE=2`, `ARCHIVE_ONLY=45`, `APPROVED_EXCLUDE=2`, `MANUAL_REVIEW=0`, unexplained remainder `0`. Planned counters: 183. Purchases: 161 rows / 208 units / IDR 371,029,998. Replacements/lifecycles: 47. Incidents: 89. Legacy opening stock, receipts, movements, and FIFO: 0. Unresolved required mappings: 0; planned Graha legacy rows: 0; fake stock: 0; unknown-cost coercions: 0. The three added counter IDs receive deterministic UUIDs/timestamps and `ojan`/`ijal` operator evidence; no Production target was written.
- At the time of this evidence capture, dedicated Production database/user creation was pending through Hostinger hPanel. That gate is closed below; no migrations, imports, users, `.env`, APP_KEY, release, symlink, public activation, DNS, SSL, cache/CDN, or traffic action was performed.

## Phase 2A — Production database verification and closure

Manual Hostinger verification completed the dedicated Production database gate. The following evidence was supplied by the authorized operator and contains no credential material:

- Database: `u777904340_a3production`.
- Dedicated database user: `u777904340_a3production`.
- First identity query: `SELECT DATABASE()` returned exactly `u777904340_a3production`.
- Runtime compatibility: `11.8.8-MariaDB-log`; InnoDB is supported and the default engine; charset `utf8mb4`; collation `utf8mb4_unicode_ci`; SQL mode `NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`; timezone `SYSTEM`; `lower_case_table_names=0`.
- Empty-target proof: `APPLICATION_TABLE_COUNT=0`; no migrations or application data were present.
- Authenticated identity: `u777904340_a3production@localhost`; privileges were verified at database level for the dedicated target only. No other database was inspected.
- The database password was rotated after an earlier accidental hash exposure. Rotation was completed manually; no password, hash, secret, or credential material is recorded here.
- Minimal post-rotation verification again returned the exact database identity, authenticated identity, and `APPLICATION_TABLE_COUNT=0`.
- All prior Phase 2A evidence remains valid: `SOURCE_CHANGED_DURING_CAPTURE=NO`; final frozen source `529` rows (`click_history=185`, `part_replacements=70`, `error_logs=91`, `inventory_parts=22`, `part_purchases=161`); latest counter `1,441,597`; source and Production-root backups verified; the recorded Production `default.php` hash remains `aba5b5856471c610e4dd52c322c7a72a895fc9bf98ac1d027528d0e7de1f7e45`; private root absent; deterministic dry-run passed; unresolved mappings, Graha legacy rows, fake stock, unknown-cost coercions, and unexplained remainder all remain `0`.

Phase 2A is closed as documentation-only. No Laravel migration, schema creation, import, bootstrap, secret-file creation, release, filesystem/public-root mutation, traffic activation, or staging redeploy was performed. The selected application SHA remains `b69c7e125f083f52dc519f4a3cc3d401ba5a64b0`.

**Classification:** `M2_12E_PRODUCTION_CUTOVER_PHASE2A_READY`

**Next milestone (not started):** M2.12E Phase 2B — Production Schema Migration + Bootstrap + Frozen Data Import + Reconciliation. Phase 2B must stop before Production public activation.
