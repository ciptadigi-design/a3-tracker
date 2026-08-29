# M2.9 Legacy Supabase Data Migration Assessment

Assessment date: 2026-08-29 (Asia/Jakarta)<br>
Accepted new-application baseline: `0313c6debd6b9167984e07fa769f178253011f46`<br>
Legacy Supabase project: `wtslqxjwjqyjgcapfrrz`<br>
New DEV Supabase project: `sxitqjxljoqsnpepymrl`<br>
Assessment mode: **read-only**

> **M2.9B supersession (2026-08-29):** The user approved Account = Cipta Grafika, Branch = Tuparev, and physical Machine = existing `CG-TUP-A3-01` (Konica Minolta bizhub PRESS C1070/1070P). All assessed legacy operational evidence belongs to Tuparev; Graha receives zero legacy operational data. These identity blockers are closed. See `M2_9B_MIGRATION_DECISION_CLOSURE.md` for the current gate and machine-readable IDs.

## 1. Decision summary

M2.10 is **BLOCKED**, not because the observable legacy rows are unusable, but because two acceptance-critical facts are not yet provable:

1. The available legacy client credential can read the five tables used by the old application, but cannot inventory hidden tables/schemas, database constraints/indexes/policies, Auth users, or private Storage. The legacy project is not visible in the Supabase organization currently authenticated by the CLI.
2. The source has no Account, Branch, Machine, model, serial-number, or location key. Evidence strongly suggests that the single legacy tracker corresponds to the current Cipta Grafika / Tuparev / `CG-TUP-A3-01` C1070 chain, but Branch and physical-Machine ownership still require explicit business approval. No record may default to Tuparev merely because Graha is newer.

No legacy or new hosted data was changed. No database migration was created. No DEV import was attempted.

## 2. Recovery and evidence boundary

Repository recovery found a clean `develop` branch at the accepted M2.8 SHA, equal to `origin/develop`. Local `main` and `production-old/main` both remained at `7f35603fc9b1eeed7b85901ab77a6e121b57a005` before assessment changes.

Evidence used:

- The protected legacy source at `production-old/main`, inspected without checkout or modification.
- GET-only PostgREST reads against the legacy project using its existing client credential. Secrets were not copied into assessment artifacts.
- GET-only legacy Auth settings and Storage bucket-list endpoints.
- The current schema reconstructed from migrations and queried through the local PostgreSQL catalog.
- A read-only `pg_dump` of selected/current public DEV data and remote table statistics for collision analysis. The dump remained temporary and is not committed.
- Existing accepted lifecycle bootstrap and architecture documentation, treated as corroboration rather than independent legacy database metadata.

The legacy audit is a sequential application-level snapshot, not a transactionally consistent database backup. The captured row counts/fingerprints can change while the old application remains writable.

## 3. Legacy source and access status

The old application hardcodes project URL `wtslqxjwjqyjgcapfrrz` and directly calls PostgREST. Its application code uses exactly these five public table names:

| Table | Rows | Classification | Observed ID type | Business role |
|---|---:|---|---|---|
| `click_history` | 182 | HISTORY | UUID-shaped string | Cumulative counter readings |
| `part_replacements` | 70 | HISTORY | UUID-shaped string | Free-text component replacement/reset boundaries |
| `error_logs` | 91 | TRANSACTION / HISTORY | UUID-shaped string | Human/process/test-print and ambiguously named Machine Error records |
| `inventory_parts` | 22 | MASTER + DERIVED | number | Mutable item name/current balance pairs |
| `part_purchases` | 161 | TRANSACTION | number | Purchase rows whose old UI separately incremented mutable stock |
| **Total** | **526** |  |  |  |

All five responded successfully to read-only requests. The Auth settings endpoint responded, but the old application performs no Supabase Auth login and uses free-text actors. The available credential cannot list Auth users. The client-visible Storage bucket count is zero; private/hidden buckets cannot be ruled out without owner/admin access.

The old application also stores click price and visual preferences in browser `localStorage`; those are not shared database evidence and must not become migrated machine economics.

### 3.1 Metadata limitations

The public API root requires a secret credential for OpenAPI/catalog discovery. Therefore the following are **not proven** for the complete legacy database:

