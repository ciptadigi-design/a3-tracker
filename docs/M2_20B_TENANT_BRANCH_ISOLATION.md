# M2.20B — Tenant / Branch + Related-Reference Isolation Closure

M2.20B closes F02–F05 through resource-bound replay comparisons, server-resolved report scope, tenant-safe catalog references, and persisted branch authorization for operational writes. No role redesign, schema migration, frontend source change, or Production action is included.

## Recovery audit

- CURRENT_BRANCH=develop
- HEAD_SHA=872f3d3e1872dda398695d8790962c0c9dee13e1 (at recovery)
- ORIGIN_DEVELOP_SHA=872f3d3e1872dda398695d8790962c0c9dee13e1 (at recovery)
- WORKTREE_CLEAN=NO
- CURRENT_MODIFIED_FILES=17 at recovery: 14 M2.20B PHP files and 3 unrelated documents.
- CURRENT_UNTRACKED_FILES=36 at recovery: 3 M2.20B PHP files and 33 unrelated files.
- M2_20B_PARTIAL_FILES=17; all reused, no partial files discarded.
- PREEXISTING_UNRELATED=36; UNKNOWN=0.

The complete path classification and original unrelated hashes are in [recovery evidence](evidence/m2-20b-recovery.json). All 36 hashes matched the interrupted session's `/tmp/m2-20b-preexisting-manifest.json`, which also matches the M2.20A manifest. They were checked again before commit. No reset, checkout, stash, clean, or discard was performed.

Every recovered PHP file compiled. All were treated as **partial**, including the new test suite. The recovered suite ran before implementation changes: 23 tests, 72 assertions and 5 failures at recovery. Two newly added `abort(422)` calls hit the existing exception renderer's inappropriate `errors()` call; three assertions compared an unrefreshed Eloquent object against persisted data. The new rejection paths now use ValidationException; comparisons use persisted before/after state. Security assertions were retained.

The interrupted session's original red log survives, with 23 failures reproducing F02–F05. Its hash and provenance are recorded in recovery evidence. Recovery did not revert code to reproduce these a second time.

| Recovered file (under backend/) | Purpose and security intent | Recovery problems / completion |
| --- | --- | --- |
| app/Http/Controllers/Api/ClickTargetController.php | Bind calendar retry to machine/date/type/notes | Retained; exact and changed retries verified |
| app/Http/Controllers/Api/ComponentsController.php | Validate manufacturer and slot component references | Retained; global/owned cases and historical reads covered |
| app/Http/Controllers/Api/IncidentsController.php | Prevent incident association outside persisted branch | Replaced broken HTTP 422 rejection with ValidationException |
| app/Http/Controllers/Api/InventoryController.php | Purchase/receipt branch checks and item reference scope | Added location→branch tenant validation; both receiving resources authorized |
| app/Http/Controllers/Api/MachineCostController.php | Validate operating-cost person | Repaired 422 handling; selling-price note replay comparison added |
| app/Http/Controllers/Api/OperationsController.php | Machine/model/manufacturer reference checks | Retained and complemented by safe catalog read relationships |
| app/Http/Controllers/Api/ReportsController.php | Resolve authorized branches independently of client filters | Retained; explicit machine access precedes branch-filter consistency validation |
| app/Services/ComponentConfigurationService.php | Lifecycle resource and payload binding | Scoped lookup to component, existence-only foreign collision check; correct persisted lock; sync rejects polluted slot references |
| app/Services/CorrectCounterReading.php | Machine branch authorization for correction | Extended retry comparison to target/value/time/reason/notes |
| app/Services/InventoryLedgerService.php | Bind retries to item/location/quantity/PIC/cost | Added movement-kind binding, both transfer legs, account lock for cross-location races |
| app/Services/OperationalIncidentService.php | Incident branch mutation/replay checks | Omitted optional payload fields now compare their defaults, preventing omission bypass |
| app/Services/OperationalReportService.php | SQL branch restrictions on report evidence | Included current and legacy consumption shapes; global-store machine isolation; no full-history PHP scope filtering |
| app/Services/PurchaseReceiptService.php | Purchase and receiving resource/payload validation | Added receipt PIC provenance comparison and account serialization; completed line multiset comparison |
| app/Services/ReplaceMachineComponent.php | Component replacement retry binding | Normalized default quantity and compared free-text performer when no canonical PIC |
| app/Services/ReplayFields.php | Explicit domain-supplied field comparison | Null-sensitive comparison; timestamps compare existing storage precision and domain normalization |
| app/Services/ScopedReference.php | Active global-or-owned catalog validation | Renamed method to `activeGlobalOrOwned`; SQL limits tenant reference lookup; no branch authorization inside helper |
| tests/Feature/M2_20BIsolationTest.php | Synthetic cross-tenant and cross-branch regression suite | Corrected persisted-state fixtures and expanded coverage; no weakened denials |

## Replay contracts

