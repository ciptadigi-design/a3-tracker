# Consolidated Production Acceptance — 2026-10-21

Production: https://a3.ciptagrafika.com at `c3a7def2e52162c77a465bc99349f6479bca22aa` (`/api/v1/version`, schema_batch 26). Exact-SHA Database CI and Laravel MySQL Target CI are green. Read-only session: no Production data, rates, tickets, permissions, migrations or knowledge were touched, and nothing was redeployed.

Evidence labels: **PROD** verified in Production now · **CI** verified by automated tests (exact-SHA CI, plus local run of the relevant suites: 291 passed, 1 SQLite-only failure in `MaintenanceOfficialKnowledgeTest` caused by an old rollback test, green on MySQL CI) · **USER** confirmed by the user · **UNVERIFIED** no independent evidence.

## Scope found

- **Phase 3.2** has no written acceptance criteria in `docs/`. Its scope is taken from commits `aba41b5`, `acda154` and the migrations `2026_10_20_000100` / `000200`: maintenance activity log; internal PIC / external technician; resolution form (breakdown tickets need a complete resolution to reach DONE and cannot close while the test result is `not_normal`); narrow operator capabilities; append-only, auditable activity corrections; downtime stays independent of the ticket.
- **M2.20** scope is `M2_20G_CONTROLLED_PILOT_ACCEPTANCE.md` (A–G: identity, tenant/branch isolation, effective capabilities, audit, owner administration, time/currency contract) and its post-deployment acceptance matrix.

## Results

| Area | PROD | CI | USER | UNVERIFIED |
|---|---|---|---|---|
| Release / health | health ok, version = target SHA, all migrations through `2026_10_21_000100` Ran (incl. `session_version`, 3.2 migrations); `/me`, downtime, field-experience and rates endpoints return 401 unauthenticated | both CI workflows success | | |
| Machine condition, downtime, schedule clipping, open/closed incidents, ticket link | schema deployed | `DowntimeMaintenanceV1`, `DowntimeKpiAnalytics` (23), `DowntimeTicketIntegration`, `ProductionScheduleBranchScope` | | live incident history rendering |
| Financial Impact (separate indicators, production-downtime basis, overlap allocation, reconciliation, missing vs zero, current-rate label) | deployed, bundle contains UI strings | 23 KPI tests incl. overlap reconciliation | Overview, rate configuration and calculations exercised in Production; UI accepted | |
| Official C/J knowledge | | `MaintenanceOfficialKnowledge`, `MaintenanceErrorKnowledge` | J-code search confirmed; 625 C parents, 376 J entries / 371 unique, 2 ambiguous quarantined (import evidence) | independent Production count (needs authenticated/DB access) |
| Field experience, account-wide visibility, ticket permissions | endpoint deployed (401) | `MaintenanceFieldExperience`, `MaintenanceTroubleshootingTicketFlow` | Phase 3.3 search previously deployed and verified | |
| Tenant / branch isolation, capabilities | | `M2_20B/C`, `BranchAuthorizationScope`, `M2_20GControlledPilotAcceptance` | | authenticated cross-account / branch checks in Production (single real tenant) |
| Phase 3.2 resolution/PIC/corrections behaviour | tables deployed | `MaintenanceActivityResolution` | | a real ticket resolved end to end in Production |

## Decisions

- **PHASE_3_2_ACCEPTANCE = PARTIAL.** Behaviour is covered by CI and deployed, but there are no written criteria and no recorded Production exercise of the activity/resolution/correction flow. Missing: one owner/operator walkthrough of resolution + correction on an existing ticket (no test ticket may be created for this).
- **M2_20_ACCEPTANCE = PARTIAL.** Unauthenticated matrix row and release record pass. Missing: the authenticated rows (CG Owner, Operator capability scoping, session reset, cross-account) in Production. G-P1 CSV formula neutralization: RESOLVED 2026-10-21 by code and tests (see M2.20G doc); this does not change the PARTIAL status.

## Still tracked (not complete)

M2.12F Reconciliation: implemented scope closed (see `M2_12F_FULL_DOMAIN_RECONCILIATION.md`); open owner decisions: 2 deferred historical counters (need atomic relink), 76 ambiguous records · CSV formula neutralization (G-P1) resolved 2026-10-21 (code + tests) · Assisted Knowledge Coverage Verification: sampled and checked 2026-10-21, mostly PASS with 1 PARTIAL (numeric-only official search is C-only) and 1 GAP (J-7272 SD-510, J-7524 FD quarantined) - see `maintenance/ASSISTED_KNOWLEDGE_COVERAGE_VERIFICATION.md` · J-code Team Feedback · M2.20D Deferred Audit Events · other deferred V2+ milestones.