- total schema/table count beyond the five application-used tables;
- declared primary keys, foreign keys, indexes, defaults, checks, triggers, RLS policies, grants, or soft-delete mechanisms;
- Auth user count/provider identities/password portability;
- private Storage buckets/objects.

Within observed data, every `id` is non-null and unique. The old UI addresses mutations by `id`; this supports, but does not prove, an `id` primary key. No source payload contains a relational foreign-key field. The old UI hard-deletes rows and directly patches mutable records; no archive/status column was observed in these five tables.

### 3.2 Observed fields and nullability

- `click_history`: `id`, `date_str`, `date_for`, `operator`, `total_clicks`, `daily_clicks`, `created_at`; no null/blank values.
- `part_replacements`: `id`, `part_name`, `replaced_at_click`, `operator`, `created_at`; no null/blank values.
- `error_logs`: the 18 observed columns are `id`, `tgl`, `nomor_invoice`, `divisi`, `nama_konsumen`, `nama_produk`, `qty_kesalahan`, `kerugian_bahan`, `kerugian_jasa`, `jumlah_kerugian`, `kategori_kesalahan`, `jenis_kesalahan`, `deskripsi_kesalahan`, `penyebab`, `pencegahan_solusi`, `penyelesaian`, `pic`, `created_at`. Blank counts are: description 3, invoice 6, prevention 55, cause 61; other observed fields are populated.
- `inventory_parts`: `id`, `part_name`, `stock`; no null/blank values.
- `part_purchases`: `id`, `created_at`, `tgl_pembelian`, `part_name`, `qty`, `harga_satuan`, `total_harga`, `supplier`; one blank supplier, otherwise populated.

## 4. Legacy data-quality profile

### 4.1 Counters

- Operational-date range: 2026-03-04 through 2026-08-28.
- Cumulative value range: 1,269,356 through 1,440,211; total advance 170,855.
- 11 normalized free-text operator labels.
- 32 dates contain multiple readings; maximum is 4. These are valid candidates and must not be collapsed.
- No counter regressions, negative totals, negative stored daily values, duplicate IDs, or exact business-key duplicate candidates were observed.
- Stored `daily_clicks` disagrees with the immediately prior chronological reading for 56 of 181 comparable rows, including 36 same-day cases. It is derived/non-authoritative and will not migrate.
- `date_str` behaves like a client-local timestamp: it is 420–423 minutes ahead of server `created_at`, strongly corroborating Asia/Jakarta. However, 27 rows have a `date_for` different from the date portion of `date_str`, matching the old UI's “Yesterday” option. Those rows require an approved observed-time rule; combining a date and time silently would manufacture precision.

### 4.2 Replacements/components

- Range: 70 rows from 2026-03-04 through 2026-08-24; counters 1,269,523 through 1,435,866.
- 21 normalized component labels appear in replacement history and 5 normalized technician/operator labels.
- No negative counters, duplicate IDs, or exact business-key duplicate candidates were observed.
- Across replacement, inventory, and purchase data there are 27 normalized part labels. Twenty-two have exact canonical business-name candidates. Five require manual mapping:

| Legacy label | Why it is ambiguous |
|---|---|
| `Charging Corona` | No color/slot; cannot choose C/M/Y/K |
| `Drum Unit` | No color/slot; cannot choose C/M/Y/K |
| `Developing Unit` | No color/slot; cannot choose C/M/Y/K |
| `Fuser Unit` | Not semantically identical to current `Fuser Belt` without approval |
| `Other Part` | Generic evidence; must never map to `TEST_COMPONENT` |

Display-name similarity alone is insufficient. A slot-level override file must resolve each affected source ID.

### 4.3 Errors/waste

- 91 rows from 2026-03-02 through 2026-08-26.
- Types: 41 Human Error, 40 Print Test, 10 Machine Error.
- Categories: 63 Kesesuaian/Ketepatan, 13 Bahan, 9 Desain, 4 Prosedur/Proses, 2 Kualitas.
- 19 normalized PIC labels and one division label (`CG`). `CG` corroborates the Account, not a Branch.
- No negative quantity or loss values. Aggregate quantity is 432; material loss 272,511.72; service loss 167,502; stored assessed loss 458,588.72.
- Four rows have assessed loss different from material + service. Their exact ratios are representable by the new penalty multiplier but require approval: `0fec4411-8e2a-42e6-bc7f-bc11e8da1535` (2×), `f08320f8-c861-4e56-9df7-d5bfdcc52fff` (2×), `83b86ac9-448b-41aa-8a59-5bec107768a1` (2×), and `e3578b4c-c6aa-46df-b674-58095b110fc0` (3×).
- Two exact candidate duplicate pairs require business review; no row is approved for exclusion yet:
  - `aa35bc82-19e9-4fa9-b015-a93d5960bbf1` / `3436509c-f729-4f78-b9b7-33d0d41f6837`
  - `04fa396d-a878-4f0f-ae32-1874e92b3c87` / `76c2f97c-cae5-44a8-ac0c-29af462b5994`

