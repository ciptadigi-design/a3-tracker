# M2.10A Disposable Migration Engine & Full Dry Run

## Outcome

M2.10A is proven against a local Supabase PostgreSQL 17 database reconstructed from all 47 repository migrations through `20260829000600`. The engine planned all 526 application-visible legacy rows, validated the plan in a rollback transaction, applied it to the disposable target, reconciled it, retried it without duplicates, reproduced the same result after a fresh database rebuild, and rolled back a deliberate mid-transaction failure.

No hosted Supabase URL is accepted as an apply transport. No hosted DEV or Production data/schema was changed.

## Architecture

- `migrate.mjs` is the CLI boundary. Its default mode is `dry-run`; mutation requires `apply --apply`.
- `lib/m2-10a-engine.mjs` verifies source and target fingerprints, builds deterministic transformations/crosswalks, emits one PostgreSQL transaction, and reconciles real target tables.
- `prepare-disposable.mjs --reset` runs the normal local Supabase ledger and loads controlled fixtures. The baseline contains the locked Cipta Grafika/Tuparev/CG-TUP-A3-01 identity, effective C1070 profile, 28 deterministic Machine Component assignments, 30 accepted target lifecycles, two accepted newer replacement/inventory fixture chains, and three known target counters.
- `manifests/m2-10a.json` is the explicit eligibility/exclusion contract. `m2-10a-source-fingerprints.json` is the explicit source snapshot contract.
- `reconciliation/m2-10a/` contains the 526-row crosswalk, execution manifest, and machine-/human-readable reconciliation.

The source reader performs GET requests only. A captured source snapshot is written outside the repository with mode `0600`; credentials are never printed or stored in the snapshot.

## CLI

```sh
# Safe default: a transaction that always rolls back
node scripts/migration/migrate.mjs \
  --source-snapshot /secure/path/legacy-snapshot.json \
  --manifest scripts/migration/manifests/m2-10a.json \
  --expected-source-fingerprints scripts/migration/manifests/m2-10a-source-fingerprints.json \
  --target-type DISPOSABLE \
  --target-container supabase_db_konica-tracker-next \
  --account-id 357e420a-c9ea-4404-9da4-f254c5dce5ef \
  --branch-id 76d3c7ab-55c3-40f7-b133-0ef54a448893 \
  --machine-id b4ca07ee-c588-404d-abcf-b6a029e68776 \
  --execution-id 5f4756d2-cefd-5b5c-bd03-d27ae9db8a9d \
  --expected-target-fingerprint EXPECTED_SHA256 \
  --output-dir /tmp/m2-10a-dry-run

# Mutation is enabled only for the local disposable Docker target
node scripts/migration/migrate.mjs apply --apply ...same explicit inputs...
```

Modes are `audit`, `plan`, `dry-run`, `apply`, and `reconcile`. `audit` and `plan` do not connect to a target. Dry-run sends the complete SQL to PostgreSQL inside `BEGIN`/`ROLLBACK`, so FK, check, enum, trigger, identity, collision, and schema compatibility are validated without persisting rows. Apply uses one `BEGIN`/`COMMIT` transaction and `ON_ERROR_STOP`; any failure rolls back the complete migration.

Apply fails closed unless all of these are present and exact:

- explicit `--apply`/`apply` mode;
- target type `DISPOSABLE` and a local Supabase database-container transport;
- explicit manifest, manual exclusion set, source fingerprints, and target fingerprint;
- exact Account, Branch, Machine, Graha denylist, and migration-ledger identities;
- execution UUID and output directory.

`DEV`, `PRODUCTION`, unknown targets, hosted project refs, and non-container transports are rejected. The M2.10A code has no hosted apply client.

## Deterministic identity and provenance

New historical records use UUIDv5 derived from one migration namespace plus record kind, source project, schema, table, and source ID. Request IDs and row IDs use separate names. Existing Account, Branch, Machine, Component, Model Profile, Machine Component, Person, and counter-merge IDs are retained.

The crosswalk records source project/schema/table/ID, target entity/ID, mapping rule, confidence, disposition, and reason. It also records every skipped, archive-only, and manually excluded source row, so absence from an operational table is explainable.

## Transformation contract

### Counters

