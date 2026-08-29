# M2.10B Hosted DEV Staging Migration

Date: 2026-08-30 (Asia/Jakarta)
Repository baseline: `b29d26a79d0a5100cc90daa2928ff8564f4971e5`
Execution ID: `958bb2b3-3110-410f-9c03-d8355a2f9be7`
Target: Supabase DEV `sxitqjxljoqsnpepymrl`
Status: **pre-apply gates complete; exact-SHA commit/CI gate pending**

## Approved decisions

- Five application-visible source tables are the accepted hosted-staging scope.
- Cipta Grafika `357e420a-c9ea-4404-9da4-f254c5dce5ef`, Tuparev `76d3c7ab-55c3-40f7-b133-0ef54a448893`, and existing physical Machine `CG-TUP-A3-01` `b4ca07ee-c588-404d-abcf-b6a029e68776` are locked.
- Graha `9f753339-0d54-42c9-9bb6-afe2461803f8` is denylisted.
- Counter rows `54da82a6-b723-442c-880f-19215e1f35bb` and `82d3b425-7273-415d-8c49-338d92405962` are `APPROVED_EXCLUDE_FOR_STAGING` and remain auditable.
- `oan` maps to existing Operational Person Akmal Fauzan. Current source evidence still supports the accepted Ojan/Akmal alias chain.
- `sri bulan` remains `ARCHIVE_TEXT_ONLY`: proximity to Bulan events does not prove identity strongly enough.
- Epri, Bigel, Bulan, Daniel, Dhea, Lydia, Marsya, Nabilah, Pinkan, and Ramdani are approved Operational People only. No Auth identities, memberships, emails, passwords, or roles are created.
- Five generic replacement rows are archive-only. No Drum/Charging Corona color or Fuser slot is fabricated.
- Historical purchases are Tuparev-owned draft acquisition evidence with `LEGACY_IMPORT` and `RECEIPT_UNKNOWN_NOT_REPRESENTED`; they do not create receipts, movements, balances, or FIFO.
- The 22-row/11-unit inventory snapshot is archive evidence only and creates no opening stock.

## Fresh source snapshot

The source snapshot was captured GET-only from legacy project `wtslqxjwjqyjgcapfrrz` at `2026-08-29T20:33:53.321Z` (2026-08-30 Asia/Jakarta). The private snapshot is mode `0600` and is not committed.

| Table | Rows | SHA-256 |
|---|---:|---|
| `click_history` | 182 | `c1e69897a12caf54eb61064f30a799be7fd41e960a3909aff3d8969834393ee5` |
| `part_replacements` | 70 | `2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e` |
| `error_logs` | 91 | `65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b` |
| `inventory_parts` | 22 | `ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632` |
| `part_purchases` | 161 | `6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306` |
| **Total** | **526** | All five match M2.10A |

## Hosted target preflight

Comprehensive preflight fingerprint: `5bae4aff63e1ab38d59421c0a758406307b8f2d23ed9751e63f724ffea835dfb`.

The target schema ledger is exactly `20260829000600`. Identity rows match the locked Account, Branch, and Machine. The compared M2.10A fixture domains remain at 3 counters, 3 People, 28 assignments, 30 lifecycles, 2 replacement events, 2 purchases, 2 receipts, 5 movements, 3 FIFO lots, and 2 incidents. No newer DEV evidence was detected in those domains. Graha has zero machines, counters, lifecycles, replacements, incidents, costs, movements, or stock.

## Backup and recovery

Supabase reports no physical backup entries and PITR is disabled. A verified logical recovery point was therefore established before apply:

- roles SHA-256 `0decd601faa70260a3a31e8ce63208cc4c1f99921bc6f3ed4faf1cd980da3a`;
- public schema SHA-256 `6810faf2c2d3c6b303e4fce6119811aab7bb23d8150fefe40d21d635d38cc44c`;
- public data SHA-256 `e96bceb76937810ab8f121254f7b6bc641641cc97ec9863bb12b005c31226782`.

