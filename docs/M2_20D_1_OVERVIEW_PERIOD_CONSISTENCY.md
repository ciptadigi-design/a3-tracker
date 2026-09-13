# M2.20D.1 — Overview Period Consistency

## Baseline and scope

- CURRENT_BRANCH=develop
- HEAD_SHA=c9d8ca9941fb9b110c4e603d5c92563705ecae63
- ORIGIN_DEVELOP_SHA=c9d8ca9941fb9b110c4e603d5c92563705ecae63 (verified after fetch)
- WORKTREE_CLEAN=NO
- Production is unchanged. No deployment, main merge, production database operation, or public_html operation is part of this change.

Pre-existing modified files (preserved byte-for-byte):

- `docs/M2_12E_PRODUCTION_CUTOVER_EXECUTION.md`
- `docs/M2_12F_FULL_DOMAIN_RECONCILIATION.md`
- `docs/evidence/m2-12f-full-domain-reconciliation.json`

Pre-existing untracked files (preserved byte-for-byte):

- `.m2-12e-2b-canonical-config-apply.sh`
- `.m2-12e-final-public-activation.sh`
- `.m2-12e-hostinger-routing-readonly-audit.sh`
- `.m2-12e-inplace-public-activation-fixture.sh`
- `.m2-12e-install-stable-root-routing.sh`
- `.m2-12e-new-supabase-c1070-readonly.mjs`
- `.m2-12e-pre-public-mode-audit-repair.sh`
- `.m2-12e-production-backend-hotfix-deploy.sh`
- `.m2-12e-production-c1070-components-readonly.sh`
- `.m2-12e-production-htaccess-processing-forensics.sh`
- `.m2-12e-production-routing-recovery-fixture.sh`
- `.m2-12e-production-routing-recovery.sh`
- `.m2-12e-routing-local-test.sh`
- `.m2-12e-stable-root-routing-fixture.sh`
- `.m2-12e-stable-root.htaccess`
- `.m2-12e-staging-ifmodule-forensics.sh`
- `.m2-12f-component-config-production-apply.sh`
- `docs/M2_12F_COMPONENT_CONFIG_PATCH_REVIEW.md`
- `docs/M2_12F_COMPONENT_CONFIG_PRODUCTION_APPLY.md`
- `docs/M2_12F_WRITE_PLAN_REVIEW.md`
- `docs/evidence/m2-12f-component-config-patch.json`
- `docs/evidence/m2-12f-component-config-production-apply.json`
- `docs/evidence/m2-12f-component-config-rollback.json`
- `docs/evidence/m2-12f-new-supabase-capture.json`
- `docs/evidence/m2-12f-production-write-plan.json`
- `docs/evidence/m2-12f-write-plan-review.json`
- `scripts/migration/fixtures/m2-12f-reconciliation-cases.json`
- `scripts/migration/m2-12f-component-config-patch.mjs`
- `scripts/migration/m2-12f-component-config-patch.test.mjs`
- `scripts/migration/m2-12f-component-config-production-apply.php`
- `scripts/migration/m2-12f-reconcile-captures.mjs`
- `scripts/migration/m2-12f-reconcile-captures.test.mjs`
- `scripts/migration/m2-12f-write-plan-review.mjs`

The initial SHA-256 manifest was captured before edits in `/tmp/m220d1-baseline.json`. All entries were verified again before commit. Only explicitly named milestone files are staged.

## Existing contract audit

| Surface | Existing implementation | State / requests |
| --- | --- | --- |
| Machine Cost | Inline Period select and native custom date inputs; `machineCostPeriodPresets`, `resolveMachineCostPeriod` | `usePersistentUIState`, sessionStorage, user/account/branch key; `period_start`, `period_end` to machine cost |
| Reports | Duplicate inline fields; **same** preset list/calculator as Machine Cost | Same state hook, user/account key; `period_start`, `period_end`; request sequence guards |
| Overview before change | Local current-month/today helpers; hardwired monthly Cost / Click | User/account/branch machine selection; click-target endpoint accepted only year/month; target refresh had no stale-response guard |
| Shared timezone | `inheritedMachineTimezone` frontend; `MachineTimezoneResolver` backend | Branch → account inheritance; backend machine override remains authoritative for machine data boundaries |
| Shared range safety | Reports controller validation | Strict YYYY-MM-DD, end ≥ start, maximum **366 elapsed days** (367 inclusive calendar dates) |

