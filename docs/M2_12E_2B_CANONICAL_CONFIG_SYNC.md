# M2.12E 2B — minimal-safe canonical configuration sync

Status: **READY FOR OPERATOR-ASSISTED PRODUCTION DRY-RUN; NOT APPLIED**

This checkpoint implements the locked `MINIMAL_SAFE_DAY1` decision as the dedicated Laravel command `a3:sync-canonical-config`. It does not reuse the legacy importer or a migration. No Production connection, backup, database write, public activation, or frontend build occurred while preparing it.

## Deterministic source reconciliation

The computed first-run plan has exactly 16 creates and no updates or deletes:

| Group | Count | Semantic crosswalk |
|---|---:|---|
| Graha branch | 1 | `CG-GRH` / Graha / Asia/Jakarta / active; new Production-local UUIDv4 |
| Operational policy | 1 | Existing Production account; initialize false, replace true, purchasing/receiving/adjust/transfer false, log-errors true |
| Counter types | 3 | `color_impressions`, `bw_impressions`, `operating_hours`; new Production-local UUIDv4 identities |
| Tuparev inventory location | 1 | `CG_DIGITAL` / CG Digital Print, attached only to the existing Production Tuparev identity |
| Inventory items | 10 | Exact remaining active C1070 component gap after preserving the 18 already represented component-linked acquisition items |

The ten inventory-item component keys are `CHARGING_CORONA_K`, `CHARGING_CORONA_Y`, `CLEANING_UNIT`, `DEVELOPER_M`, `DEVELOPING_UNIT_C`, `DEVELOPING_UNIT_K`, `DEVELOPING_UNIT_M`, `DRUM_K`, `DRUM_Y`, and `ROLL_MESIN`. Each is crosswalked to its existing Production-local component identity. If one has gained an unclassified linked item, the command stops instead of making a duplicate.

The existing `total_impressions` counter remains unchanged. The existing JFP supplier remains unchanged. The command contains no supplier, person, Auth, Graha machine/location, stock, receipt, movement, FIFO, lifecycle, incident, purchase, counter-reading, or historical-FK write path.

## Safety contract

The command requires exactly one of `--dry-run` and `--apply`. It opens a transaction, locks and verifies the known Production account, Tuparev, C1070 model, primary machine, and sole master user; fingerprints all existing Auth, operational people, suppliers, machine/component configuration, and operational-history tables; computes and prints the complete write plan; performs inserts only; rechecks protected fingerprints and `LEGACY_GRAHA_OPERATIONAL_RECORDS=0`; then rolls back a dry-run or commits an explicitly authorized apply.

Existing rows must match exactly. Business-key or UUID collisions, a changed component gap, missing schema, non-MySQL/non-disposable-SQLite targets, extra Auth users, DEV UUID leakage, or an unexpected create count fail closed. The `--expect-create` check validates the actual plan and never pads it to a requested count.

A future Production apply additionally requires `--confirm=APPLY_MINIMAL_SAFE_DAY1`. In `APP_ENV=production`, it also requires the path and SHA-256 of a fresh readable gzip database backup; the command verifies the hash and gzip stream before beginning reconciliation. Rollback is restoration of that backup, not ad-hoc deletes.

## Operator-assisted Production dry-run

From the prepared Laravel release, with the Production environment loaded but before any apply:

```bash
php artisan a3:sync-canonical-config --dry-run --expect-create=16
```

Accept only if the complete plan is the reviewed 16-row set and the terminal report includes:

```text
CONFIG_CREATE=16
CONFIG_UPDATE=0
CONFIG_DELETE=0
DEV_UUID_LEAKAGE=0
AUTH_USER_CREATION=0
PERSON_IDENTITY_REWRITE=0
OPERATIONAL_FACT_WRITES=0
FAKE_STOCK_CREATION=0
FAKE_LIFECYCLE_CREATION=0
LEGACY_GRAHA_OPERATIONAL_RECORDS=0
NEW_WRITES=0
TRANSACTION_RESULT=ROLLED_BACK
CONFIG_SYNC_DRY_RUN=PASS
```

Any discrepancy is a STOP. Do not run `--apply`, migrations, the legacy importer, public activation, or frontend replacement as part of this dry-run step.

## Local disposable evidence

`CanonicalConfigSyncTest` executes the actual Artisan command against a migrated in-memory SQLite target. It proves the 16-create dry-run rolls back, first apply creates only the reviewed configuration, second apply reports zero new writes, operational/Auth rows remain unchanged, conflicts roll back, and unclassified component-linked items stop safely. Result: 4 tests / 44 assertions passed; the PHP 8.5 runtime reports only the framework's existing PDO MySQL constant deprecation notices.

The canonical frontend artifacts were not modified. Preserved hashes:

- dist: `39804f4e132f63f5afcb32d832bf427034c84cae82e2829df2d0a3f902cc178b`
- index: `4cda8392c40749df52977f330021bd186d2e2dcbaafe29f6d7d844d53f735be8`
- runtime target: `LARAVEL`