“Machine Error” is an old operational classification, not proof of a technical fault code. In the current schema it defaults to `machine_operation` operational incident unless separate technical evidence is approved for archival treatment.

### 4.4 Inventory and purchases

- Inventory contains 22 unique normalized item names, no negative balances, no duplicate normalized names, and 11 total units.
- Purchases contain 161 rows dated 2025-06-08 through 2026-08-08, 22 normalized item names, 8 normalized supplier labels, 208 total ordered units, and IDR 371,029,998 stored acquisition value.
- Purchase totals equal `qty × harga_satuan`; no negative/zero quantities, negative prices, duplicate IDs, or exact business-key duplicate candidates were observed.
- Purchase dates precede some source creation timestamps, demonstrating historical/backfilled business dates.
- The old UI first inserted a purchase and then separately patched/inserted mutable stock. Either call could succeed alone. Purchase history is not receipt evidence, and replaying purchases must not create stock or FIFO layers.

## 5. Current schema inventory

The current reconstructed schema has 41 public base tables, 25 public projection views, RLS enabled on all 41 tables, and 130 `SECURITY DEFINER` functions. Migrations are authoritative; the latest accepted migration is `20260829000600_production_security_hardening.sql`.

### 5.1 Governance and identity

| Tables | Key migration contracts |
|---|---|
| `accounts`, `branches` | UUID keys; normalized Account/Branch codes; Branch belongs to Account; timezone validation; archive fields preserve state |
| `profiles`, `account_memberships`, `account_membership_branches` | Auth UUID remains identity; unique Account/user membership; role/status and explicit Branch scope |
| `platform_user_privileges` | UUID-bound `superuser`; never inferred from email/name/membership |
| `operational_people`, `operational_person_branches` | Operational PIC is separate from Auth; normalized Account name/code uniqueness; explicit Branch eligibility |
| `account_operational_permissions` | Account-scoped capability policy |
| `member_provisioning_requests`, `identity_change_events`, `settings_change_events` | Idempotency/audit evidence keyed by request identity |

### 5.2 Machine, counter, and component truth

| Tables | Key migration contracts |
|---|---|
| `manufacturers`, `machine_models`, `machines` | Canonical masters; Machine has Account + Branch + model; normalized Machine code uniqueness; optional serial collision key |
| `counter_types`, `counter_readings` | Append-only cumulative evidence; nonnegative value; chronological same-stream links; correction lifecycle; unique `(account_id, client_request_id)` |
| `components`, `machine_model_components` | Logical catalog and model-specific slot blueprint; normalized scoped codes; no global A3 slot count |
| `machine_component_assignments`, `machine_component_profile_exclusions` | Persistent Machine slot truth; unique normalized Machine slot; durable overrides/exclusions; request idempotency |
| `machine_component_lifecycles` | One open lifecycle per Machine/slot; UNKNOWN requires null installation counter/date; installation/removal snapshots protected |
| `component_replacement_events`, `component_profile_baseline_revisions` | Immutable lifecycle transition; exact counter/usage consistency; reason/condition/actor snapshots; idempotent request keys |

The accepted C1070 profile has 28 effective slots. Profile synchronization may configure slots but cannot fabricate lifecycle evidence.

### 5.3 Inventory, purchasing, receiving, and costs

