# M2.9B Migration Decision Closure

Date: 2026-08-29 (Asia/Jakarta)<br>
Accepted baseline: `8773d6e7b0514aed6a97cff32d53318d35691324`<br>
Mode: read-only source/target audit; **M2.10 was not executed**

## Gate

**M2.10 BLOCKED.** Identity is fully locked and the 526-row register is complete, but 30 operational rows still require explicit decisions/physical evidence. Full legacy owner/admin catalog access is unavailable and is a separate bounded administrative limitation.

## Locked identity and scope

| Entity | Existing target ID | Decision |
|---|---|---|
| Cipta Grafika Account | `357e420a-c9ea-4404-9da4-f254c5dce5ef` | APPROVED MERGE |
| Tuparev Branch | `76d3c7ab-55c3-40f7-b133-0ef54a448893` | APPROVED MERGE; all five tables |
| `CG-TUP-A3-01` Machine | `b4ca07ee-c588-404d-abcf-b6a029e68776` | APPROVED MERGE into same physical Machine |
| Konica Minolta | `50000000-0000-0000-0000-000000000001` | retain existing |
| bizhub PRESS C1070/1070P | `51000000-0000-0000-0000-000000000001` | retain existing |
| Graha | `9f753339-0d54-42c9-9bb6-afe2461803f8` | DENYLISTED; zero legacy operational evidence |

No name-only execution matching is allowed. The only active Tuparev location is `CG Digital Print` (`b6296488-5479-4dd0-9463-091891b4cbe4`), so it is the deterministic candidate, subject to physical stock approval.

## Administrative inventory

The authenticated Supabase organization exposes NEW DEV but not LEGACY. The application client proves only the five public tables. Complete database catalog, non-public schemas, views, functions, sequences, policies, extensions, migration history, Auth users, and private Storage remain `UNVERIFIED_ADMIN_ACCESS_REQUIRED`. The Auth settings endpoint is reachable; the legacy UI performs no Supabase Auth login. Client-visible Storage buckets: zero. This limitation does not by itself disprove the five authoritative tables used by every legacy UI read/write path; user risk acceptance is still required before a conditional migration.

## Snapshot fingerprints

Audit time: `2026-08-29T13:45:10.225Z`. SHA-256 is over canonical JSON objects and rows sorted by source ID.

| Table | Rows | Fingerprint |
|---|---:|---|
| `click_history` | 182 | `c1e69897a12caf54eb61064f30a799be7fd41e960a3909aff3d8969834393ee5` |
| `part_replacements` | 70 | `2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e` |
| `error_logs` | 91 | `65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b` |
| `inventory_parts` | 22 | `ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632` |
| `part_purchases` | 161 | `6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306` |

M2.10 APPLY must refuse a mismatch unless a fresh reviewed manifest is intentionally generated.

## Counters and overlap

`total_clicks` is authoritative cumulative evidence. All 182 `daily_clicks` fields are `SKIP_DERIVED`. Multiple readings per day and repeated cumulative values remain valid.

The legacy UI proves that `date_for` is the selected operational date and `date_str` is entry time. Thus 155 rows are `RESOLVED_DETERMINISTICALLY`; all 27 “Yesterday” rows are `RESOLVED_DATE_ONLY`. If the required target timestamp is synthesized, use `date_for` plus the `date_str` local time in Asia/Jakarta, mark it `MIGRATION_SYNTHETIC_TIME`, and retain both raw fields. It must never be represented as source-recorded observation time.

Overlap result: 179 `DISTINCT_EVENT`; legacy `0fc458d7-…` is `SAME_EVENT_HIGH_CONFIDENCE` and `MERGE` (2m01s from target); legacy `54da82a6-…` and `82d3b425-…` are `POSSIBLE_DUPLICATE` / `MANUAL_REVIEW` (about 25h07m and 2h26m). No value-only or date-only dedupe is permitted. Existing DEV rows and their previous-reading chain remain untouched; M2.10 must rebuild the effective chronological link plan in a disposable target.

## People

There are 26 unique normalized actor/PIC values across counters, replacements, and incidents. Three map to existing Tuparev People; clear additional historical people are a bounded `CREATE_PERSON` list; composite `Akmal - Dhea` stays text-only; `oan` and `sri bulan` need review. Source strings remain snapshots and no Auth user is created. See `scripts/migration/pic-mapping.json`.