The files are mode `0600` under the ignored private execution directory. Verification transactionally truncated and restored a disposable exact-schema database, asserted the locked identity and expected pre-import domain counts, and rolled back. Incorrect hosted results must stop the migration and use this controlled recovery procedure; immutable evidence must not be casually deleted.

## Controlled DEV authorization

`migrate-hosted-dev.mjs` is a separate, fail-closed entry point. Hosted mutation requires all of the following:

- mode `apply` plus explicit `--apply`;
- `--target-type DEV`;
- argument and linked project ref both exactly `sxitqjxljoqsnpepymrl`;
- explicit source snapshot and expected source fingerprints;
- explicit target preflight fingerprint;
- M2.10B manifest and verified recovery manifest;
- exact Account, Branch, Machine, Graha, schema-ledger, audit-actor, and execution identities;
- zero unresolved staging approvals.

The hosted SQL transport refuses destructive `DELETE`, `TRUNCATE`, `DROP`, and `ALTER` statements. M2.10A continues to permit mutation only in a disposable local container. Production and unknown targets remain non-executable.

## Planned disposition

| Disposition | Rows |
|---|---:|
| IMPORT | 476 |
| MERGE | 1 |
| SKIP_DUPLICATE | 2 |
| ARCHIVE_ONLY | 45 |
| APPROVED_EXCLUDE | 2 |
| MANUAL_REVIEW | 0 |
| **Total** | **526** |

Unexplained remainder: **0**. Planned domain writes are 179 counters, 47 verified closed lifecycle intervals, 161 purchases/lines, and 89 incidents. Ten missing People are created; 18 matching Inventory Items are reused. The one `SAME_EVENT_HIGH_CONFIDENCE` counter points to the existing target row. All 27 deterministic synthetic counter timestamps remain unchanged from M2.10A.

## Expected differences and archive evidence

The execution crosswalk and Expected Difference Register preserve all 526 source identities. They retain full source/candidate evidence for excluded counters, first-boundary replacements, five ambiguous replacements, and the inventory snapshot. Legacy `daily_clicks` remains derived and is not imported. Aggregate Machine Cost snapshots are not imported; Machine Cost and Reports remain projections of accepted source evidence.

## Validation before hosted apply

- migration unit tests: 29 passed;
- exact-schema migration integration: passed, including dry-run, idempotency, fresh reconstruction, and deliberate rollback failure;
- frontend suites: passed;
- Branch persistence regression: 8 passed;
- database clean reconstruction: passed;
- pgTAP: 38 files / 1,310 assertions passed;
- schema lint: no errors;
- changed-code ESLint: passed;
- JSON validation: passed;
- `git diff --check`: passed;
- production build: passed (existing bundle-size advisory only).

Machine Models gutters remain 24px desktop, 20px tablet, and 14px compact mobile, with a regression assertion for each breakpoint.

## Apply, reconciliation, and idempotency

Pending exact-SHA commit, push, and successful GitHub CI. The required order after that gate is hosted rollback-only dry-run, first explicit apply, reconciliation, Graha inspection, identical second apply using the new postflight fingerprint, and second reconciliation. Actual timestamps, mutation summaries, and postflight results will be appended without overwriting M2.10A artifacts.

## Staging limitations and Production gates

This is staging acceptance data, not Production truth. Complete hidden legacy catalog/Auth/private Storage inventory remains unavailable under the accepted five-table limitation. Production remains blocked on physical stock opname, an approved opening-stock manifest, freeze owner/window, final full legacy snapshot/fingerprint, Production backup/plan, reconciliation, rollback rehearsal, and user acceptance. No Production import, DNS/domain cutover, shared-hosting/PHP/MySQL migration, or Maintenance work is authorized.

## User acceptance checklist

- Review Tuparev Overview, Machines, Daily, Components, Inventory, Machine Cost, Errors, and Reports.
- Confirm historical counter chronology and the two approved exclusions.
- Confirm component history contains only verified intervals and no fabricated generic slot.
- Confirm acquisitions are draft historical evidence with no receiving/stock/FIFO effect.
- Confirm incident type/loss projections and the 2x/3x multiplier cases.
- Confirm Graha contains only legitimate Graha evidence.
- Record signed-in visual/data acceptance separately from HTTP smoke results.