The reusable `PeriodFields` component now supplies all three pages. Machine Cost's existing markup/styling is retained. Reports retains its own readout/filter styling. The period engine remains `src/features/machineCost/machineCostPeriods.js`; no second or third preset calculator is added.

`OperationalPeriodRange` extracts Reports' existing validation for reuse by Overview's two reads. Invalid end dates now bail before the span callback; accepted ranges and maximum remain unchanged. Machine Cost's existing full endpoint behavior is unchanged when `summary_only` is absent.

## Metric audit and resulting semantics

| Existing Overview content | Baseline classification | Result |
| --- | --- | --- |
| Click target hero actual, achievement, status, variance | CURRENTLY_MONTH_BASED / PERIOD_SENSITIVE | Actual and allocated target over the selected dates |
| Expected / remaining target, remaining active days, required pace | CURRENTLY_MONTH_BASED and CURRENTLY_TODAY_BASED / PERIOD_SENSITIVE | Selected-period target, remaining selected target, active dates remaining within selection (today inclusive), pace to selected target |
| Cost / Click | CURRENTLY_MONTH_BASED / PERIOD_SENSITIVE | Same MachineCostService standard-cost numerator and effective usage denominator, exact selected range |
| This Week and This Month progress cards | Mixed week/month bases / PERIOD_SENSITIVE | Selected-period progress and target variance; existing three-card grid retained |
| Daily Click Performance actual/target bars | Full requested month / PERIOD_SENSITIVE | Only selected dates, canonical actuals and unchanged monthly daily target allocation |
| Previous-month comparison rows/tooltips | Derived from displayed range/day / PERIOD_SENSITIVE | Existing exact-calendar-month shift semantics, bounded historical read for each comparison range; daily comparisons batched |
| Today chart context | CURRENTLY_TODAY_BASED | Displayed only when today is in selected dates; machine-resolved timezone |
| Machine picker, active-machine eligibility, target-management actions | NOT_DATE_DEPENDENT | Unchanged authorization and machine eligibility |
| Standalone revenue, incident counts, replacement counts, inventory balances, branch aggregates | Not shown on Overview | No new metrics introduced. Replacement consumption and assessed machine Error / Waste inside standard cost are SQL-bounded by the selected period |

No displayed metric silently retains all-time data. Historical comparison data is explicitly labeled as previous-month evidence. The page remains a selected-machine workspace, not a new account-wide aggregation/reporting product.

Target allocation retains `allocate()` over each intersecting **complete month** and its operational calendar, then selects daily allocations for the requested dates. A partial month therefore compares actuals to its allocated period target, not the full monthly target. A range spanning an unconfigured month reports target/status as not fully configured instead of silently summing partial targets. Actuals and the chart remain available. These target semantics intentionally differ from monthly target administration's full-month projection; the old year/month API is unchanged.

## Period, timezone, state and backend

- Exactly Today, This Week, This Month, Last Month, This Year, Custom Range. Default This Month.
- Monday-start week; Today/Week/Month/Year end on context-local today; Last Month covers the complete preceding month. Actual ISO date-only range is displayed.
- Preset context: branch timezone → account default timezone. Reuse `inheritedMachineTimezone`, with explicit UTC fallback matching the backend when configuration is absent. Its existing default remains unchanged for all other callers.
- Per-machine metrics use `MachineTimezoneResolver` for half-open UTC database boundaries. A machine override differing from preset context is shown in the readout. No timezone architecture/currency changes.
- Period state follows Reports' sessionStorage user/account convention. Branch switches retain the account's selection and recompute dates. New accounts load their own saved selection or This Month. Machine selection remains user/account/branch-scoped.
- Context-keyed workspace remount hides previous machine lists and results synchronously. Within a context, request keys hide mismatched period results before effects run. The request sequence gate invalidates on cleanup, including invalid ranges/unmount. Both projections settle before one result commit. A denied/failed cost request yields Unavailable, never stale cost or fabricated zero.
- Existing `GET /api/v1/machines/{machine}/click-target` accepts optional `period_start`/`period_end` as the range path, retaining the year/month path.
- Existing `GET /api/v1/machines/{machine}/cost` adds `summary_only=1` for Overview. It validates the bounded report range, preserves MachineAccessResolver + `machine_cost.view`, and returns the existing standard cost projection without unrelated price/operating-cost histories or unused business projection reads.
- DailyActualUsageService now uses existing `forMachineWithinRange`, preserving real predecessor/delta semantics while eliminating full-history PHP filtering. Usage, replacements and incidents are SQL-bounded. Calendar queries are bounded to intersecting complete months, necessary for unchanged allocation. Target lookups are bounded by machine/year/month. Historical comparison reads are also SQL-bounded. No OperationalReportService duplication.
- Canonical machine clicks reconcile with Machine Cost and Reports for the same machine/date keys. Cost / Click reuses the exact same cost service and presentation function. Branch-only incidents remain a Reports concept, not a machine cost component.

