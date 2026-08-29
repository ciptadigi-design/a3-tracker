# M2.9C Approval Gates and Decision Packet

Date: 2026-08-29. Baseline: `dda53d3933916611fe1e4884326496a66b43f9f3`. Identity is final: Cipta Grafika `357e420a-c9ea-4404-9da4-f254c5dce5ef`; Tuparev `76d3c7ab-55c3-40f7-b133-0ef54a448893`; existing physical Machine `CG-TUP-A3-01` `b4ca07ee-c588-404d-abcf-b6a029e68776`; Graha denylisted.

Gate categories: A `M2_10A_TRANSFORMATION_BLOCKER`; B `M2_10A_EXCLUDABLE_MANUAL_ROW`; C `HOSTED_STAGING_APPLY_GATE`; D `PRODUCTION_CUTOVER_GATE`; E `ADMINISTRATIVE_RISK_ACCEPTANCE`.

| Decision | Rows | Category | M2.10A | Hosted staging | Production | Status |
|---|---:|---|---|---|---|---|
| MIG-ADMIN-001 | 0 | E | Does not block | Blocks unless full inventory or accepted limitation | Blocks unless satisfied earlier | PENDING USER ACCEPTANCE |
| MIG-COUNTER-001 | 2 | B | Exclude rows | Approval required to include | Same | PENDING |
| MIG-PIC-001 | 2 | C | Dry-run snapshot/prospective mapping | Approval required | Same | PARTIAL: `oan` resolved; `sri bulan` pending |
| MIG-PIC-002 | 18 | C | Prospective deterministic Person IDs | Creation-list approval required | Same | PENDING |
| MIG-COMPONENT-001 | 2 | B | Exclude rows | Approval required to include | Same | PENDING |
| MIG-COMPONENT-002 | 2 | B | Exclude rows | Approval required to include | Same | PENDING |
| MIG-COMPONENT-003 | 1 | B | Exclude row | Approval required to include | Same | PENDING |
| MIG-COMPONENT-004 | 2 | B | Purchase applies component-null; stock excluded | Approval required for stock inclusion | Physical count required | PARTIAL RESOLVED |
| MIG-LIFECYCLE-001 | 18 | B | Archive in manifest/crosswalk | Does not block | Does not block | RESOLVED BY POLICY |
| MIG-PURCHASE-001 | 161 | C | Disposable `draft` plan with explicit legacy/receipt-unknown notes | Representation approval/schema decision required | Same | M2.10A RESOLVED; HOSTED PENDING |
| MIG-STOCK-001 | 22 | D | Exclude or marked fixture only | No real opening movement | Physical opname/approval required | PENDING PRODUCTION |
| MIG-FREEZE-001 | 0 | D | Mechanism only | Does not block | Owner/window/final snapshot required | PENDING PRODUCTION |

No category-A decision remains.

## Exact decision packets

### MIG-ADMIN-001

- Domain/rows: administrative inventory; no known source row.
- IDs: none.
- Evidence: all legacy UI REST calls resolve exclusively to the five proven tables; LEGACY is absent from the authenticated CLI organization.
- Recommended: allow M2.10A; before hosted apply, obtain full admin inventory or explicitly accept five-table scope.
- Alternative/risk: wait for admin access; accepting scope risks undiscovered hidden non-UI data, while waiting delays migration proof.

### MIG-COUNTER-001

- IDs: `click_history:54da82a6-b723-442c-880f-19215e1f35bb`, `click_history:82d3b425-7273-415d-8c49-338d92405962`.
- Evidence/recommendation: `54da…` value 1,437,283 at 2026-08-25 17:07:37 Asia/Jakarta versus target `44086d7c-…` at 2026-08-26 18:15 local (25h07m); legacy neighbors 1,436,493 then 1,437,911. `82d3…` value 1,438,992 at 2026-08-27 19:41:55 versus target `d222f09e-…` at 22:08 local (2h26m); neighbors 1,437,911 then 1,440,211. Exact values and monotonic positions suggest re-entry, but timestamps do not prove the same observation. Recommend `EXCLUDE_PENDING_MANUAL_APPROVAL` for both.
- Alternative/risk: IMPORT_DISTINCT preserves possible repeated readings but creates zero-usage plateaus; SKIP_DUPLICATE risks losing distinct evidence.

### MIG-PIC-001

- IDs: `part_replacements:3ad7c893-4f60-4218-ab96-fd809d073b4f`, `error_logs:66d03606-d75d-4f79-bbeb-119b9bd4b290`.
- Evidence/recommendation: `oan` occurs once as a replacement actor and is a one-character truncation of `ojan`, explicitly identified as Akmal elsewhere; recommend MAP_EXISTING → Akmal Fauzan. `sri bulan` occurs once near four `Bulan` events but does not prove identical identity; keep MANUAL_REVIEW.
- Alternative/risk: null Person link preserves the exact snapshot but weakens person reporting; an incorrect link misattributes history.

