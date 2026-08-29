# M2.7C — Global Branch Context Reconciliation

## Contract

The Branch selected in the application top bar is the canonical operational projection for the selected Account. Operational pages do not maintain a second Branch selector and do not interpret broad authorization as permission to aggregate all accessible Branches.

Authorization scope and projection scope are deliberately separate:

- Authorization scope determines which Accounts and Branches the caller may access.
- Projection scope is the single global Branch currently selected by the caller.
- A Platform Superuser may access every Branch, but normal operational reads still require and honor one selected Branch.
- A Branch with no operational evidence renders an empty or zero state. It never falls back to another Branch, an Account-wide aggregate, or a stale child selection.

The existing tenant shell remains the sole source for the selected Account and Branch. No page-local Branch context was added.

## Branch-switch invalidation

The existing shell context already invalidates the established Overview, Machines, Daily, Machine Cost, and Errors projections. M2.7C applies the same rule to Components, Inventory, and Reports:

- loads include the global `branch.id` dependency;
- the previous projection is hidden immediately when its recorded Branch differs from the shell Branch;
- asynchronous Reports responses are sequence-guarded so an older Branch response cannot replace a newer projection;
- persisted child identifiers are retained only when they belong to the new Branch;
- invalid Machine, Location, purchasing, receipt, and movement draft state is cleared or re-keyed by Branch;
- the Inventory Movements panel is keyed by the global Branch so its local Location/type/detail filters cannot survive into another Branch;
- a browser reload is not required.

## Components

Components keeps its established domain boundary:

- Component Catalog is Account-wide reusable master data.
- Model Profiles are Account/model-level master data.
- Machine Components, initialization, lifecycle, health, and replacement evidence are operational data scoped through `machines.branch_id`.

The Machine Components selector now loads Machines for the selected Account and global Branch. A stale Machine selection is cleared when the Branch changes. Existing safe single/first-Machine selection behavior can select only from the already Branch-scoped collection. If Graha has no Machines, no Machine is selected or queried and the page reports `No active machines in Graha.` The Model Profiles and Component Catalog tabs remain populated from Account master data.

## Inventory

### Domain ownership

Inventory Item/SKU, Component, and Supplier definitions remain Account-wide master data. Physical operational evidence is Branch-owned:

- Inventory Locations are owned by their existing `inventory_locations.branch_id` relationship.
- Stock balances and FIFO layers project through the Location Branch.
- Ordinary movements project through their Location Branch.
- A transfer remains one immutable ledger event. It is visible as outbound history for the source Branch and inbound history for the destination Branch; no movement is duplicated.
- Purchases now store an explicit immutable `inventory_purchases.branch_id` and receiving must use a Location in the same Branch.
- Receipts, receipt lines, purchase lines, price history, and purchasing projections derive their Branch from the Purchase and destination Location relationships.

The Stock screen follows the existing item-ledger architecture: Account Item masters may remain listed, but totals include only balances in the selected Branch. An empty Branch therefore shows zero physical quantity rather than Tuparev stock.

### Mutations and safety

Location creation is forced to the global Branch. Openings, adjustments, receipts, and other Location-based mutations accept only Branch-compatible Locations. Purchases require the selected Branch, and receiving validates that the destination Location has the same Branch as the Purchase. Cross-Account and cross-Branch tampering is rejected in the database.

Location insert/update/delete policies require access to the Location Branch. Database mutation guards apply the same check to opening and adjustment Locations, both transfer endpoints, and replacement-consumption Locations. A cross-Branch transfer remains possible only when the caller can access both Branches and the selected operational PIC is assigned to both; its established paired ledger rows and FIFO cost transfer remain unchanged. Once a Location has movement or receipt evidence, its Branch cannot be reassigned because that would rewrite the meaning of immutable history.

M2.7C changes projection and authorization boundaries only. It preserves immutable movements and receipts, FIFO allocation, replacement consumption, Machine Cost evidence, and unknown-cost semantics.

### DEV audit and backfill

The pre-migration DEV audit found one Inventory Location (`CG_DIGITAL`) and it was already assigned to Tuparev. There were no legacy Locations with a null `branch_id`, so no Location backfill is necessary.

Purchases previously lacked direct Branch ownership. The forward-only migration adds nullable `branch_id` for legacy compatibility and deterministically backfills a Purchase only when all of its immutable receipts resolve to exactly one non-null Location Branch. The two existing DEV Purchases both receive into the Tuparev Location and therefore deterministically resolve to Tuparev. The migration does not rewrite movement or receipt history, assign anything to Graha, or fabricate stock.

## Reports

Reports consumes the global shell Branch. Its internal Branch dropdown and normal `All Branches` mode were removed. The filter hierarchy is now:

`Global Branch → optional Machine in that Branch → Period / Date Range`

The Machine selector loads only Machines in the selected Branch and is disabled when none exist. Legacy persisted Reports `branchId` is removed while unrelated Machine/period/date preferences are preserved; an invalid Machine is cleared by the existing filter normalization.

Every operational report projection receives the selected Branch: Overview, Machine Performance, Machine Economics, Machine Comparison, Component Consumption, Error/Waste, and Inventory/Purchasing. Database report scope resolution requires a non-null Branch, verifies that it belongs to the Account, checks caller access, and constrains an optional Machine to that Branch. This rule also applies to Platform Superusers. Purchase reporting now has a Branch-required RPC signature; its older Account-wide signature is not executable by application roles.

Future consolidated multi-Branch management reporting must be a separate explicit product mode. It is not part of the current operational Reports page.

## Temporary Settings policy

Settings is temporarily Platform-Superuser-only. The shell navigation and direct `/settings` route use the explicit immutable Platform Superuser privilege from M2.7B. Owner, Admin, Technician, and Operator do not see Settings and are denied before Settings content renders.

Database tenant-governance authority now requires an active explicit Platform Superuser for workspace changes, Branch governance, membership provisioning/role/status, permission policy, PIC governance, Machine Model administration, and Advanced Settings. It is not inferred from username, email, display name, or Owner role. Operational permissions outside Settings retain their existing role and Branch rules.

The Owner role and the M2.7B onboarding architecture remain intact for future self-service policy changes. M2.7D credential and direct-active provisioning work is intentionally excluded.

## Graha empty-state acceptance

Graha is intentionally empty. M2.7C does not create Machines, Locations, stock, movements, Purchases, counters, errors, lifecycle events, replacements, or cost evidence for it. With Graha selected:

- operational pages render zero/empty projections;
- Machine selectors never expose `CG-TUP-A3-01`;
- Inventory never projects Tuparev physical evidence;
- Reports never project Tuparev clicks, component consumption, or error/waste values;
- Component Catalog, Model Profiles, and Inventory Item definitions remain available where their Account-master contract applies.

Tuparev evidence and values remain unchanged when Tuparev is selected.

## Migration and test coverage

Migration `20260829000300_global_branch_context.sql` adds Purchase Branch ownership and enforcement, branch-required report purchasing scope, selected-Branch report enforcement, physical Inventory RLS reconciliation, and the temporary Platform-Superuser-only Settings authority.

Regression coverage includes Components Branch switching and master-data preservation, Inventory item-versus-physical-evidence projection, Reports global Branch and legacy-state behavior, Settings navigation/route policy, Branch-scoped database reporting and purchasing, suspended-user denial, Account isolation, cross-Branch tampering, and explicit Platform Superuser governance.
