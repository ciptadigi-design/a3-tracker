# M2.20C — Effective Capability Contract Report

## Baseline and protection

- STARTING_SHA=58b1bc33e42fe9beddb36e935a338b575a7e8674
- CURRENT_BRANCH=develop
- HEAD_SHA / ORIGIN_DEVELOP_SHA at start=58b1bc33e42fe9beddb36e935a338b575a7e8674
- WORKTREE_CLEAN=NO
- PREEXISTING_MODIFIED_FILES=3; PREEXISTING_UNTRACKED_FILES=33
- Exact paths/statuses/SHA-256 hashes: [preservation manifest](evidence/m2-20c-preexisting-files.json). All preserved; no reset, clean, stash or discard.
- Remote develop was checked again before commit and still matched the starting SHA.
- FINAL_SHA / COMMIT_SHA=the commit containing this report; exact SHA and exact-SHA CI links are delivered in the completion response. CI necessarily runs after this report is committed.

## Current capability sources (starting SHA)

| Source | Classification | Finding / disposition |
|---|---|---|
| AccountAccessResolver | BACKEND_AUTHORITY | Active membership; owner governance; owner/admin operational management; global versus account catalog scope. Management/governance wrappers now delegate to the capability service. |
| BranchAccessResolver, MachineAccessResolver | BACKEND_AUTHORITY / RESOURCE_AUTHORIZATION | Active account/branch, active membership and assignment (owner all-branch scope); machine/account/branch consistency and active machine for writes. Preserved. Platform privilege alone does not satisfy these resolvers. |
| PlatformPrivilegeService, platform.manage Gate | BACKEND_AUTHORITY / INTENTIONAL_ROLE_RULE | Independent active platform privilege. Platform account administration, manufacturer administration and operational-person administration remain platform-only. |
| GovernanceController, MemberLifecycle, ProvisionMember, membership model protections | BACKEND_AUTHORITY | Owner/member and branch governance; separate platform identity boundaries and last-owner protection. No role/tenancy redesign. |
| ComponentsController | BACKEND_AUTHORITY / DUPLICATED_LOGIC | Account management for configuration; lifecycle initialization previously required only machine scope. Initialization now capability-gated. |
| InventoryController / canAccessLocation | BACKEND_AUTHORITY / DUPLICATED_LOGIC | Owner/admin checks blocked delegated purchase/receipt/adjust/transfer; external replacements had only machine scope; inventory replacements also required management at their location. Action capability now precedes retained resource checks. |
| OperationalIncidentService / IncidentsController | BACKEND_AUTHORITY / DUPLICATED_LOGIC | Creation ignored policy. Mutation already owner/admin and branch-scoped. Both now use explicit capabilities. |
| MachineCostController | BACKEND_AUTHORITY / STALE_SECURITY_LOGIC | Financial create/void relied on machine scope alone. Fixed for both selling prices and operating costs. |
| CorrectCounterReading, CreateCounterReading, ClickTargetController, ReportsController | BACKEND_AUTHORITY | Existing authority and resource restrictions preserved with capability checks. |
| account_operational_permissions migration and GovernanceController | DISPLAY_ONLY at baseline | Seven persisted switches, none consulted for Laravel action authorization. Now interpreted centrally. |
| Laravel tenant adapter | STALE_LOGIC | Hardcoded permissions: [] removed. /me capability map is consumed. |
| TenantProvider | FRONTEND_PRESENTATION | Previously selected raw policy rows; now selects an authenticated user/account capability map and refreshes safely. |
| Inventory, Components, Errors, MachineCost, Daily, Machines, ClickTarget pages; incident/machine detail | FRONTEND_PRESENTATION / DUPLICATED_LOGIC | Role/policy action assumptions replaced by backend capability consumption. Technician incident-resolution affordance also aligned with existing backend denial. |
| Settings permission matrix | DISPLAY_ONLY / DUPLICATED_LOGIC | Fixed role cells now come from backend capability_matrix. Switches show persisted policy. Minimal copy/scope correction; no Settings redesign. |
| Sidebar/AppShell | FRONTEND_PRESENTATION / INTENTIONAL_ROLE_RULE | Existing temporary platform-only Settings release gate retained. Capability checks additionally guard Settings and relevant navigation. Role labels remain labels. |
| Supabase SQL has_operational_capability and Supabase adapter | HISTORICAL_BACKEND_AUTHORITY / OUT_OF_SCOPE | Historical source of delegation semantics, not the Production Laravel authorization path. No frontend imitation of these rules was added. Legacy adapters without the new payload fail closed. |
| Deferred Advanced Economics/intelligence and unsupported adapter operations | DEAD_CODE / OUT_OF_SCOPE for launch | No enabling, policy migration, operating-cost backfill or feature expansion. |

