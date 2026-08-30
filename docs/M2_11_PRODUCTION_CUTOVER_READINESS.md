# M2.11 Production Cutover Readiness

Status: **PRODUCTION_CUTOVER_CONDITIONAL**. M2.11 did not mutate hosted DEV or Production. It did not run the final import, DNS cutover, shared-hosting work, PHP/MySQL work, or Maintenance.

## Discovered target architecture

The current application architecture remains React on Vercel plus Supabase/PostgreSQL. No dedicated A3 Production Supabase project exists in the accessible organization. The only A3 Vercel project is `a3-tracker-dev`; its `develop` production branch and browser variables point to NEW DEV (`sxitqjxljoqsnpepymrl`). No Production domain, Production Auth configuration, Production Edge Functions, or Production data target was found. These findings make DEV categorically unsuitable as Production.

The lowest-risk target is a new dedicated Supabase Production project and a separate Vercel Production project. Provisioning requires explicit user authorization. The recommended domain is `a3.ciptagrafika.com`; `mesin.ciptagrafika.com` is broader but less product-specific. DNS remains approval-gated.

## Exact schema and infrastructure plan

Provision schema exclusively from all 48 repository migrations in order, ending at `20260830000100_incident_operator_person.sql`. Do not create tables manually. Then deploy the four repository Edge Functions (`auth-login`, `provision-member`, `manage-account`, and `bootstrap-platform-superuser`) with Production-only secrets and configure Production Auth URLs. The prior `20260829000600_production_security_hardening.sql` remains in the ledger but is no longer the latest migration.

Provision deterministic structural masters before historical evidence: Cipta Grafika Account, Tuparev and Graha Branches, Konica Minolta manufacturer, the C1070 model, accepted component catalog/model profiles, `CG-TUP-A3-01`, 28 unique assignments, eligible Operational People, and an approved inventory location. Do not seed Xerox or speculative masters. Resolve IDs through the final manifest; the current location candidate `b6296488-5479-4dd0-9463-091891b4cbe4` is valid only if the Production provisioning crosswalk deliberately retains it.

The Production C1070 gate requires exactly 28 active effective profile slots, 28 assignments, no duplicate normalized slot codes, and an explicit current-lifecycle initialization decision. Historical migration contributes 47 verified closed lifecycle intervals; it must not manufacture an unknown predecessor boundary.

## Rehearsal result

Execution `6a8f4bb8-1f31-4e03-a9db-fdd9821e8d78` used a current-ledger, local disposable target with deterministic Account/Branches/Machine, 28 assignments, and zero operational evidence. The accepted non-frozen M2.10B source snapshot was used only as rehearsal input.

- Baseline fingerprint: `8c4ab77d2b05a863e261d224651dce605cf65cc3470392c0316d134c311ab7c6`.
- Source aggregate fingerprint: `43d01460d9aae1605b1e0466d68ab324caa07099da24539e42eb20e0fac0cb3e`.
- Post-apply fingerprint: `f78296a4427113e2f397d25a0a2d820d2ae839f47e413ed5ecf2132eddd21533`.
- Post-stock-fixture fingerprint: `80738b4383169e584df993b3790017ebbe5e2e0aafd69a2385a9e048f02a82b8`.
- Disposition: 477 IMPORT, 0 MERGE, 2 SKIP_DUPLICATE, 45 ARCHIVE_ONLY, 2 APPROVED_EXCLUDE, 0 MANUAL_REVIEW; 526/526 accounted and zero unexplained.
- Applied: 180 counters, 47 lifecycle intervals, 161 purchases/lines, 89 incidents, 10 historical people, 9 suppliers, and 22 items.
- Purchases remained acquisition-only: 208 units / IDR 371,029,998, with zero receipt, movement, or FIFO evidence.
- Graha leakage was zero. Failure injection rolled back. A second complete apply was fingerprint-idempotent.
- The explicit non-Production stock fixture produced two movements/two lots: one known-cost unit and two unknown-cost units. Unknown remained SQL `NULL`, not Rp0.

The logical backup consists of private public-schema and public-data exports whose hashes are recorded in the committed evidence. Restore into a freshly reconstructed current-ledger disposable database reproduced the exact baseline fingerprint. The same restore proved rollback after committed migration and fixture state; no evidence was deleted manually.

## Locked cutover semantics

Final cutover must use a fresh complete snapshot taken only after a confirmed legacy write freeze. Timestamp deltas are forbidden. Recalculate per-table and aggregate fingerprints and stop if the source changes. Generate a Production-specific target preflight and classify each target row CREATE, MERGE, ALREADY_PRESENT, CONFLICT, or SKIP. Never copy the DEV crosswalk blindly.

Accepted semantics remain: two ambiguous counters excluded; `oan` maps to Akmal Fauzan; `sri bulan` stays archive-text-only absent stronger evidence; ten historical people are Operational People, not Auth users; five ambiguous replacements are archive-only; legacy purchases create no receiving or stock; and the 22-row/11-unit legacy inventory display snapshot creates no opening balance. Other Part and generic Drum Unit, Charging Corona, and Developing Unit may stay `component_id = NULL`.

Production mode remains deliberately unavailable in M2.11. A future final-cutover implementation must require exact Production ref, production flag, frozen source fingerprints, target fingerprint, verified backup proof, freeze confirmation, approved stock manifest/hash, execution UUID, named approvals, and a human confirmation token. Unknown targets and DEV must refuse.

`scripts/migration/apply-opening-stock.mjs` is the observable opening-stock boundary for rehearsal. It defaults to transaction rollback, requires an explicit manifest, execution UUID and expected target fingerprint, accepts only the local disposable target class, and hard-blocks every hosted project, DEV, and Production. M2.11 does not contain a Production apply switch.

## Remaining gates

Required before GO: provision and audit dedicated Production Supabase/Vercel projects; confirm backup/PITR entitlement (PITR was not claimed); name all cutover roles; schedule the window; complete and approve physical stock opname; approve the actual Account/Branch/Machine/location crosswalk; approve current lifecycle bootstrap state; create only real Auth accounts; select domain; run signed-in Production smoke and responsive acceptance; and give explicit final Production apply approval.

The detailed procedures are in `docs/production/`. Rehearsal evidence is in `scripts/migration/reconciliation/m2-11/`.