## Validation mapping

| Requirements | Evidence |
| --- | --- |
| O01–O07 presets/default/custom | `overviewPeriod.test.js`, `OverviewPeriodTest`, rendered UI fixture |
| O08–O09 invalid/max safety | Frontend guards and both backend paths tested; existing M2.19.3 suite |
| O10–O11 synchronized cards/charts | Request gate tests; endpoint reconciliation; rendered preset changes assert hero, Cost / Click and daily bar counts |
| O12–O14 account/branch/timezone/stale | Boundary-date unit tests, account-scoped key tests, gate late success/failure tests; rendered refresh persistence, account switching and delayed old-branch response |
| O15 isolation | New forbidden cross-account/unassigned-branch requests plus M2.20B suite |
| O16–O17 Machine Cost/Reports | Existing frontend/backend suites, direct cost/report reconciliation, period transport and range suites |
| O18–O19 capability/audit | Existing M2.20C/M2.20D suites unchanged |
| O20 responsive | Real rendered Overview at 1440/768/375px: no viewport overflow or console errors; screenshots visually inspected; existing CSS breakpoints reused |

The connected Browser tool reported no available browser. The visual/runtime test used an isolated local Chromium fixture with mock API responses and the real Overview, state hooks and service adapters. Backend integration tests separately exercise real database authorization/data semantics. Temporary fixture files are removed before commit; logs/screenshots remain under `/tmp/m220d1-*` for this work session.

## Local results

Final counts and exact-SHA CI links are reported with the commit delivery. Expected engine-specific SQLite and frontend fixture skips are disclosed. Existing PHPUnit metadata deprecations and Vite's large-chunk advisory remain; unrelated lint debt is outside scope.

- Targeted frontend: 179 passed.
- Targeted backend regression: 220 passed, 2 MySQL-specific skips on SQLite (1,229 assertions).
- Rendered UI: PASS (six presets, custom validation, shared refresh, account/branch persistence, timezone switch, delayed response, 1440/768/375px).
- Full backend MySQL (isolated local MySQL 9.7.1/InnoDB, PHP 8.2): **555 passed / 2,823 assertions**. Exact-SHA target CI supplies MySQL 8 verification.
- Full backend SQLite: **546 passed / 2,789 assertions**, 9 engine-specific skips.
- Full frontend (`node --test`, including migration rehearsals): **650 passed**, 2 skips, 0 failures.
- Frontend build: PASS. Changed-file ESLint, PHP lint (9 files), Pint (9 files), and `git diff --check`: PASS.
- All **36** pre-existing modified/untracked files retain their original SHA-256 hashes.
- Database CI also runs frontend tests/build; there is no separate frontend workflow in this repository.

## Invariants

OVERVIEW_HAS_PERIOD_SELECTOR=YES
DEFAULT_PERIOD=THIS_MONTH
SUPPORTED_PERIODS=Today,This Week,This Month,Last Month,This Year,Custom Range
OVERVIEW_USES_SHARED_PERIOD_CONTRACT=YES
DUPLICATE_PERIOD_ENGINE_ADDED=NO
ALL_PERIOD_SENSITIVE_METRICS_SCOPED=YES
CUSTOM_RANGE_BOUNDED=YES
ACCOUNT_SWITCH_SAFE=YES
BRANCH_SWITCH_SAFE=YES
STALE_RESPONSE_SAFE=YES
MACHINE_COST_PERIOD_REGRESSION=PASS
REPORT_PERIOD_REGRESSION=PASS
M2_20B_ISOLATION_PRESERVED=YES
M2_20C_CAPABILITY_PRESERVED=YES
M2_20D_AUDIT_PRESERVED=YES
PRODUCTION_CHANGED=NO
DATABASE_PRODUCTION_CHANGED=NO
PUBLIC_HTML_CHANGED=NO

M2.20A–D and this milestone remain pending eventual reviewed combined Production deployment. Stop after CI; M2.20E — Account Owner Administration is only a recommended next milestone and is not started.