CURRENT_CAPABILITY_SOURCES=the inventory above. Post-change security search found no remaining same-class F06 role/policy action duplication in covered frontend pages. Remaining role comparisons establish membership lifecycle/last-owner/platform identity boundaries, branch-owner scope, role labels, or Settings member-form validation. AccountAccessResolver compatibility wrappers retain identical management tiers and call the resolver, rather than maintaining separate role lists. Global catalog authorization still uses PlatformPrivilegeService, independently of account role.

## Account policy reconstruction and precedence

Persisted table: `account_operational_permissions`. Laravel migration: `2026_08_31_001300_complete_frontend_parity.php`. All seven columns default to **false**, including when the row is absent. Historical Supabase defaults for replacement/errors were true; these are not substituted for current Laravel defaults.

| POLICY_KEY | CURRENT_UI_LABEL | Baseline backend usage | Baseline frontend usage | DEFAULT_VALUE | INTENDED_ROLE_SCOPE |
|---|---|---|---|---|---|
| operator_can_initialize_component | Initialize component lifecycle | Settings read/write only | Operator lifecycle control | false | Operator delegation |
| operator_can_replace_component | Replace component | Settings read/write only | Operator replacement control | false | Operator delegation, both sources |
| operator_can_create_purchase | Create Purchase | Settings read/write only | Operator purchase control | false | Operator delegation |
| operator_can_receive_goods | Receive goods | Settings read/write only | Operator receipt control | false | Operator delegation |
| operator_can_adjust_inventory | Inventory adjustment | Settings read/write only | Operator adjustment control | false | Operator delegation |
| operator_can_transfer_inventory | Inventory transfer | Settings read/write only | Operator transfer control | false | Operator delegation |
| operator_can_log_errors | Log Errors | Settings read/write only | Operator incident control | false | Operator delegation |

ACCOUNT_POLICY_KEYS=exactly the seven keys above. No persisted account counter-recording/correction switch exists. `can_record_counter` on operational-person branch assignments describes PIC eligibility, not authenticated membership authority. No intentional financial-write delegation switch exists. Advanced Economics is deferred and has no corresponding current Laravel persisted account policy field; its unsupported UI path is not a delegation switch.

The historical SQL in `20260829000200_account_provisioning_branch_scope.sql`, function `has_operational_capability`, agrees with the existing Settings matrix: Owner/Admin get all seven actions; Technician gets replacement/errors only; Operator gets the applicable switch. This evidence resolves the intended contract without inventing policy names.

- POLICY_PRECEDENCE_RULE=roles establish fixed authority; existing switches delegate only their named operational actions to Operators. They cannot grant governance, identity, global catalogs, configuration, counter correction, incident mutation, financial writes or platform powers.
- OWNER_POLICY_BEHAVIOR=unaffected operational baseline; all seven delegated actions available.
- ADMIN_POLICY_BEHAVIOR=unaffected operational baseline; all seven available; resource assignment still required.
- TECHNICIAN_POLICY_BEHAVIOR=replacement and incident creation independently of Operator policy; no lifecycle initialization, purchase/receipt/adjust/transfer, financial writes or incident mutation.
- OPERATOR_POLICY_BEHAVIOR=seven existing switches control their mapped actions; recording counters and read access remain available with resource scope.

