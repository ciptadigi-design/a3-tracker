# Production Rollback Runbook

The rollback authority is `TBD_USER_APPROVAL_REQUIRED`. Prefer restoring the verified known-good backup; never hand-delete immutable migration evidence.

## A. Failure before commit

Stop the command, retain its logs, confirm the transaction rolled back, recompute the target fingerprint, and compare it to preflight. If identical, keep Production unavailable while the cause is reviewed. The M2.11 injected database failure proved this path.

## B. Commit succeeds but reconciliation fails

Block frontend GO and all writes. Capture redacted diagnostics and the unexpected fingerprint. Restore the known-good Production recovery point into Production using the provider-approved restore procedure, verify schema ledger/counts/fingerprints and Account/Branch/Machine records, then rerun the pre-cutover smoke. Do not issue compensating DELETE statements.

## C. Data reconciles but application acceptance fails

If a safe code-only rollback is explicitly proven compatible with the migrated schema/data, the rollback owner may approve it. Otherwise keep writes stopped and restore the known-good database plus prior frontend deployment as one coordinated recovery. Confirm the legacy tracker remains read-only or deliberately unfreeze it only after rollback completion.

## Restore verification

Verify ledger; schema objects; RLS; Account/Branches/Machine; 28 assignments; counters; lifecycles; purchases; incidents; inventory movements/lots; source and target fingerprints; zero Graha leakage; Auth/login; and signed-in pages. Record operator, timestamps, backup identifier/hash, restored fingerprint, and decision. M2.11 restored its disposable baseline exactly from private logical artifacts without touching hosted systems.
