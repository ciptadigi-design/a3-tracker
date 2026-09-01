# M2.12D — Hostinger production cutover preparation

Status: **M2_12D_PRODUCTION_CUTOVER_PREPARATION_CONDITIONAL**

This milestone prepares and rehearses the eventual cutover. **No Production mutation was performed.** The Production website, database, DNS, SSL, cache/CDN, files, and secrets remain untouched. `u777904340_NX8rN` was not accessed.

## Baseline and rehearsal evidence

- Starting repository SHA: `8dac737c816fabb4a04a6c79e367eb9316724a2c` on `develop`; application SHA accepted from M2.12C: `b69c7e125f083f52dc519f4a3cc3d401ba5a64b0`.
- The closest full migration rehearsal is the committed non-Production M2.11 disposable rehearsal (`scripts/migration/reconciliation/m2-11/6a8f4bb8-1f31-4e03-a9db-fdd9821e8d78`). It ran from an empty target through migrations, deterministic bootstrap, import, reconciliation, failure rollback, and a repeat apply.
- Hostinger M2.12C isolated restore (`u777904340_stagingrestore`) proved backup/restore of the staging schema and synthetic data; it is not a Production-data source and was not used for a destructive import.
- Rehearsal target engine: disposable PostgreSQL reference target (project ref `wtslqxjwjqyjgcapfrrz`); Hostinger staging runtime remains MariaDB 11.8.8/InnoDB/utf8mb4 and is covered by M2.12C MySQL CI and runtime evidence.

## Source inventory and locked dispositions

The accepted legacy snapshot contains 526 rows:

| Source table | Rows | SHA-256 fingerprint |
|---|---:|---|
| click_history | 182 | `c1e69897a12caf54eb61064f30a799be7fd41e960a3909aff3d8969834393ee5` |
| part_replacements | 70 | `2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e` |
| error_logs | 91 | `65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b` |
| inventory_parts | 22 | `ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632` |
| part_purchases | 161 | `6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306` |

All legacy operational rows are Tuparev-only. Graha legacy rows are explicitly excluded. Date-only counters receive deterministic synthetic timestamps; `daily_clicks` is derived, not imported. Five ambiguous replacements remain archived/excluded by the approved mapping. “Other Part” never becomes `TEST_COMPONENT`. Purchases are acquisition evidence only (161 rows, 208 units, IDR 371,029,998); they do not fabricate receipts, stock, FIFO layers, or movements. Opening stock requires explicit stock-opname evidence. Errors remain immutable; the accepted incident result is 89 imported and 2 duplicate rows skipped. Unknown opening cost remains SQL `NULL`, never zero.

The deterministic rehearsal plan accounted for every source row: `IMPORT=477`, `SKIP_DUPLICATE=2`, `ARCHIVE_ONLY=45`, `APPROVED_EXCLUDE=2`, `MERGE=0`, `MANUAL_REVIEW=0`, unexplained remainder `0`.

## Crosswalk and reconciliation

Production IDs must be generated/resolved by exact business keys, never by assuming DEV UUID equality. Required keys are Account `Cipta Grafika`, Branch `Tuparev`, Machine `CG-TUP-A3-01`, model `Konica Minolta AccurioPress C1070 / bizhub PRESS C1070`, approved Tuparev location, person code/branch, catalog code, model profile, and slot code. Every foreign key must resolve or the import stops; fuzzy/best-effort mapping is prohibited.

The rehearsal produced 10 operational people, 180 counters, 47 lifecycles, 9 suppliers, 22 inventory items, 161 purchases, and 89 incidents; 28 target assignments and 47 lifecycle rows reconciled. Unresolved required mappings: `0`. Graha leakage: `0`. Legacy-derived FIFO receipts/movements: `0`. Stock-opname fixture evidence remained isolated, with known-cost and unknown-cost quantities represented separately and unknown cost preserved as `NULL`. Failure injection rolled back, dry-run left the target unchanged, first apply passed, and second apply was idempotent. Counter history is append-only; accepted reference readings are 1,437,283 (26 Aug 18:15), 1,437,911 (+628), and 1,438,992 (+1,081); August effective usage is 1,709 clicks.