`total_clicks` becomes `counter_readings.reading_value`; `daily_clicks` is never inserted. Multiple same-day values remain distinct. The 27 date-only rows use the locked deterministic Asia/Jakarta `MIGRATION_SYNTHETIC_TIME` convention, with stable source ordering and 1 ms increments only when needed. Two possible duplicates remain excluded. The one high-confidence overlap is crosswalked to existing counter `6e40a1e2-72ef-4878-b669-65271ac144b9` and is not duplicated.

### People

Ten approved prospective historical Operational People receive deterministic IDs in the disposable target. Existing Angga, Daffa, and Akmal IDs are reused. Ambiguous or archive-text actor values remain snapshots without fuzzy write-time mapping. No Auth user, membership, role, email, or password is generated.

### Components and lifecycles

Exact labels resolve through the effective C1070 slot, preserving physical slot identity. The five ambiguous replacements are excluded, and `Other Part` never resolves to `TEST_COMPONENT`. Acquisition-only ambiguous labels use noncanonical Inventory Items with `component_id = NULL`.

The 47 eligible replacement rows become Level A closed lifecycle intervals between two verified source counter boundaries. They do not become fabricated replacement events because the source lacks reason, removal condition, inventory source, and exact physical time. The first boundary for each of 18 slots remains archive-only; it establishes no predecessor start. `installed_at` and `removed_at` remain null for imported intervals, and UNKNOWN current lifecycles remain unchanged. The accepted target’s 30 lifecycle rows and two replacement chains are preserved semantically by target fingerprints and immutable IDs.

### Purchases and inventory

All 161 purchase rows become draft acquisition evidence with `LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED` notes. Reconciliation is 208 units and IDR 371,029,998. They create zero receipts, Inventory Movements, stock increases, and FIFO layers.

The 22-row/11-unit mutable inventory snapshot is excluded as MANUAL_REVIEW. No production opening balance is created. The small `NON_PRODUCTION_FIXTURE` stock in the disposable baseline exists only to preserve and test the two accepted newer target replacement chains; it is not derived from legacy stock and cannot be used by hosted apply.

### Incidents and costs

The category map is Human Error → `human`, Print Test → `test_print`, Machine Error → `machine_operation`. The two later high-confidence duplicate writes are skipped. Four source multipliers are represented as `penalty_multiplier`; stored assessed loss is not multiplied twice. The 89 imported incidents reconcile to IDR 455,962.36 after the two explicit duplicate exclusions. No Maintenance record, Machine Cost aggregate, report row, or browser-local click price is migrated.

## Proof and reconciliation

The accepted source fingerprints are unchanged. The controlled baseline fingerprint is `ac062a965647c6a1edbd1dc7ea4406fae4a26687f84f8ef66ae6b48bdc5eb8ab`; the deterministic migrated target fingerprint is `2cfd1d65e12b66afe2ae8e03e4d2e5c486a031593faa8a9254a3d132057a9654`.

| Result | Proven value |
|---|---:|
| Source rows | 526 |
| IMPORT / MERGE | 476 / 1 |
| SKIP_DUPLICATE / ARCHIVE_ONLY / MANUAL_REVIEW | 2 / 18 / 29 |
| Eligible/accounted | 497 |
| Unexplained | 0 |
| Counters imported / merged | 179 / 1 |
| Verified lifecycle intervals | 47 |
| Historical people created | 10 |
| Purchases / quantity / acquisition value | 161 / 208 / IDR 371,029,998 |
| Purchase-derived receipt / movement / FIFO | 0 / 0 / 0 |
| Incidents imported | 89 |
| Graha legacy leakage | 0 |

The identical second apply leaves the postflight fingerprint unchanged. A fresh ledger rebuild produces the same baseline and postflight fingerprints. Deliberate division-by-zero after all planned statements rolls the transaction back and leaves the pre-failure fingerprint unchanged.

## Limitations and M2.10B prerequisites

Complete legacy database/Auth/private Storage/extension/migration-history inventory remains unavailable. M2.9C proved that the legacy UI’s operational reads and writes use only the five known tables, so this is not an M2.10A blocker. Before hosted staging, the user must still accept five-table scope (or provide full admin inventory), decide the two counter overlaps, remaining PIC approval, ten people, five ambiguous replacements, and the draft historical Purchase representation. Hosted backup, exact hosted fingerprint, and explicit apply authorization are also required.

Production remains separate and additionally requires stock opname, approved opening-stock manifest/location, freeze owner/window, final complete source snapshot/fingerprints, backup/rollback, reconciliation, and acceptance.