### MIG-PIC-002

- IDs: `error_logs:918406fb-d7b2-4a86-9b6b-130dbc788486`, `e93f8e1a-a581-4fc9-8bfb-9d331cd8c7b4`, `f0ca11d2-6f0d-46b3-baec-b1726590eec4`, `39a9355a-ad87-4258-ab12-ec9bff14362c`, `d466dc1c-5c0b-4588-a5c2-fb0427d643ea`, `050f052b-31b1-479d-a171-93805adabcab`, `c3d1e0c2-2515-4059-93fb-128d35f9dd2f`, `f08320f8-c861-4e56-9df7-d5bfdcc52fff`, `a64f13bc-68c8-4c17-8385-4fb821978776`, `bdda8c62-5479-44f8-8e31-bfa762b8f313`, `81e42010-38a2-432b-b34a-9a3a577be4f4`, `23fa4d7a-1a24-470b-90f2-2b8a91deeb23`, `3b9a7a9f-c4b9-4eef-9b21-da378aca5bbc`, `c2504a3f-5f6d-4ec4-a78d-9bce64bf60f9`, `382c5f65-a18b-4e63-abf3-4d8a70558954`, `c900895c-2c80-456a-acf3-fdbc11ca6b8f`, `7a3c78c1-f925-4d56-917f-40f375a80278`, `2cbba87e-a31d-49d3-8d81-dcf391efe4fd`.
- Evidence/recommendation: all are person-like PIC entries attached to real incidents, not placeholders/system text. Proposed canonical display names: Epri, Bigel, Bulan, Daniel, Dhea, Lydia, Marsya, Nabilah, Pinkan, Ramdani. Generate prospective UUIDv5 IDs in dry-run; create no Auth identity.
- Alternative/risk: archive snapshots only; avoids excess masters but loses person-level reporting.

### MIG-COMPONENT-001

- IDs: `part_replacements:e8e26ec3-c0e3-4f37-8b28-fdb5ba847be6`, `part_replacements:d959e9d2-08f6-4db2-b068-5cdcaec3b2d3`.
- Evidence: Charging Corona at counters 1,349,569 (2026-05-26, Ojan) and 1,377,914 (2026-06-24, Ojan); acquisition prices IDR 950,000–1,400,000; candidates C/M/Y/K. Price and chronology do not identify color.
- Recommended: EXCLUDE_PENDING_MANUAL_APPROVAL. Alternative MAP_SLOT risks corrupting one of four lifecycles.

### MIG-COMPONENT-002

- IDs: `part_replacements:2fb16092-4494-4682-a365-fb20ae97e88a`, `part_replacements:41342cc0-fc25-4d14-8bf5-b6b8c5cb21e4`.
- Evidence: Drum Unit at counters 1,353,291 (2026-06-01, Ojan) and 1,372,545 (2026-06-20, Angga); acquisition prices IDR 3,600,000–3,800,000; candidates C/M/Y/K. No note identifies color.
- Recommended: EXCLUDE_PENDING_MANUAL_APPROVAL. Alternative MAP_SLOT risks false lifecycle boundaries.

### MIG-COMPONENT-003

- ID: `part_replacements:73543d0f-99e5-46b3-a02a-4c84967f6194`.
- Evidence: Fuser Unit, counter 1,295,204, 2026-04-02, Angga; no source cost; sole structural candidate FUSER_BELT, but no semantic proof.
- Recommended: EXCLUDE_PENDING_MANUAL_APPROVAL; alternative MAP_SLOT FUSER_BELT has medium confidence.

### MIG-COMPONENT-004

- IDs: `part_purchases:301`, `inventory_parts:47`.
- Evidence: Other Part purchase is one unit at IDR 550,000 on 2026-08-05; snapshot shows one unit. Current `inventory_items.component_id` is nullable.
- Recommended: import acquisition through a deterministic historical Inventory Item with `component_id=NULL`; exclude stock until physical count. Never map `TEST_COMPONENT` or create a Machine slot.
- Alternative/risk: archive purchase only loses in-database acquisition reporting; arbitrary component mapping corrupts component economics.

### MIG-LIFECYCLE-001