## Authentication and environment plan (not provisioned)

Authentication users are distinct from operational people. The eventual cutover will use the reviewed `php artisan platform:bootstrap-superuser <user_id> --confirm=GRANT_PLATFORM_SUPERUSER` procedure, explicit account/branch/membership bootstrap, and secure TTY password injection. No Production user or password was created in M2.12D.

Production `.env` is prepared as a secret-name specification only: `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY`, `APP_GIT_SHA`, MySQL connection/credentials/charset/collation, session/Sanctum/CSRF settings, `FRONTEND_URL`, cache/queue/log/filesystem/mail settings, and `VITE_DATA_BACKEND=laravel`. Secrets are injected out of band; no Production `.env` or `APP_KEY` is written now.

## Release, backup, freeze, and rollback plan

The planned private topology is `/home/u777904340/a3-app/{shared,releases/<SHA>,current}` with `current` a symlink. `public_html` will expose only the controlled Laravel front controller, rewrite file (mode 644), and built public assets; `.env` is mode 600, private storage remains outside the web root, and `storage`/`bootstrap/cache` are writable only as required. Release upload and activation are separate: upload and verify manifest/SHA, inject environment, cache config/routes/views, migrate, smoke-test, then switch `current` atomically.

At T-24h the owner records release SHA, CI, access, backup and rollback readiness. During the separately authorized freeze, writes are stopped and verified rejected; the Asia/Jakarta freeze timestamp, final source snapshot, counts, fingerprints, counters, and compressed logical backup are captured. The backup hash and gzip/archive test are mandatory. GO requires all source/target/reconciliation/auth/health gates below. Rollback is release switch first when schema-compatible, then application plus verified database restore, then DNS/legacy traffic abort; never ad-hoc row deletion or `migrate:fresh`.

## GO / NO-GO matrix

| Gate | M2.12D evidence | Decision |
|---|---|---|
| SOURCE_FREEZE_RECORDED / SOURCE_BACKUP_VERIFIED / SOURCE_FINGERPRINTS_MATCH | Final Production freeze is not authorized or executed | NO-GO |
| TARGET_BACKUP_VERIFIED / TARGET_SCHEMA_MIGRATED | Proven in disposable rehearsal and M2.12C isolated restore; Production target not touched | CONDITIONAL |
| CROSSWALK_COMPLETE / UNRESOLVED_REQUIRED_MAPPING_0 | Deterministic rehearsal: complete, unresolved 0 | PASS for rehearsal |
| GRAHA_LEGACY_ROWS_0 / FAKE_STOCK_CREATED_0 / UNKNOWN_COST_COERCED_TO_ZERO_0 | 0 / 0 / 0 in reconciliation evidence | PASS |
| MIGRATION, COUNTER, PURCHASE, INCIDENT, REPLACEMENT totals | Reconciled (526 source; 180 counters; 161 purchases/208 units; 89 incidents; 47 lifecycles) | PASS for rehearsal |
| AUTH_BOOTSTRAP_PASS / HEALTH_PASS / VERSION_SHA_PASS / SANCTUM_PASS / AUTHENTICATED_SMOKE_PASS / RESPONSIVE_SMOKE_PASS | Staging M2.12C evidence accepted; Production bootstrap not executed | CONDITIONAL |
| SUPABASE_RUNTIME_REQUESTS_0 | Staging browser evidence: 0 | PASS for staging |
| ROLLBACK_READY | Rehearsal failure rollback and idempotent repeat passed; Production backup/release still pending | CONDITIONAL |

Any false required gate is NO-GO. M2.12D therefore remains **CONDITIONAL** pending an explicitly authorized Production-source freeze/backup and Production-target preflight/bootstrap approval. The next milestone must separately authorize the Production cutover; it is not implied by this document.

## Test evidence

Migration unit/reconciliation tests: 24 passed. Laravel suite: 1 passed, 55 environment-dependent tests deprecated/skipped locally because MySQL is not configured (no failure asserted). Frontend `npm run build`: passed. M2.12C exact-SHA CI remains Database `33470449131` and Laravel MySQL Target `33470449136`, both successful for application SHA b69. Documentation-only M2.12D changes do not trigger a redeploy.
