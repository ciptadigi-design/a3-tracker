# M2.10A Disposable Migration Engine Contract

M2.10A means **Disposable Migration Engine & Full Dry Run**. It builds the actual engine, defaults to dry-run, plans all 526 rows, and may write only to a local/disposable database reconstructed from the exact migration ledger. It must prove deterministic UUIDv5 target/request IDs, idempotent retry behavior, merge/exclusion/archive accounting, reconciliation, transaction failure, and rollback/restore.

Eligibility: 476 APPLY_ELIGIBLE + 1 MERGE_ELIGIBLE + 2 SKIP_DUPLICATE + 18 ARCHIVE_ONLY + 29 EXCLUDED_MANUAL = 526; remainder zero. Field-level daily counters remain SKIP_DERIVED.

Required inputs: explicit manifest, expected source fingerprints, expected target domain fingerprints, locked Account/Branch/Machine UUIDs, execution UUID, manual exclusion list, and Graha denylist. Default mode is `dry-run`; `--apply` must additionally prove the target class is `DISPOSABLE_LOCAL_EXACT_SCHEMA`. Hosted DEV and Production target classes have no executable mutation path in M2.10A.

Opening stock is excluded or supplied only as a fixture marked `NON_PRODUCTION_FIXTURE`; fixture data is rejected for hosted/Production targets. Purchases create draft acquisition rows/lines only in the disposable proof, with explicit legacy and receipt-unknown notes; generated receipts, movements, balances, and FIFO lots must remain zero. Archive-only evidence remains in the manifest/crosswalk.

M2.10B is a later **Hosted Staging Migration Acceptance** milestone requiring approval of remaining hosted mappings, full legacy inventory or accepted limitation, exact hosted fingerprint, backup, and explicit apply authorization. Production apply remains separate and additionally requires physical stock opname, opening approval, freeze owner/window, final snapshot/fingerprints, rollback, reconciliation, and acceptance.

M2.10A must not mutate hosted DEV or Production, create final opening stock, perform final freeze, or start domain cutover.