## Components and lifecycles

Exact colored/specific labels map to existing component, profile slot, and Machine Component assignment IDs. Slot identity is mandatory; a repeated logical component in multiple slots is never deduped by component ID/name. `Other Part` can never map to `TEST_COMPONENT`.

The five ambiguous labels affect 32 rows: Charging Corona 13, Drum Unit 13, Developing Unit 3, Fuser Unit 1, Other Part 2. Generic purchases may use noncanonical, component-null historical Inventory Items so acquisition value is preserved without inventing a color/slot. Five ambiguous replacement rows and the unidentified Other Part purchase remain manual. The full per-row result is in `component-mapping.json`.

Lifecycle levels are locked: A verified interval, B partial boundary, C event only, D unmappable. The first replacement per exact slot is archive/boundary evidence only; it does not prove predecessor installation. Consecutive verified boundaries may create truthful closed intervals. UNKNOWN stays UNKNOWN. Existing 28 assignments, 30 lifecycles, and two newer replacement/inventory chains are preserved. Historical rows merge before/around them; no current lifecycle is deleted, reset, or rewritten.

## Purchases, inventory, incidents

All 161 legacy purchase rows are acquisition evidence (208 units; IDR 371,029,998), never receipt, movement, stock, or FIFO evidence. Source has no status column. Current statuses have no exact historical equivalent: default recommendation is a dedicated non-operational historical representation; `received` is forbidden. If forced into the current enum, `draft` plus explicit legacy/receipt-unknown notes is least factually assertive but can misstate workflow and requires approval.

The 11-unit legacy inventory snapshot is not an opening balance. The opening worksheet fields are: canonical item, legacy displayed stock, physical cutover count, approved opening quantity, cost evidence, location, reviewer, approval status. Physical counts/approvals are intentionally blank. Opening stock comes only from physical stock opname at cutover.

Both incident pairs are high-confidence duplicate writes: every business field is identical and the second writes occurred 0.309s and 0.129s later. Skip only `3436509c-…` and `76c2f97c-…`; retain the earlier rows. The four assessed-loss anomalies are exact source-UI outputs: `(material + service) × 2` for three and `× 3` for one, corroborated by punishment text. Import base fields plus multiplier; do not multiply the stored total again. Human Error → `human`, Print Test → `test_print`, Machine Error → `machine_operation`.

## Row accounting

526 = 475 IMPORT + 1 MERGE + 2 SKIP_DUPLICATE + 0 row-level SKIP_DERIVED + 18 ARCHIVE_ONLY + 30 MANUAL_REVIEW. Field-level `daily_clicks` SKIP_DERIVED count is 182. Unexplained remainder: zero. Exact IDs and reasons are in `source-row-dispositions.json`.

## Target preflight and M2.10 contract

Target fingerprint domains: identity rows; effective counter rows and links; model/profile/assignments; lifecycles/replacements; inventory items/locations/movements/cost lots; purchases/lines/receipts; incidents; and migration ledger. Canonicalize selected stable business fields, sort by primary ID, and fingerprint each domain separately. Dry-run records expected hashes; APPLY rereads in one preflight and aborts on any mismatch.

CLI contract: default `dry-run`; modes `audit`, `plan`, `dry-run`, `apply`, `reconcile`. `apply` additionally requires `--apply`, explicit disposable/allowlisted target, manifest path, expected source and target fingerprints, Account/Branch/Machine UUIDs, execution/request UUID, and confirmation that unresolved approvals are zero. Production is never on a default allowlist. A transaction fails closed on fingerprint, ID, denylist, reconciliation, or disposition remainder mismatch.

First writes must target a disposable/local database reconstructed from the exact migration ledger. Prove deterministic UUIDv5 IDs/request IDs, retry idempotency, reconciliation, failure rollback, and full restore before hosted DEV is considered. No development freeze is imposed now.

Final cutover requires named owner, freeze start, legacy write-disable confirmation, final full snapshot time, migration start, reconciliation completion, production acceptance, and archival/unfreeze decision. Because legacy allows edits/deletes, use write freeze → fresh complete snapshot → fingerprint → import → reconcile → accept; timestamp delta alone is forbidden.

No Supabase migration or hosted DEV mutation was made. Shared hosting/PHP/MySQL, Production cutover, and Maintenance were not started.