- IDs: `0cf0631d-7a44-49f0-921e-a5f4c9336020`, `11f68a6a-eb3d-41b2-ad6b-753d2d12ed9a`, `2c630ac4-584f-43a1-914f-b703d533aabf`, `2d4f235b-58f8-486a-9a70-b4b06d85ca00`, `3b244cbc-9f47-48d0-9250-f36210c49d01`, `3ce63b01-62da-4af3-aa59-808c9b673e1a`, `4dacb3a1-339f-47b4-b740-c0da0973953d`, `59698ddf-5610-40f6-92e0-b56b8d1f24c2`, `9f634de8-3403-4ec5-9a96-be88d8a6830a`, `a862b9e3-2457-43e3-8491-a86d6777e288`, `a8aed9bf-f3d5-4dbc-80ce-667b13902bab`, `b276f26b-394a-4657-a3ee-ef51a6271efd`, `b5eb3f2a-692a-4ab3-920b-dc6d4e437950`, `b6b645e7-977b-4935-a16b-f8b83c86115b`, `b75e74e2-a3aa-4c6d-81c2-f1b018119d3d`, `cc867daa-e02a-439a-a8ac-afabc0a7fb55`, `d3c5d5af-3688-4916-9fda-e3ad5c867e74`, `ef3762c0-979c-4c38-8c1c-da0ec76c4ef6`.
- Evidence/recommendation: first boundary per exact slot proves no predecessor start. Preserve as ARCHIVE_ONLY in manifest/crosswalk; no new schema or fake lifecycle.
- Alternative/risk: a dedicated evidence domain could be designed later; forcing domain rows fabricates history.

### MIG-PURCHASE-001

- IDs: `148`, `149`, `150`, `151`, `152`, `153`, `154`, `155`, `156`, `157`, `158`, `159`, `160`, `161`, `162`, `163`, `164`, `165`, `166`, `167`, `168`, `169`, `170`, `171`, `172`, `173`, `174`, `175`, `176`, `177`, `178`, `179`, `180`, `181`, `182`, `183`, `184`, `185`, `186`, `187`, `188`, `189`, `190`, `191`, `192`, `193`, `194`, `195`, `196`, `197`, `198`, `199`, `200`, `201`, `202`, `203`, `204`, `205`, `206`, `207`, `208`, `209`, `210`, `211`, `212`, `213`, `214`, `215`, `216`, `217`, `218`, `219`, `220`, `221`, `222`, `223`, `224`, `225`, `226`, `227`, `228`, `229`, `230`, `231`, `232`, `233`, `234`, `235`, `236`, `237`, `238`, `239`, `240`, `241`, `242`, `243`, `244`, `245`, `246`, `247`, `248`, `249`, `250`, `251`, `252`, `253`, `254`, `255`, `256`, `257`, `258`, `259`, `260`, `261`, `262`, `263`, `264`, `265`, `266`, `267`, `268`, `269`, `270`, `271`, `272`, `273`, `274`, `275`, `276`, `277`, `278`, `279`, `280`, `281`, `282`, `283`, `284`, `285`, `286`, `287`, `288`, `289`, `290`, `291`, `293`, `294`, `295`, `296`, `297`, `298`, `299`, `300`, `301`, `302`, `303`, `304`, `305`, `306`, `307`, `308`, `309`.
- Evidence/recommendation: 161 acquisition rows, 208 units, IDR 371,029,998; no status or receipt fields. In disposable M2.10A use `draft` plus `LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED; source_id=…`; generate zero receipts/movements/FIFO. Hosted apply needs explicit approval of that representation or a later narrow historical-status schema decision.
- Alternative/risk: archive-only loses purchase reporting; `received` invents receipt and is forbidden.

### MIG-STOCK-001

- IDs: `inventory_parts:28`, `29`, `30`, `31`, `32`, `33`, `34`, `35`, `36`, `37`, `38`, `39`, `40`, `41`, `42`, `43`, `44`, `45`, `46`, `47`, `48`, `49`.
- Evidence/recommendation: mutable snapshot total 11; exclude from M2.10A apply or use explicitly non-production fixtures. Production requires physical count, approved quantity/location/cost/reviewer/timestamp.
- Alternative/risk: replaying 11 creates unverified stock; excluding from production loses opening inventory.

### MIG-FREEZE-001

- IDs: none.
- Evidence/recommendation: legacy edit/delete means timestamp delta is insufficient. Production requires named owner/window, write disable, complete final snapshot and fingerprints.
- Alternative/risk: no freeze can miss edits/deletes; it does not affect disposable engine proof.

## Locked cross-domain rules

- Date-only counters: deterministic timestamp uses `date_for` plus source `date_str` local time; tie/conflict resolution advances synthetic rows by deterministic 1 ms in `(date_for,total_clicks,date_str,created_at,id)` order. Mark `MIGRATION_SYNTHETIC_TIME`; never claim source observation precision.
- Lifecycle Levels A–D remain locked; UNKNOWN remains UNKNOWN; first boundary never invents predecessor start.
- Incident duplicates `3436509c-…` and `76c2f97c-…` are `SKIP_DUPLICATE_HIGH_CONFIDENCE` and remain in expected differences.
- Loss anomalies preserve source bases and 2×/3× multiplier; never multiply the stored total twice.
- Human Error → `human`; Print Test → `test_print`; Machine Error → `machine_operation`; no Maintenance.
- Archive-only evidence lives in the migration manifest/crosswalk when no truthful target domain row exists.