Every HTTP caller still authorizes the target resource. A matching request key never substitutes for authorization. Resource or payload conflicts return 409 without serializing a stored result. Null and empty values are distinct. Numeric database representations compare numerically; timestamps use the existing database's second precision. UTC-normalizing domains pass UTC values; this milestone does not change existing timestamp storage conventions. Generated timestamps and server-derived name snapshots are not compared as client inputs.

| Domain | Lookup boundary and compared request identity |
| --- | --- |
| Lifecycle | Globally unique key: foreign component collision checked by existence only; stored row loaded from authorized component. Component, started date, counters/end date when applicable, source, evidence, notes |
| Incident | Account key; branch, machine, occurrence, category/type/description, selected people, invoice/customer/product, quantities/loss/multiplier, cause/prevention/resolution; omitted fields use defaults |
| Purchase | Account key; branch, supplier, number/date/currency/reference/notes, complete line multiset (item/quantity/cost) |
| Receipt | Account key; purchase, location, complete purchase-line/quantity multiset, PIC from each exact receipt-line movement |
| Inventory inbound/outbound | Account key and movement kind; item, location, quantity, reason, PIC, reference; inbound also cost and explicit occurrence |
| Transfer | Account key; both source and destination movements, item, quantity, PIC; incomplete prior transfer conflicts |
| Replacement | Account key; component, inventory source/item/location/quantity, external reason, notes, performer, explicit replacement date |
| Calendar exception | Account key; machine, date, type, notes |
| Counter create/correct | Account key; machine and selected person/value/time/shift/notes for create; original corrected reading/value/time/reason/notes for correction |
| Selling price | Existing machine/value/effective-date checks retained; notes now compared too |

Inventory's existing uniqueness index includes location. An account-row transaction lock prevents two different locations/items from concurrently claiming the same movement request key. Transfers intentionally retain two movement kinds under one key. Purchasing and receiving use the same account serialization boundary. This can serialize concurrent inventory writes within one account; no report query uses this lock. Existing MySQL same-request and insufficient-stock races remain passing.

## Reports

The controller first verifies account access, then any explicit branch and machine. `authorizedBranchIds()` supplies either null (existing unrestricted authority) or an array, including an empty array. Restricted arrays are intersected with persisted active account branches. Client filters only intersect that scope. The service applies SQL `whereIn` before hydrating machines, incidents, counters, replacements, and consumption. Costs, performance, daily clicks and operator activity derive from scoped evidence. Branch-only incidents are restricted directly by branch. Inconsistent historical incident→machine account/branch links are excluded from report evidence.

All seven report areas are covered: overview, machine performance/cost, counter/daily clicks, operator activity, incidents, component/replacement activity, inventory consumption. Existing range limits and M2.19.1 SQL history bounds remain intact.

Account-global inventory locations are an existing explicit domain rule: `InventoryController::canAccessLocation()` permits null branch, and `OperationalPersonEligibilityService::eligibleOperatorForLocation()` documents account-wide locations. This rule is preserved. Linked consumption must additionally belong to an authorized machine, so a global store does not expose another branch's machine. Explicit branch filters retain their established location-branch narrowing. Both legacy `issue/replacement_consumption` and Laravel `replacement_consumption/component_replacement` movement shapes are recognized. Owner reports retain account-wide scope when filters are omitted.

## References and authority

`ScopedReference::activeGlobalOrOwned()` is limited to catalog attachment validation. Suppliers and people keep their domain-specific tenant and branch eligibility checks. It is not an authorization resolver.

Machine→model, model→manufacturer, catalog→manufacturer, profile-slot→component, inventory-item→component, purchase→supplier, and operating-cost→person now reject foreign private references. Location→branch also requires the same tenant. Profile sync cannot propagate a polluted foreign component reference.

`GlobalOrOwnedBelongsTo` protects catalog reads for machines, models, profiles/slots, machine components and inventory items. Lazy queries apply scope; eager queries constrain allowed tenants and check each parent when matching mixed-account batches. Historical inactive global/owned references remain readable. Foreign private catalog objects are returned as null instead of being serialized. No historical rows are repaired or deleted.

Account-wide master-data authority remains unchanged: machine/catalog/profile configuration and inventory master-data administration remain subject to their established role checks. Branch-sensitive operational actions use existing branch/machine resolvers: purchase creation, receiving (purchase and location), adjustments/transfers/replacements, counter correction, incident edit/resolve/void and incident-machine association. An account-global purchase/location retains existing account semantics.

## Matrix and neighboring search