## Baseline direct-request reproduction

F06_REPRODUCED=YES. F06_CASES_CONFIRMED=C01–C12. F06_CASES_ALREADY_CLOSED=NONE.

The first pre-implementation run had 11 failures/10 passes (41 assertions). A separate archive of the exact starting commit, with only the new test file added, revalidated the expanded suite: 17 failing contract tests, 11 non-failing cases (52 assertions). Some expanded cases intentionally fail because the resolver does not yet exist. The C01–C12 failures below are actual HTTP/bootstrap mismatches, not missing-class failures.

| Case | Baseline observation |
|---|---|
| C01 | Operator lifecycle initialization policy OFF returned 201. |
| C02 | Operator incident creation policy OFF returned 201. |
| C03 | Operator external replacement policy OFF returned 201. |
| C04–C07 | Technician/Operator financial creates returned 201 despite expected denial; source inspection also confirmed the same broad create/void authorization tier. Post-fix tests exercise both creates and voids. |
| C08 | Operator purchase policy ON returned 403. |
| C09 | Operator receipt policy ON returned 403. |
| C10 | Operator adjustment policy ON returned 403. |
| C11 | Standalone authorized-destination transfer with policy ON returned 403. |
| C12 | /me lacked capabilities; adapter returned permissions: []. |

Fixtures use synthetic Accounts A/B, two A branches, one B branch, machines/components/items/locations and all four account roles. Account codes are milestone-specific to coexist with existing MySQL concurrency fixtures. No customer or Production data was used.

## Current role × action matrix (before fix)

Y=coarse role tier permits the action; scope in the last column must also pass. P=platform privilege without account membership. P branch/machine writes still require applicable membership and assignment.

| ACTION | PLATFORM | OWNER | ADMIN | TECHNICIAN | OPERATOR | Existing resource requirement |
|---|---|---|---|---|---|---|
| ACCOUNT_GOVERNANCE (account record create/update) | Y | N | N | N | N | Platform Gate |
| MEMBER_MANAGEMENT / BRANCH_MANAGEMENT / POLICY_MANAGE / SETTINGS_VIEW API | Y | Y | N | N | N | Account; identity transitions have additional M2.20A rules |
| MACHINE_MANAGEMENT / COMPONENT_CONFIGURE | Y | Y | Y | N | N | Persisted account management scope |
| CATALOG_MANAGEMENT | Y | Account-owned | Account-owned | N | N | Persisted catalog owner; global needs platform |
| SUPPLIER_MANAGEMENT / INVENTORY_ITEM_MANAGEMENT / LOCATION_MANAGEMENT | Y | Y | Y | N | N | Account; branch-linked reference checks |
| COUNTER_RECORD | Y | Y | Y | Y | Y | Active authorized machine and eligible counter PIC |
| COUNTER_CORRECT | Y | Y | Y | N | N | Authorized machine and effective reading |
| PURCHASE_CREATE | Y | Y | Y | N | N | Account; selected branch authorization if supplied |
| PURCHASE_RECEIVE / INVENTORY_OPENING / INVENTORY_ADJUST / INVENTORY_TRANSFER | Y | Y | Y | N | N | Location account/branch; transfer both; receipt purchase branch |
| COMPONENT_LIFECYCLE_INITIALIZE | Y | Y | Y | Y | Y | Active authorized machine |
| REPLACEMENT_INVENTORY | Y | Y | Y | N | N | Active authorized machine AND management-scoped inventory location |
| REPLACEMENT_EXTERNAL | Y | Y | Y | Y | Y | Active authorized machine |
| INCIDENT_CREATE | Membership | Y | Y | Y | Y | Persisted active branch/membership; optional machine in branch |
| INCIDENT_MUTATE | Y | Y | Y | N | N | Incident's authorized branch |
| REPORT_VIEW | Y | Y | Y | Y | Y | Authorized branch set and selected machine filters |
| CLICK_TARGET_MANAGE | Y | Y | Y | N | N | Active authorized machine |
| MACHINE_COST_VIEW | Y | Y | Y | Y | Y | Authorized machine |
| SELLING_PRICE_MANAGE / OPERATING_COST_MANAGE | Y | Y | Y | Y | Y | Active authorized machine only (F06) |

