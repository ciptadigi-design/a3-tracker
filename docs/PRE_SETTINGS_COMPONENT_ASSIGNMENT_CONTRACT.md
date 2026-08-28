# Pre-Settings Component Assignment Contract

## Purpose and domain hierarchy

Component Catalog defines reusable logical component types. A Model Profile defines a stable slot template for a machine model. A Machine Component Assignment is the persistent configuration of one physical machine. A Lifecycle records the installed physical component history for that assignment. Inventory Items and SKUs remain a separate stock domain linked to the logical Catalog component.

Profiles are templates, not live machine truth. Lifecycle and replacement history never depend on re-rendering the latest template.

## Slot identity and repeated components

An active profile slot is unique by machine model, scope, and normalized `slot_code`; it is not unique by `component_id`. A logical Gear component may therefore appear as `GEAR-A` and `GEAR-B` in the same C1070 model. Slot code and component identity become immutable after the profile has provisioned a machine or obtained lifecycle evidence. Display order, expected clicks, thresholds, and notes remain template defaults that can change safely. Existing lifecycle expectations remain immutable install-time snapshots.

Catalog assignment summaries count active effective profiles only and expose both unique models and physical slots, for example `1 model · 2 slots`.

## Provisioning and synchronization

Database-owned synchronization runs after machine creation and relevant profile changes, and can be invoked explicitly by Owner/Admin. It:

- provisions every eligible active effective profile into a persistent machine assignment;
- is serialized per account and idempotent;
- provisions new profiles to existing active machines of the model;
- respects workspace overrides of shared profiles;
- respects explicit machine exclusions;
- reuses a retired assignment rather than duplicating its slot;
- creates no lifecycle, counter, inventory movement, receipt, or cost lot;
- retires only stale inherited assignments that are UNKNOWN and have no lifecycle evidence.

A machine whose model has no active profiles remains valid with zero configured components. Users see explicit Add Component and Apply / Sync Model Profile actions.

## Machine-specific assignments and exclusions

A machine-specific add creates a `machine_specific` assignment only for that physical machine and never updates the Model Profile. An inherited assignment records its `source_profile_id`.

Removing an uninitialized inherited slot retires its assignment and records a durable profile exclusion. Refresh, profile restore, and future sync cannot re-add it. Clearing the exclusion reuses/reprovisions the slot in UNKNOWN state without fabricating lifecycle evidence. Machine intent has precedence over the model template.

An assignment with any lifecycle evidence cannot be destructively removed. Because replacement, consumption, FIFO allocation, and machine-cost evidence all descend from lifecycle/replacement facts, preserving the lifecycle preserves those domains as well.

## Archive and restore

Profile archive prevents future inheritance. Existing inherited assignments without history are retired; assignments with lifecycle history remain visible and intact. Catalog components are never archived by profile archive. Profile restore preserves slot identity, rejects an active normalized-slot conflict, and reprovisions eligible machines, except those with explicit exclusions.

Catalog archive is blocked while active profiles reference the component. An archived Catalog entry cannot be selected for a new assignment. Catalog restore makes it eligible again, reuses/provisions its canonical zero-stock Inventory Item under the accepted inventory contract, and does not restore profiles or machine assignments automatically.

Archived Catalog and Profile cards expose Restore and no edit/assign/initialize/replace action until restored.

## History and Inventory safety

The migration adds configuration identity and backfills links; it does not edit lifecycle counters, replacement events, inventory movements, FIFO lots/allocations, purchases, receipts, machine cost, or acceptance facts. Canonical Inventory provisioning remains component-to-stock-master only: it creates no quantity, opening balance, receipt, or acquisition cost.

Model changes remain supported by the existing Machine master editor. They do not delete old assignments or lifecycle facts; new-model profiles may be provisioned alongside retained historical configuration. A future Settings UX should add explicit confirmation before exposing this operation more broadly.

## Authorization, RLS, retries, and concurrency

Active account members can read assignments and exclusions through RLS. Owner/Admin alone can archive/restore, add/remove machine assignments, clear exclusions, and request sync through `SECURITY DEFINER` RPCs with empty `search_path`. Technician/Operator retain their accepted lifecycle Initialize/Replace permissions but cannot restructure Catalog/Profile/Machine configuration. Anonymous, suspended, and cross-account access is denied.

Normalized unique indexes protect model slots, machine slots, profile-to-machine provisioning, and request keys. Account-scoped advisory transaction locks serialize profile sync, restore, removal, and exclusion changes. Retryable mutations accept client request IDs; duplicate provisioning and exclusion operations resolve to the existing evidence, while conflicting payload reuse is rejected where the request creates durable evidence.

## Reconciliation

The forward migration deterministically creates assignments for effective active model slots and for every machine slot already carrying lifecycle history, then links lifecycle rows to the matching assignment. Archived/no-history projections are not materialized. No hosted fake data is inserted.

## Concrete example

Component Catalog: `Gear`

C1070 profiles: `GEAR-A`, `GEAR-B`

- CG-TUP-A3-01: `GEAR-A` excluded; `GEAR-B` inherited.
- CG-TUP-A3-02: `GEAR-A` inherited; `GEAR-B` inherited.

Restoring or syncing the model never overrides CG-TUP-A3-01’s exclusion.

## Settings handoff

Settings may later administer Component Catalog, Machine Models, Model Profiles, provisioning defaults, account permissions, and machine overrides. It must consume these tables/RPCs and must not create another configuration model. Maintenance remains excluded.