| Required cases | Coverage |
| --- | --- |
| B01–B03 | Foreign and same-account lifecycle keys; exact retry and changed payload |
| B04–B08 | Incident, receipt, adjustment, replacement and calendar resource mismatch |
| B09–B15 | Omitted/own/foreign branch, foreign machine, owner, branch-only incident, real consumption; empty assignments and all report arrays |
| B16–B21 | Foreign private model/manufacturer/profile component/inventory component/supplier/person |
| B22–B23 | Global and own catalog references; own supplier and eligible person |
| B24–B28 | Purchase branch, both receiving branches, correction, incident edit/resolve/void and machine association |
| B29 | Persisted before/after snapshots including FIFO, receipt lines and incident revisions; partial receiving rollback |
| B30 | Rejection payloads and historical polluted catalog reads; mixed-account eager batches |
| Additional | Exact/changed retries across domains, omitted incident loss, receiving PIC, adjustment sign, transfer destination, counter payloads, global-store machine scope, concurrent cross-location key race |

The security search included `::find(`, `::findOrFail(`, `client_request_id`, `supplier_id`, `machine_model_id`, `manufacturer_id`, `component_catalog_id`, `component_id`, `operational_person_id`, `branch_id`, `machine_id` across backend application code.

- FIXED_IN_M2_20B: counter create/correction payload omissions; selling-price notes; adjustment kind/transfer destination; inventory location foreign branch; profile sync propagation; historical catalog eager/lazy exposure; report current consumption shape and inconsistent incident-machine links.
- SAFE: supplier branch assignment validates tenant; supplier/receipt summaries use account-owned lookup sets; canonical person eligibility requires tenant plus branch; selling-price resource/value/date checks already existed; exclusion/configuration actions bind persisted machine and catalog authority; legacy import is an explicit trusted CLI path, not a tenant retry API; operating-cost creation relies on existing uniqueness and never returns a stored foreign retry result.
- NEW_BLOCKER_NEEDS_DECISION: none identified in this scope. No M2.20C capability redesign undertaken.

## Validation and deployment gate

Final local results and required suite counts are recorded in [validation evidence](evidence/m2-20b-validation.json). All required suites ran, followed by the complete backend suite on SQLite and an isolated local MySQL database. SQLite skips engine-specific tests; MySQL runs them. Three pre-existing PHPUnit metadata deprecations remain. PHP syntax, repository Pint formatting and `git diff --check` pass. No frontend source changed; the existing Database CI workflow also runs frontend tests/build.

Commit only the M2.20B paths. Push develop and require **Database CI** and **Laravel MySQL Target CI** green for that exact commit SHA. The delivery report links those runs; no main merge or deployment is authorized.

- FOREIGN_RETRY_RECORD_RETURNABLE=NO
- CROSS_RESOURCE_RETRY_ACCEPTED=NO
- LEGITIMATE_EXACT_RETRY_PRESERVED=YES
- BRANCH_RESTRICTED_REPORT_EXPANDS_ON_OMITTED_FILTER=NO
- FOREIGN_PRIVATE_REFERENCE_ATTACHABLE=NO
- FOREIGN_PRIVATE_REFERENCE_SERIALIZED=NO
- UNASSIGNED_BRANCH_OPERATIONAL_WRITE=NO
- FAILED_CROSS_SCOPE_WRITE_PARTIAL=NO
- GLOBAL_REFERENCE_SUPPORT_PRESERVED=YES
- OWN_TENANT_PRIVATE_REFERENCE_SUPPORT_PRESERVED=YES
- OWNER_ACCOUNT_WIDE_REPORT_SUPPORT_PRESERVED=YES
- M2_19_1_REPORT_QUERY_SCALABILITY_PRESERVED=YES
- M2_20A_SECURITY_BEHAVIOR_PRESERVED=YES
- PREEXISTING_FILES_PRESERVED=YES
- PREEXISTING_FILE_HASH_MISMATCHES=0
- SCHEMA_CHANGE_REQUIRED=NO
- MIGRATIONS_CREATED=0
- PRODUCTION_CHANGED=NO
- DATABASE_PRODUCTION_CHANGED=NO
- PUBLIC_HTML_CHANGED=NO

Production remains the user-reported `a51c81f74af8e463d7676a4f7536e0bfb3506f5f`; no Production connection was needed. M2.20A deployment remains pending. M2.20B also requires deployment to make its protections effective there. A combined A+B deployment is recommended after review. The only pending migration relative to that Production SHA is `2026_09_13_000100_add_user_session_version.php`; it is unchanged.

Application-level scope is sufficient; intentional global references do not require new database constraints. Existing data is compatible without destructive repair. Invalid historical catalog relationships become unavailable in responses. Authorization behavior risk is moderate: previously accepted cross-scope requests now fail, and restricted reports show less data. Rollback is operationally simple for code, but reverting security fixes reopens the findings; A's additive session-version column can remain in place during code rollback. No migration or rollback was executed in Production.

F02=CLOSED; F03=CLOSED; F04=CLOSED; F05=CLOSED, subject to the exact-SHA CI gate in the delivery report. External pilot readiness remains NOT_READY because M2.20C–G remain. Recommended next milestone: M2.20C — Effective Capability Contract, after user review. It was not started.