## Effective contract and final matrix

EFFECTIVE_CAPABILITY_ALGORITHM=read persisted user/account status and current membership; inactive user/account or non-active existing membership yields all false. Resolve fixed role plus the seven persisted booleans. Active platform privilege grants all coarse capabilities in an active tenant context unless its existing membership is inactive. No membership is synthesized for resource access. Each protected mutation independently resolves action authority and retains persisted resource authorization and domain validation. No capability payload from the client is trusted; no cross-request permission cache exists.

CAPABILITY_RESPONSE_ENDPOINT=GET /api/v1/me (existing bootstrap).

CAPABILITY_RESPONSE_SHAPE=`data.capabilities[account_id][stable.action.key] = boolean`. Only active membership accounts already returned by /me are included. Suspended/revoked/inactive contexts are absent; direct resolver calls return all false. No duplicate permissions list is emitted. Settings additionally returns `data.capability_matrix[role][key]`, computed with the same role/policy algorithm for presentation.

All final matrix rows presume an active user, account and applicable membership. POLICY=the named switch, not unrestricted YES.

| ACTION / capability | OWNER | ADMIN | TECHNICIAN | OPERATOR | POLICY_KEY | RESOURCE_SCOPE |
|---|---|---|---|---|---|---|
| ACCOUNT_GOVERNANCE / account.manage | N | N | N | N | NONE | Platform-only account administration |
| GLOBAL_CATALOG / catalog.global.manage | N | N | N | N | NONE | Platform-only global catalog |
| MEMBER_MANAGEMENT / members.manage | Y | N | N | N | NONE | Persisted account + identity/last-owner boundaries |
| BRANCH_MANAGEMENT / branches.manage | Y | N | N | N | NONE | Persisted account / branch relationship |
| MACHINE_MANAGEMENT / machines.manage | Y | Y | N | N | NONE | Persisted account management + scoped model/manufacturer |
| CATALOG_MANAGEMENT / catalog.manage | Y | Y | N | N | NONE | Account-owned record; shared records use global privilege |
| SUPPLIER_MANAGEMENT / suppliers.manage | Y | Y | N | N | NONE | Account and branch-linked supplier references |
| INVENTORY_ITEM_MANAGEMENT / inventory.items.manage | Y | Y | N | N | NONE | Account and compatible catalog scope |
| INVENTORY_LOCATION_MANAGEMENT / inventory.locations.manage | Y | Y | N | N | NONE | Account and branch relationship |
| COUNTER_RECORD / counters.record | Y | Y | Y | Y | NONE | Authorized active machine, eligible PIC |
| COUNTER_CORRECT / counters.correct | Y | Y | N | N | NONE | Authorized machine + effective reading |
| PURCHASE_CREATE / inventory.purchase.create | Y | Y | N | POLICY | operator_can_create_purchase | Authorized selected branch; delegated purchase requires branch; existing management may create account-wide purchase |
| PURCHASE_RECEIVE / inventory.receive | Y | Y | N | POLICY | operator_can_receive_goods | Authorized active destination AND purchase's branch, same account |
| INVENTORY_OPENING / inventory.opening | Y | Y | N | N | NONE | Authorized active location and same-account item |
| INVENTORY_ADJUST / inventory.adjust | Y | Y | N | POLICY | operator_can_adjust_inventory | Authorized active location and same-account item |
| INVENTORY_TRANSFER / inventory.transfer | Y | Y | N | POLICY | operator_can_transfer_inventory | Both active locations authorized; item/locations same account |
| COMPONENT_CONFIGURE / components.configure | Y | Y | N | N | NONE | Persisted machine's account management scope |
| COMPONENT_LIFECYCLE_INITIALIZE / components.lifecycle.initialize | Y | Y | N | POLICY | operator_can_initialize_component | Authorized active machine, eligible component state |
| REPLACEMENT_INVENTORY / components.replace.inventory | Y | Y | Y | POLICY | operator_can_replace_component | Authorized active machine AND active location; same-account compatible item |
| REPLACEMENT_EXTERNAL / components.replace.external | Y | Y | Y | POLICY | operator_can_replace_component | Authorized active machine; external reason required |
| INCIDENT_CREATE / incidents.create | Y | Y | Y | POLICY | operator_can_log_errors | Authorized branch; optional active machine in that branch |
| INCIDENT_MUTATE / incidents.manage | Y | Y | N | N | NONE | Incident's authorized branch and applicable incident state |
| REPORT_VIEW / reports.view | Y | Y | Y | Y | NONE | Authorized branch set, machine/account filter consistency |
| CLICK_TARGET_MANAGE / click_targets.manage | Y | Y | N | N | NONE | Authorized active machine |
| MACHINE_COST_VIEW / machine_cost.view | Y | Y | Y | Y | NONE | Authorized machine |
| SELLING_PRICE_MANAGE / machine_cost.selling_price.manage | Y | Y | N | N | NONE | Authorized active machine; persisted price's machine for void |
| OPERATING_COST_MANAGE / machine_cost.operating_cost.manage | Y | Y | N | N | NONE | Authorized active machine; persisted cost's machine for void |
| SETTINGS_VIEW / settings.view | Y | N | N | N | NONE | Account; existing UI release gate still platform-only |
| POLICY_MANAGE / settings.policy.manage | Y | N | N | N | NONE | Account; existing UI release gate still platform-only |

