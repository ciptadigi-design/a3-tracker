# M2.12F Full Domain Reconciliation

Status: **READY — offline reconciliation complete; no writes executed.**

- New Supabase capture SHA256: `94bfe30b539fb490b59e349e2d271ba594de882863f2f17dc34351482786bc5e`
- Production MariaDB capture SHA256: `6c79b0be60bc62e622f348c660a29aaf9191062b20793ada304222fd886e5e16`
- Production runtime source of truth: Hostinger MariaDB
- New Supabase role: read-only reference
- Bulk migration: rejected
- Deterministic write plan: `/Users/imadprakoso/Projects/konica-tracker-next/docs/evidence/m2-12f-production-write-plan.json` (approval-gated; not executed)

## Domain results

| Domain | Reconciliation |
|---|---|
| accounts | source=1, production=1, matched=1, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| branches | source=2, production=2, matched=2, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| machine_models | source=2, production=1, matched=1, legacy=0, source-only=1, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| machines | source=2, production=1, matched=0, legacy=0, source-only=1, production-only=0, duplicate=0, test/dev=0, ambiguous=1, planned=0 |
| component_catalogs | source=29, production=28, matched=24, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=1, ambiguous=4, planned=0 |
| model_profiles | source=2, production=1, matched=1, legacy=0, source-only=1, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| model_profile_slots | source=55, production=28, matched=0, legacy=0, source-only=23, production-only=0, duplicate=3, test/dev=1, ambiguous=28, planned=0 |
| machine_components | source=75, production=28, matched=0, legacy=0, source-only=23, production-only=0, duplicate=24, test/dev=0, ambiguous=28, planned=0 |
| component_lifecycles | source=75, production=47, matched=0, legacy=47, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=28, planned=0 |
| counters | source=185, production=183, matched=0, legacy=182, source-only=3, production-only=1, duplicate=0, test/dev=0, ambiguous=0, planned=2 |
| operational_people | source=17, production=13, matched=4, legacy=0, source-only=0, production-only=7, duplicate=0, test/dev=0, ambiguous=13, planned=0 |
| operational_person_branch_assignments | source=17, production=13, matched=3, legacy=0, source-only=0, production-only=7, duplicate=0, test/dev=0, ambiguous=14, planned=0 |
| inventory_locations | source=2, production=1, matched=1, legacy=0, source-only=1, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| inventory_items | source=34, production=32, matched=14, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=2, ambiguous=18, planned=0 |
| purchases | source=161, production=161, matched=0, legacy=161, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| receipts | source=0, production=0, matched=0, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| inventory_movements_fifo | source=10, production=0, matched=0, legacy=0, source-only=10, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| incidents_errors | source=91, production=89, matched=0, legacy=89, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=2, planned=0 |
| machine_cost_source_facts | source=1, production=0, matched=0, legacy=0, source-only=1, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=1 |
| operational_policies | source=1, production=1, matched=1, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| counter_types | source=4, production=4, matched=4, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| settings_configuration | source=1, production=1, matched=1, legacy=0, source-only=0, production-only=0, duplicate=0, test/dev=0, ambiguous=0, planned=0 |
| auth_identity_references | source=1, production=1, matched=0, legacy=0, source-only=0, production-only=1, duplicate=0, test/dev=1, ambiguous=0, planned=0 |

## Component configuration audit

- Audit: **DIFF_FOUND**
- Actual configuration writes required: **YES**
- Production component catalogs / profile slots / assignments / lifecycles: 28 / 28 / 28 / 47
- Production active lifecycles: 0
- Production latest effective counter: 1441597
- Approval-gated canonical field differences: catalogs=4, profile slots=28, machine components=28.
- The deployed 28 assignments and 47 closed lifecycle rows are not duplicated. Reference duplicate/test profile rows are excluded. Ambiguous configuration differences are deliberately absent from the write plan.

## Derived domains

- Machine Cost source-of-truth classification is MIXED: totals are derived from counters, lifecycle/replacement facts, acquisition costs, and incident/waste facts, while persisted selling-price/configuration facts are reconciled separately. Calculated totals are never migrated.
- Reports remain derived projections. No dashboard/report totals are included in the write plan.
- Settings full-set assignment parity fix is IMPLEMENTED in the combined hotfix; settings data differences remain separate and approval-gated.
- Auth rows were not captured and auth users are never included in the write plan.

## Global totals

- TOTAL_MATCHED=57
- TOTAL_PRODUCTION_ONLY_VALID=16
- TOTAL_SUPABASE_ONLY_CANONICAL=50
- TOTAL_SUPABASE_ONLY_OPERATIONAL_VALID=14
- TOTAL_ALREADY_MIGRATED_LEGACY=479
- TOTAL_DUPLICATE=27
- TOTAL_DEV_TEST_EXCLUDED=5
- TOTAL_AMBIGUOUS=136
- TOTAL_REQUIRES_USER_APPROVAL=200
- TOTAL_PLANNED_CREATE=3
- TOTAL_PLANNED_UPDATE=0
- TOTAL_PLANNED_WRITES=3
- UNEXPLAINED=0

## Safety

- NETWORK_ACCESS_DURING_RECONCILIATION=NO
- DATABASE_ACCESS_DURING_RECONCILIATION=NO
- PRODUCTION_MUTATION=NO
- PRODUCTION_DATABASE_MUTATION=NO
- PUBLIC_HTML_MUTATION=NO
- PRODUCTION_DEPLOYMENT=NO
- LEGACY_FREEZE=REMAINS_ACTIVE

## Closure status (2026-10-21)

M2.12F is **CLOSED for implemented scope; three data decisions remain open (none are code work).**

- DONE: offline full-domain reconciliation; write-plan review; approved component-configuration patch applied to Production (126 compare-and-set field updates on 60 rows, backups SHA-256 recorded, zero failures — see `M2_12F_COMPONENT_CONFIG_PRODUCTION_APPLY.md`).
- OPEN, needs an owner decision (nothing is written without it):
  1. Two historical `total_impressions` counter facts (1437283 @ 2026-08-26T11:15Z, 1438992 @ 2026-08-27T15:08Z): **DEFERRED**. A CREATE alone is unsafe; it needs an atomic predecessor/successor relink on Production counters.
  2. 76 ambiguous records (28 lifecycles, 2 incidents, 18 inventory items, 1 machine identity/config, 13 people, 14 person-branch assignments) plus 62 deferred/excluded.
  3. Counter backfill: DEFERRED. Selling-price fact: REJECTED (voided test row).
- `docs/evidence/m2-12f-new-supabase-capture.json` contains a personal phone number and is intentionally not committed; keep it local.
- Legacy freeze remains active. No Production write was made in this closure step.