| Tables | Key migration contracts |
|---|---|
| `inventory_items`, `inventory_locations` | Account item definitions and Branch-capable physical locations; canonical component-item uniqueness; no stored balance column |
| `inventory_movements` | Immutable signed ledger; explicit movement/reference type; one opening key per item/location; request/leg uniqueness |
| `inventory_suppliers`, `inventory_purchases`, `inventory_purchase_lines` | Purchase is acquisition evidence only; positive quantity/nonnegative price; normalized purchase and request uniqueness |
| `inventory_receipts`, `inventory_receipt_lines` | Immutable receipt evidence linked to physical location, PIC, purchase line, movement, and cost snapshot |
| `inventory_cost_lots`, `inventory_cost_allocations`, `inventory_cost_inputs` | FIFO evidence; unknown unit cost remains null; allocations are immutable/idempotent |
| `inventory_purchase_number_sequences` | Account-period numbering state, not historical business evidence to import |

### 5.4 Errors/economics/reports

| Tables | Key migration contracts |
|---|---|
| `operational_incidents`, `operational_incident_revisions` | Branch-scoped operational/human/test-print evidence; generated assessed loss; immutable content with resolve/void transitions |
| `machine_operating_costs`, `machine_selling_prices` | Immutable posted/voided source evidence with request keys; unknown cost/revenue is not zero |
| projection views | Machine Cost, FIFO, component health, inventory balances, and Reports are recomputed projections, never migration targets |

## 6. Source-to-target domain matrix

| Legacy source | New target | Class | Transformation / rule | Confidence | Risk | Reconciliation |
|---|---|---|---|---|---|---|
| application-wide implicit tenant | existing Account/Branch/Machine crosswalk | MANUAL-MAP | Approve exact owner before any row transforms | Low until approval | Tenant/Branch leakage | All rows resolve exactly once; none defaulted |
| `click_history` | `counter_readings` | TRANSFORM | Total only; deterministic local timestamp rule; snapshot actor; ignore `daily_clicks` | Medium | 27 date ambiguities; 3 DEV overlaps | Count/range/latest/fingerprint/by-Machine |
| `part_replacements` | lifecycle + replacement evidence | SPLIT / MANUAL-MAP | Normalize slot; build only provable lifecycle intervals; no stock effect | Low | Missing prior installation/reason/condition/event time | Count by slot, boundary counters, quarantine |
| `inventory_parts` | `inventory_items` + opening movement | SPLIT | Merge definitions; one approved physical-count opening at freeze | Medium | Mutable balance/unknown location/cost | Balance by item/location and physical count |
| `part_purchases` | supplier + purchase + line | TRANSFORM | Preserve acquisition row; no receipt/movement/FIFO | Medium | Historical status semantics; supplier aliases | Count, qty, acquisition totals, item/supplier |
| `error_logs` | `operational_incidents` | TRANSFORM / MANUAL-MAP | Enum normalization; preserve snapshots/loss; Machine Error remains operational by default | Medium | Duplicate candidates, 4 multipliers, Branch/PIC | Count/type/category/loss/exception register |
| hardcoded part list/lifetimes | catalog/profile corroboration | MANUAL-MAP | Never overwrite existing C1070 profile; never seed other models automatically | Low | UI defaults mistaken for manufacturer truth | Approved slot-by-slot diff |
| browser `clickPrice` and legacy UI reports | none | SKIP / DERIVED_ONLY | Local browser state and projections are not shared evidence | High | Mixing accounting methods | Recorded expected difference |
| legacy Auth (if any) | Auth/profiles/memberships | UNKNOWN / MANUAL-MAP | Inventory separately; historical actor snapshots do not imply login identity | Unknown | Account takeover/duplicate identity | Auth inventory and explicit provisioning register |
| client-visible Storage | none observed | UNKNOWN | Confirm with admin inventory before declaring NONE | Unknown | Missing attachments | Bucket/object counts and checksums |

The machine-readable counterpart is `scripts/migration/mapping.json`.

## 7. Account, Branch, and Machine mapping

### Account

Candidate: existing Account `CG` / Cipta Grafika. Evidence: all legacy error rows use division `CG`; current target Account code is `CG`; the old tracker is a Cipta Grafika repository. Treatment is `MERGE`, subject to owner approval.

### Branch

Candidate: Tuparev. Evidence: the accepted guarded legacy lifecycle bootstrap targeted `CG-TUP-A3-01` and Tuparev, and current overlapping counters live on that Machine. The actual source rows contain no Branch/location field, and `divisi=CG` is Account-level evidence only. Therefore this remains `MANUAL-MAP`. Graha receives **no** legacy row by default. Unapproved Branch ownership quarantines every operational row.