FINAL_CAPABILITY_MATRIX=above. PLATFORM_SUPERUSER=all coarse keys true under the stated active-context rule; operational branch/machine checks are still mandatory. Account-wide catalog/configuration governance and branchless account-wide locations retain their established account-level scope; no new membership bypass was introduced. Platform administration Gate remains separate from tenant action capabilities.

## Frontend and refresh safety

FRONTEND_CAPABILITY_SOURCE=BACKEND. HARDCODED_EMPTY_PERMISSIONS_REMOVED=YES.

TenantProvider ties maps to authenticated user ID and selected account ID. Account switches immediately clear tenant/branch state and trigger a new bootstrap; account maps are replaced, never merged. Superseded requests cannot publish data, errors or loading completion. User changes reject the prior user's context before rendering. Page content is already keyed to account/branch, closing old account workflows on transition. Missing maps or non-boolean values deny presentation; no local role/policy fallback exists.

CAPABILITY_REFRESH_BEHAVIOR=bootstrap on session load, explicit tenant refresh and selected-account changes; successful policy mutation refreshes tenant context and Settings matrix. Other open sessions receive updated UI on their next context refresh; backend authorization consults current persisted policy immediately, without logout/login. No realtime system was introduced.

Settings fixed cells now represent the backend role matrix; Operator controls represent actual persisted delegation. Catalog copy distinguishes account-owned governance. The temporary platform-only Settings navigation/page gate is preserved as an intentional release restriction; no H6/Owner Settings expansion occurred. Relevant components/inventory/financial/counter/incident/machine/click-target controls use `can(key)`; this function checks an explicit backend boolean only.

## Verification and security invariants

