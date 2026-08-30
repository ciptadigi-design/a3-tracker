# Production Cutover Runbook

## Roles

| Responsibility | Owner |
|---|---|
| Cutover owner | `TBD_USER_APPROVAL_REQUIRED` |
| Legacy freeze owner | `TBD_USER_APPROVAL_REQUIRED` |
| Database operator | `TBD_USER_APPROVAL_REQUIRED` |
| Application validator | `TBD_USER_APPROVAL_REQUIRED` |
| Business acceptance owner | `TBD_USER_APPROVAL_REQUIRED` |
| Rollback decision owner | `TBD_USER_APPROVAL_REQUIRED` |

No person may combine an unreviewed database operation with sole GO approval.

## Window worksheet

| Checkpoint | Planned time | Actual time | Owner | Evidence/status |
|---|---|---|---|---|
| Freeze announcement | TBD | | | |
| Freeze start / legacy write disable | TBD | | | |
| Freeze independently confirmed | TBD | | | |
| Complete final source snapshot | TBD | | | |
| Source counts/fingerprints/plan | TBD | | | |
| Production backup and proof check | TBD | | | |
| Target fingerprint lock | TBD | | | |
| Migration start | TBD | | | |
| Opening stock apply | TBD | | | |
| Reconciliation | TBD | | | |
| Signed-in frontend smoke | TBD | | | |
| Business acceptance | TBD | | | |
| GO or ROLLBACK decision | TBD | | | |
| Legacy archive or controlled unfreeze | TBD | | | |

The proven M2.10B mutation itself took about 10 seconds, but that is not the outage estimate. Reserve time for backup, full snapshot, hashing, preflight, apply, stock, reconciliation, signed-in validation, decision, and a full restore contingency. Record measured rehearsal durations again with the provisioned Production-like snapshot before scheduling.

## Observable phases

1. `preflight`: exact git SHA/CI, 48-migration ledger, target identity, Account/Branch/Machine/profile checks, zero unexplained target drift.
2. `freeze-check`: freeze owner disables writes and a second owner confirms; record timestamp and evidence.
3. `snapshot`: fetch every legacy source table fresh, record 526 only as the prior comparison—not an expected final count—and hash each table.
4. `plan`: apply locked mappings to the fresh snapshot, account for every row, and review every changed disposition.
5. `backup-check`: verify native backup/PITR status; create logical schema/data exports and verify a disposable restore. Never claim PITR without proof.
6. `apply-data`: require exact source and target fingerprints, execution UUID, backup proof, approvals, Production ref, production flag, and confirmation token.
7. `apply-opening-stock`: use only the separately approved physical manifest and stable-ID crosswalk.
8. `reconcile`: counters, lifecycles, purchases, incidents, stock, Branch isolation, derived costs, and unexplained remainder.
9. `smoke`: execute the signed-in checklist below and inspect redacted logs.
10. `accept`: business owner signs data acceptance; rollback owner records GO/ROLLBACK.

## Signed-in smoke and data acceptance

Test Login, Branch selector, Overview, Machines, Daily, Components, Inventory, Machine Cost, Errors, Reports, Superuser Settings, and My Account. Verify Tuparev and Graha and persistence across branch changes. On 360, 390, 393, 430, 768, 1024, 1366, and 1440 px, verify no horizontal overflow or blocked actions.

Daily, Inventory, Errors, and Reports must default to 10 rows, offer 10/25/50, reset/revalidate pages when filters/Branch/Machine change, and calculate KPIs over the full filtered data. Machine Cost order is COST → Daily Click Trend → BUSINESS and consumption derives only from real evidence. Error creation must use canonical Branch-eligible Operator/PIC Terlibat, preserve override behavior, and contain no hardcoded names. Settings must retain accepted Machine Models gutter/Manufacturers/Models/Profiles responsiveness.

Tuparev acceptance: chronological counters; 28-slot profile/assignments and reconciled lifecycles; physical-count-only stock; 161 historical acquisition records with no fake receiving; 89 historical incidents; consistent Machine Cost and Reports. Graha must have zero legacy counters, lifecycles, purchases, incidents, and stock.

## Observability

During the window monitor Supabase Auth failures, RLS denials, database constraints, Edge Function failures, Vercel/frontend runtime errors, API failures, and unexpected Branch data. Preserve timestamps, request IDs, deployment SHA, and redacted error summaries. Never copy JWTs, passwords, service-role keys, database credentials, or personal password material into logs/artifacts.

## Internal freeze message template

> A3 legacy tracker will be read-only from **[FREEZE START]** for the Production cutover window. Do not enter, edit, or delete legacy data until **[FREEZE OWNER]** confirms the result. After GO, use **[NEW URL]**. After ROLLBACK, use **[LEGACY URL/INSTRUCTION]**. Status owner: **[CUTOVER OWNER]**; expected decision by **[TIME]**.

After GO, keep legacy read-only for an approved archival window. Preserve the frozen snapshot, exports, fingerprints, plan, crosswalk, reconciliation, and approval record. Do not destroy the legacy project during cutover.