### Manufacturer/model/physical Machine

Candidate chain:

```text
Konica Minolta
→ bizhub PRESS C1070/1070P
→ CG-TUP-A3-01
```

Corroboration is strong: the app is a single “Konica Tracker”; the component/lifetime set matches the accepted C1070 profile; the accepted bootstrap reconstructed 28 slot states from legacy snapshot counter 1,437,911; and current DEV contains three exact counter values also present in legacy. Nevertheless, legacy has no Machine ID, code, model, manufacturer, or serial. Treatment is `MERGE` only after the business owner signs the physical identity crosswalk. Do not create a duplicate Machine.

## 8. Domain transformation rules

### 8.1 Counter history

Preserve all valid chronological readings, including multiple same-day entries. Use `total_clicks` as `reading_value`; never import `daily_clicks`. Map free-text operator to `operator_name_snapshot` and only link `operator_person_id` after an approved normalized identity crosswalk. Generate deterministic import request IDs.

For the 155 rows where `date_for` matches the local `date_str` date, interpret `date_str` as Asia/Jakarta after approval. The 27 “Yesterday” rows require one explicit policy: either business-approved observation times, or a documented conservative date-only convention supported by migration metadata. `created_at` remains ingestion evidence and must not be silently presented as the intended operational date.

### 8.2 Operational people/PIC

The old application never signs users in. Counter operator, replacement operator, and incident PIC are free text. Normalize Unicode, trim whitespace, case-fold, then resolve through a reviewed mapping held in secure migration inputs. Do not commit personal-name mappings. Repeated spellings map to one `operational_people` record; Auth linkage is optional and separate. Branch eligibility must exist before import.

### 8.3 Catalog/profile/assignments

Exact colored names can map to the current logical Component catalog only after code review. The five ambiguous groups above require source-row overrides. Repeated logical components remain separate by slot. The hardcoded 28-item list is corroboration, not proof of a model profile. Keep the accepted C1070 profile and 28 Machine assignments; do not overwrite it. Do not create profiles for Xerox or any other model from these rows.

### 8.4 Lifecycle/replacement history

`part_replacements` proves a named boundary at a counter and a source record timestamp. It does not prove a predecessor installation counter, reason, removal condition, inventory source, physical replacement time, or color slot for generic names.

A safe transformation can create a closed lifecycle interval only when two consecutive, approved events for the same slot prove its start/end counters. The first boundary per slot has unknown predecessor usage and cannot truthfully satisfy the current replacement-event invariant. The latest boundary may already be represented by the accepted open lifecycle bootstrap. Such source rows remain crosswalked as boundary/archive evidence rather than forcing fabricated replacement rows. A future M2.10 import path may need a dedicated, reviewed legacy-evidence mechanism; it must not weaken normal replacement invariants.

The current target has 28 open assignments, 30 lifecycle rows (the original 28 plus two legitimate next lifecycles), and 2 new replacement events at counter 1,438,992. Those two replacements are later than the legacy replacement maximum and have explicit current inventory evidence. They are `KEEP NEW`.

### 8.5 Inventory, purchase, receipt, FIFO, and cost

Use an opening-balance cutover, not historical ledger replay. At freeze, physically approve each legacy item/location balance, merge item definitions, and create one explicit opening movement per nonzero approved balance. The current legacy total of 11 units is only a planning snapshot. Location/Branch and unit are manual inputs. Unknown opening cost remains unknown.

Historical purchase rows may be retained as acquisition evidence, but the current purchase status model derives received state through receipt rows. M2.10 must approve either an import-specific historical-purchase representation or archive-only treatment; it must not create fake outstanding purchases, receipts, movements, or FIFO lots. Legacy contains no distinct receipt table/event. Purchase date is not receipt date.

### 8.6 Errors, Machine Cost, and Reports

Map Human Error → `human`, Print Test → `test_print`, and old Machine Error → `machine_operation` unless separately proven technical. Normalize categories to the five current enums. Preserve material/service values and approved multiplier so assessed loss reproduces legacy; do not recompute away the four intentional-looking multipliers.

Legacy Machine Cost and reports are UI-derived outputs. Migrate source counters, approved operational incidents, purchases, and opening stock evidence; then recompute current projections. Do not import KPI cards or the browser-local click price. Historical inventory consumption cost remains unavailable where no receipt/FIFO evidence exists.