Direct API coverage includes C13–C40: OFF denial and ON success for authorized lifecycle, incident, external replacement, purchase, receipt, adjustment and transfer; both financial create AND void by all four roles; scope denials for other branches/machines/accounts/locations; both transfer endpoints; receipt destination and purchase branch; inventory replacement delegation; inactive membership/account; platform without membership; policy mutation refresh; selective policy; complete coarse role matrix.

- M2_20C_TARGETED_BACKEND_TESTS=PASS, 29 tests / 263 assertions.
- CAPABILITY_MATRIX_TESTS=PASS (same targeted suite).
- M2_20A_SECURITY_REGRESSION=PASS, included in full MySQL and SQLite runs. F01/F07 remain closed.
- M2_20B_ISOLATION_REGRESSION=PASS, included in full MySQL and SQLite runs. F02/F03/F04/F05 remain closed. Existing A/B tests were not modified. The dedicated SQLite A/B run passed 63 tests / 323 assertions with 5 MySQL-only skips; the full MySQL run executes those concurrency tests.
- FULL_BACKEND_MYSQL=PASS, 525 tests / 2449 assertions, dedicated local `a3_tracker_m220c_test`, MySQL 9.7.1. Required MySQL 8 execution is also required from exact-SHA CI.
- FULL_BACKEND_SQLITE=PASS, 516 tests / 2415 assertions, 9 MySQL-specific skips.
- M2_20C_FRONTEND_TESTS=PASS, 14 account tests, including direct Laravel adapter transport, strict boolean presentation, financial-control wiring, missing payload, account/user switch, out-of-order success/error completion.
- FULL_FRONTEND_TESTS=PASS, `node --test`: 636 passed, 2 skipped (638 total).
- FRONTEND_BUILD=PASS, explicit `VITE_DATA_BACKEND=laravel VITE_API_BASE_URL=/api/v1 npm run build`.
- LINT=changed JS/JSX files PASS; changed PHP syntax PASS. Repository-wide `npm run lint` was run and fails with 702 errors/1 warning, dominated by backend/vendor and unrelated existing issues; no unrelated lint cleanup was performed.
- FORMAT=changed PHP Pint PASS; git diff --check PASS. No unrelated files were formatted.
- Existing PHP metadata deprecations and Vite large-chunk advisory remain. Local verification uses PHP 8.4 explicitly; PHP 8.5 produces pre-existing vendor PDO constant deprecation notices.
- DATABASE_CI_RUN / MYSQL_TARGET_CI_RUN / FRONTEND_CI_RUN=pending exact-SHA execution after push; final completion response supplies run IDs and outcomes. Database CI includes frontend tests/build; there is no separate frontend workflow.

BACKEND_CAPABILITY_AUTHORITATIVE=YES
FRONTEND_CAPABILITY_SOURCE=BACKEND
POLICY_OFF_DIRECT_API_DENIED=YES
POLICY_ON_DIRECT_API_ALLOWED_ONLY_WHEN_RESOURCE_AUTHORIZED=YES
LIFECYCLE_POLICY_ENFORCED=YES
INCIDENT_POLICY_ENFORCED=YES
EXTERNAL_REPLACEMENT_POLICY_ENFORCED=YES
PURCHASE_POLICY_ENFORCED=YES
RECEIVING_POLICY_ENFORCED=YES
ADJUSTMENT_POLICY_ENFORCED=YES
TRANSFER_POLICY_ENFORCED=YES
TECHNICIAN_SELLING_PRICE_WRITE=DENIED
OPERATOR_SELLING_PRICE_WRITE=DENIED
TECHNICIAN_OPERATING_COST_WRITE=DENIED
OPERATOR_OPERATING_COST_WRITE=DENIED
OWNER_FINANCIAL_WRITE=ALLOWED_WITH_RESOURCE_SCOPE
ADMIN_FINANCIAL_WRITE=ALLOWED_WITH_RESOURCE_SCOPE
POLICY_CANNOT_BYPASS_BRANCH_SCOPE=YES
POLICY_CANNOT_BYPASS_MACHINE_SCOPE=YES
POLICY_CANNOT_BYPASS_TENANT_SCOPE=YES
BRANCH_AUTHORIZATION_PRESERVED=YES
TENANT_ISOLATION_PRESERVED=YES
IDENTITY_BOUNDARY_PRESERVED=YES
MULTI_ACCOUNT_CAPABILITY_SWITCH_SAFE=YES
STALE_CAPABILITIES_ON_ACCOUNT_SWITCH=NO
SUSPENDED_MEMBERSHIP_CAPABILITIES=NONE
REVOKED_MEMBERSHIP_CAPABILITIES=NONE
SCHEMA_CHANGE_REQUIRED=NO
MIGRATIONS_CREATED=NONE
CUSTOM_RBAC_ADDED=NO
PUBLIC_SIGNUP_ADDED=NO
PREEXISTING_FILES_PRESERVED=YES

