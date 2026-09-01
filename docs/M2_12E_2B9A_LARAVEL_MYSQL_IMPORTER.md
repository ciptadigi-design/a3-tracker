# M2.12E 2B-9A Laravel/MySQL importer

The Production import architecture is now split into explicit layers:

`Frozen source → canonical Node planner → neutral JSON manifest → Laravel manifest validation → one MySQL transaction → reconciliation`

`scripts/migration/m2-10a-engine.mjs` remains the semantic oracle. The planner emits `m2.12e-neutral-import-v1`; it performs classification, mappings, exclusions, deterministic IDs, source provenance, and all locked inventory/person rules. Laravel does not reimplement those rules. It validates the manifest and applies only the planned relational records.

The Artisan command is `php artisan a3:legacy-import`. It is fail-closed: `--dry-run` is the default-safe mode, `--apply` is required for writes, and Production additionally requires `--confirm-production`. The complete apply is wrapped in `DB::beginTransaction()`/`commit`; all exceptions roll back. Existing deterministic identities are compared field-by-field and conflicting rows fail rather than being overwritten.

The importer never creates legacy receipts, inventory movements, FIFO layers, or opening stock. Purchases are acquisition evidence only. Unknown costs remain nullable. `ijal` is retained as source text and never becomes an operational person or auth user.

The PostgreSQL disposable apply path is historical rehearsal tooling only. It is not the Laravel/MySQL Production apply mechanism. 2B-9A parity must be accepted on disposable MySQL before any Production execution is authorized.

## Lifecycle schema parity

Migration `2026_09_01_000100_add_lifecycle_counters.php` is forward-only and adds nullable `DECIMAL(20,4)` `installed_counter`, `removed_counter`, and `actual_usage` columns. This matches the canonical numeric domain; NULL remains unknown, and `actual_usage` preserves `removed_counter - installed_counter` when both counters are known. MySQL/MariaDB enforce `removed_counter >= installed_counter` when both values are known, while the application validates the same invariant for portable test targets. Existing lifecycle rows are not backfilled.

Production currently has 15 applied migrations. This migration is planned only and has not been executed against Production:

```text
CURRENT_PRODUCTION_MIGRATIONS=15
PRODUCTION_FORWARD_MIGRATION_REQUIRED=YES
PRODUCTION_FORWARD_MIGRATION_EXECUTED=NO
```