### 8.7 Auth and Storage

Historical actor identity and future login identity are independent. Preserve current new Auth users and memberships. If full legacy Auth inventory later finds users, map them manually; do not copy passwords/hashes by assumption. Direct provisioning or credential reset is a separate controlled production action.

The observed Storage result is zero client-visible buckets. Admin credentials must confirm hidden/private buckets and object counts before M2.10 declares Storage `NONE`.

## 9. Collision analysis against current DEV

The read-only DEV snapshot contained:

| Domain | Current rows relevant to collision |
|---|---:|
| Accounts / Branches / Machines | 1 / 2 / 1 |
| Manufacturers / Models / Components / model-profile rows | 2 / 2 / 29 / 32 |
| Counter readings | 3 |
| Operational People | 3 |
| Machine assignments / lifecycles / replacements | 28 / 30 / 2 |
| Inventory items / locations / movements | 30 / 1 / 5 |
| Purchases / receipts / cost lots | 2 / 2 / 3 |
| Operational incidents | 2 |

Deterministic collision rules:

| Collision | Decision |
|---|---|
| Account `CG`, approved Tuparev Branch, canonical manufacturer/model | `MERGE` after identity approval |
| Legacy physical Machine candidate vs `CG-TUP-A3-01` | `MERGE`, never create duplicate, after owner/serial confirmation |
| Graha | `KEEP NEW`; import no legacy evidence without explicit source crosswalk |
| Existing 28 C1070 profile slots/assignments | `KEEP NEW`; map legacy names to them, never overwrite |
| Current two replacement/inventory chains | `KEEP NEW`; immutable legitimate evidence |
| Current Inventory items/Location/current ledger | `KEEP NEW`; merge definitions and add only approved cutover opening/delta treatment |
| Current People | normalized manual review; no name-only overwrite |
| Current incidents/purchases | `KEEP NEW`; crosswalk exact source IDs before importing legacy |

Three current counter values exactly overlap legacy values, but timestamps differ:

| Legacy source ID | Value | Legacy local `date_str` | Current DEV `observed_at` |
|---|---:|---|---|
| `54da82a6-b723-442c-880f-19215e1f35bb` | 1,437,283 | 2026-08-25 17:07:37 | 2026-08-26 11:15:00Z |
| `0fc458d7-1f51-4b73-8308-ca3dd4cd94b9` | 1,437,911 | 2026-08-26 21:19:59 | 2026-08-26 14:22:00Z |
| `82d3b425-7273-415d-8c49-338d92405962` | 1,438,992 | 2026-08-27 19:41:55 | 2026-08-27 15:08:00Z |

These are `MANUAL REVIEW`, not automatic duplicates. Value-only deduplication would destroy chronological evidence. Legacy then advances to 1,440,211 on 2026-08-28 while new DEV already has new operational evidence, so a final full-source freeze snapshot is mandatory.

## 10. ID and crosswalk strategy

Use **GENERATE NEW ID + CROSSWALK** for legacy event/master IDs, preferably deterministic UUIDv5 from a migration namespace plus `source_project/schema/table/id`. Use deterministic UUIDv5 request IDs per operation so retrying the same import cannot duplicate immutable evidence. Existing target masters use their current IDs and a `MERGE` crosswalk.

The crosswalk key is `(source_project_ref, source_schema, source_table, source_id, mapping_version)` and records target table/ID, decision, source row hash, approval actor/time, and exception reason. Never rely on names after execution begins. Keep crosswalk/expected-difference records in a migration-control staging schema or an approved audit artifact; adding a permanent target table would be an explicit forward migration in M2.10, not an M2.9 change.

## 11. Dependency-aware dry-run order