## Deployment decision — plan only

PRODUCTION_CHANGED=NO
DATABASE_PRODUCTION_CHANGED=NO
PUBLIC_HTML_CHANGED=NO
PRODUCTION_SHA=a51c81f74af8e463d7676a4f7536e0bfb3506f5f (user-provided baseline; no Production connection/mutation performed)
M2_20A_DEPLOYMENT_PENDING=YES
M2_20B_DEPLOYMENT_PENDING=YES
M2_20C_DEPLOYMENT_REQUIRED=YES
A_B_C_COMBINED_DEPLOYMENT_RECOMMENDED=YES
SCHEMA_MIGRATIONS_PENDING=2026_09_13_000100_add_user_session_version.php (M2.20A); none from C.
AUTH_BEHAVIOR_CHANGE_RISK=MEDIUM: session-version enforcement/identity transitions from A, scope restrictions from B, lower-role denial and explicit Operator delegation from C. Audit current policy values before release; preserve Laravel false defaults, do not silently grant permissions.
UI_BEHAVIOR_CHANGE_RISK=MEDIUM: unavailable actions disappear, formerly decorative grants become usable, Technician financial and lifecycle controls remain denied; frontend and backend must deploy together because old backend lacks capabilities and new frontend fails closed.
ROLLBACK_COMPLEXITY=MEDIUM: restore coordinated backend/frontend release pointers; session-version migration can remain as an additive column. Reverting authorization fixes reopens A/B/C findings and should be an explicit security decision. Do not drop session-version data automatically.

Proposed combined release, requiring separate review/authorization:

1. Confirm the approved exact A+B+C release SHA and green MySQL 8/Database CI, take normal database/config/release backups, inspect live migration status and policy values read-only.
2. Stage the matching Laravel backend and Laravel-mode frontend. Preserve current environment and tenancy data. Apply the pending additive session-version migration using the established deployment workflow.
3. Activate backend/frontend as one coordinated security release; use the existing session/CSRF handling and avoid a mixed-version interval.
4. Smoke-test representative Owner/Admin/Technician/Operator accounts, policy OFF/ON, selected-account switching, branch denials and revoked sessions. Confirm version endpoint/build manifest match the approved SHA.
5. Monitor authorization errors and follow the coordinated rollback plan only if needed. No release action in this plan has been executed here.

F06_STATUS=CLOSED in implementation and local regression evidence; milestone acceptance additionally requires exact-SHA green CI.
M2_20C_STATUS=local verification PASS; exact-SHA CI is the final gate reported at completion.
EXTERNAL_PILOT_READINESS_AFTER_M2_20C=NOT_READY (M2.20D–G remain).
RECOMMENDED_NEXT_MILESTONE=M2.20D — Minimum Administrative Audit Trail, after user review. Not started.

No Production deployment, main/production-old changes, Owner Settings expansion, TONER_M/TONER_Y change, audit-trail work or timezone/report correctness work was performed.