1. Freeze a source snapshot for the dry run; capture table row hashes/counts and the new target migration ledger.
2. Load approved Account/Branch/Machine/location/people/part/supplier mapping overrides.
3. Crosswalk existing Account and Branch; fail if any source row is unassigned.
4. Merge canonical manufacturers, Machine Models, Components, Inventory Items, suppliers, and the approved physical Machine.
5. Create/merge Operational People and Branch eligibility; do not create Auth membership automatically.
6. Reconcile the existing 28 C1070 profile/assignment slots; create no unrelated profile.
7. Transform counters in chronological order after resolving the three overlaps and 27 date ambiguities.
8. Build provable historical lifecycle intervals; quarantine first/ambiguous boundaries; merge into existing open lifecycle state without rewriting it.
9. Preserve historical purchases/lines using the approved non-receipt treatment; create no stock/FIFO evidence.
10. At the approved cutover watermark, create reconciled opening balances for physical inventory only.
11. Transform operational incidents and their PIC snapshots; quarantine duplicate/loss exceptions pending decisions.
12. Recompute Machine Cost/Reports projections and produce reconciliation/expected-difference manifests.
13. Inventory Auth and Storage separately; future login provisioning is not a prerequisite for historical actor snapshots.

## 12. Dry-run and migration manifest

`scripts/migration/legacy-audit.mjs` performs GET requests only and requires `LEGACY_SUPABASE_URL` and `LEGACY_SUPABASE_ANON_KEY`. It emits aggregate/anonymized JSON and SHA-256 row fingerprints; it never emits the key. Mutation is not implemented. `scripts/migration/mapping.json` declares `mode: dry-run` and `mutation_enabled: false`.

M2.10 should transform into a disposable target at the exact accepted migration ledger. Mutation must require an explicit `--apply`, a target-project allowlist, an expected source fingerprint, an expected pre-import target fingerprint, and a transaction/import run ID. Dry-run remains the default.

Required manifest shape:

```json
{
  "migration_version": "m2.10-vN",
  "source_project": "wtslqxjwjqyjgcapfrrz",
  "source_snapshot_started_at": "...",
  "source_snapshot_completed_at": "...",
  "source_table_counts": {},
  "source_row_fingerprints_sha256": {},
  "target_project": "...",
  "target_schema_version": "...",
  "target_migration_ledger": [],
  "mapping_version": "m2.9-v1",
  "execution_id": "uuid",
  "mode": "dry-run",
  "target_counts": {},
  "domain_totals": {},
  "crosswalk_count": 0,
  "expected_differences": [],
  "unresolved_mappings": [],
  "approved_by": null
}
```

## 13. Reconciliation plan

| Domain | Required checks |
|---|---|
| Machines | Source implicit-machine count vs exactly one approved crosswalk; manufacturer/model/Branch/serial review |
| Counters | Source/import/quarantine counts; SHA-256 fingerprint; min/max time/value; latest value; count by local date; all usage deltas; overlap decisions |
| People | Distinct normalized labels, mapped/unmapped counts, Branch eligibility, no duplicate canonical people |
| Components | Source label/ID to component+slot counts; ambiguous label queue; profile untouched checksum |
| Lifecycles/replacements | Boundary count by slot; created lifecycle intervals; archive-only first boundaries; current open-state equality; no fabricated stock links |
| Inventory | Approved physical balance by item/location; total units; unknown-cost quantity; opening movement count; current ledger preserved |
| Purchases | 161 count; 208 quantity; IDR 371,029,998 stored and generated line totals; no generated receipts/movements |
| Errors | 91 source dispositions; count by type/category; qty 432; all three loss totals; duplicate and multiplier decisions |
| Auth/Storage | Admin source counts and explicit disposition, without secrets |
| Derived outputs | Reports/Machine Cost recomputed from target evidence; definition differences documented |

Row count alone never passes a domain. Every source row must be `IMPORTED`, `MERGED`, `EXPECTED_DIFFERENCE`, or `UNRESOLVED`; unexplained loss is a failed migration.

## 14. Expected Difference Register

Known register entries at M2.9:

| Source | Source IDs/rule | Reason | Proposed treatment | Approval |
|---|---|---|---|---|
| `error_logs` | two UUID pairs listed in §4.3 | DUPLICATE candidate | MANUAL REVIEW; import neither/one/both only after evidence review | Pending |
| `error_logs` | four UUIDs listed in §4.3 | transformed assessed loss | Preserve exact approved 2×/3× multiplier | Pending |
| `click_history` | three UUIDs listed in §9 | current DEV overlap | MANUAL REVIEW / MERGE only with explicit record match | Pending |
| `click_history` | 27-row rule | timestamp/date ambiguity | MANUAL REVIEW rule; per-row disposition emitted by dry run | Pending |
| `part_replacements` | first boundary per approved slot | missing predecessor lifecycle evidence | ARCHIVE-ONLY or approved legacy-evidence mechanism; never fabricate usage | Pending |
| all part tables | generic five labels in §4.2 | UNKNOWN_MAPPING | Per-source-ID manual component/slot map | Pending |
| `part_purchases` | all 161 | no receipt evidence | Purchase/archive evidence only; no receipt/stock/FIFO | Pending |
| `inventory_parts` | current balance at final freeze | DERIVED_ONLY mutable state | Replace with approved cutover physical opening balance | Pending |
| legacy reports/click price | all derived/local output | DERIVED_ONLY / NO_TARGET_DOMAIN | SKIP and recompute | Approved in principle by current architecture; final sign-off pending |

M2.10 must expand rule entries into source-ID-level rows before applying anything. No source row is currently approved for silent exclusion.

## 15. Cutover, delta, and rollback model

### Cutover

1. Phase A: full historical dry run into a disposable target and produce manifests.
2. Phase B: business reconciliation and mapping approval.
3. Phase C: isolated DEV staging import after protecting current DEV evidence.
4. Phase D: signed user acceptance of counts, mappings, and differences.
5. Phase E: production preflight, backup, full final import.
6. Phase F: freeze the legacy application, take a final complete snapshot, rerun mapping from that snapshot, and record the last legacy counter/time.

The legacy UI permits edit/delete without an immutable audit trail. A timestamp-only delta cannot detect all edits or deletions. Keep a full ID→row-hash snapshot from every dry run and, at final cutover, require a write freeze and a fresh full snapshot. If a freeze is impossible, M2.10 is no-go.

### Rollback

- Legacy remains unchanged and authoritative until signed cutover.
- Create and verify a target backup immediately before import.
- Execute each import run transactionally where possible; abort before commit on any reconciliation failure.
- Never “rollback” committed immutable history by deleting selected ledger/lifecycle rows casually. Before production acceptance, restore the entire pre-import target snapshot in an isolated recovery operation. After accepted use resumes, use a separately approved forward correction/reversal strategy.
- Preserve the manifest, crosswalk, source snapshot hashes, target preflight hashes, and expected differences independently of the target database.

## 16. Security, privacy, and portability

No database password, service-role key, JWT, access token, Auth secret, or personal password belongs in Git. Personal-name mapping files and raw customer/error exports must live in approved encrypted migration storage, not repository fixtures. Tooling reads credentials from environment variables and reports only aggregates or opaque source IDs.

The mapping is expressed as business entities, invariants, transformations, and reconciliation—not as a blind PostgreSQL table copy. A future PostgreSQL/Supabase → MySQL/PHP move would be a separate migration and must consume the same logical manifest/crosswalk model. M2.9 does not choose or start that hosting migration.

## 17. Open blockers and M2.10 prerequisites

Required before M2.10 can be GO:

1. Legacy project owner/admin read access sufficient for schema catalog, declared constraints/indexes/RLS/grants, complete table/schema counts, Auth inventory, and private Storage inventory.
2. Signed Account/Branch/physical-Machine crosswalk, including confirmation that all source evidence belongs to Tuparev/`CG-TUP-A3-01` or an explicit per-row exception list.
3. Decision for the 27 counter business-date ambiguities and three current-DEV overlaps.
4. Secure canonical mapping for actor/PIC labels and the five ambiguous part groups.
5. Approved legacy lifecycle strategy for first boundaries, generic slots, event timestamps, replacement reason/condition, and attachment to the already bootstrapped/current lifecycle chain.
6. Approved historical-purchase representation that cannot imply receipt/outstanding stock, plus physical inventory count/location/unit/opening-cost decisions.
7. Decisions for two incident duplicate pairs and four assessed-loss multipliers.
8. A source write-freeze owner/window and full-snapshot/delta protocol.
9. A disposable exact-schema target, verified target backup/restore procedure, target collision snapshot, and signed reconciliation thresholds.

M2.9B closes item 2. Item 1 remains an administrative limitation; it does not invalidate the five proven application tables, but requires explicit risk acceptance before a conditional M2.10. Current operational/manual decisions are tracked by source ID in the M2.9B register.

Shared-hosting/PHP/MySQL migration was not started. Maintenance was not started.
