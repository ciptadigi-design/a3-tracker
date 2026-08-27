# M2.4B Replacement ↔ Inventory Integration

## Recovery state

M2.4B resumed from the accepted M2.4A commit `6fed1a05d7aa7c632ed5b179d5abc25df308cbb7`. The interrupted worktree contained the forward migration, inventory-aware replacement form, service payload, history changes, and partial regression-test updates. Those changes were audited and continued in place; no reset, restore, stash, or recreation was performed.

## Atomic architecture

`replace_machine_component` remains the only frontend replacement action. Its M2.4B signature adds five required, non-defaulted arguments:

- inventory source
- inventory item ID
- inventory location ID
- inventory quantity
- external/untracked reason

The `SECURITY DEFINER` function validates authorization and replacement inputs, locks and validates the affected records, validates location stock, optionally creates the newer Total Impressions reading, closes the old lifecycle, creates the new lifecycle, posts the inventory issue when applicable, creates the replacement event, and links both immutable facts. PostgreSQL commits or rolls back the complete function call as one transaction.

The deployed 12-argument M2.3C overload remains in the catalog for migration compatibility but has no client execution grant. This prevents authenticated clients from bypassing explicit inventory-source recording. Historical events created before M2.4B retain a null source and remain readable as legacy records.

## Migration strategy

`20260827001700_replacement_inventory_integration.sql` is forward-only. No migration through `20260827001600` is changed. It adds:

- enum `component_replacement_inventory_source` with `inventory` and `external_untracked`
- nullable legacy-compatible source/link/reason columns on `component_replacement_events`
- a restrictive replacement-to-movement foreign key and unique one-to-one movement link
- source-consistency and cross-domain validation
- the inventory-aware RPC overload and least-privilege grants
- readable replacement inventory fields in `component_replacement_history`
- replacement machine/component context in `inventory_movement_history`

## Lock order and concurrency

The deterministic lock order is:

1. machine (`FOR UPDATE`)
2. current lifecycle (`FOR UPDATE`)
3. inventory item (`FOR UPDATE`)
4. inventory location (`FOR KEY SHARE`)

The inventory item lock is the same balance-serialization boundary used by M2.4A opening, adjustment, and transfer RPCs. All balance-changing operations for an item serialize before summing its location ledger. Two replacements on different machines competing for the last unit may both lock their own machine, but only one can pass the item-scoped stock check; the loser rolls back without a lifecycle, event, movement, or counter change.

## Inventory source states

Every new replacement explicitly selects one of two stable states:

- `inventory`: requires an active item, active physical location, and positive `numeric(20,4)`-compatible quantity.
- `external_untracked`: requires a nonblank reason and rejects item, location, or quantity payloads.

The form labels are “Ambil dari Inventory” and “Stok eksternal / belum tercatat.” No free-text source values are accepted.

## Item matching and location semantics

An inventory-backed replacement accepts only an item whose `component_id` exactly equals the lifecycle component. Names are never used for transaction authorization. Multiple active SKUs may link to the same component and are all selectable.

Consumption always targets one account-owned, active physical location. The UI shows its derived `inventory_stock_balances` quantity and a current/used/after preview. Account-total stock is not used to authorize a location issue, and the server recalculates stock while holding the item lock.

## Ledger issue and replacement linkage

Inventory consumption inserts one immutable movement with:

- `movement_type = issue`
- negative signed quantity
- the Inventory Item’s existing unit snapshot
- `reference_type = component_replacement`
- `reference_id = replacement event ID`
- the replacement request ID, occurrence time, actor, and PIC snapshot

The replacement event stores the movement ID. The event is assigned its UUID before either insert, allowing the movement to reference the future event and the subsequently inserted event to reference the movement within the same transaction. A validation trigger verifies account, component link, movement type/sign, request, occurrence time, PIC snapshot, and bidirectional IDs. The ledger remains the only stock source of truth.

## Quantity and UOM

The UI defaults quantity to `1`; the database accepts a positive exact numeric value with at most four decimal places, matching M2.4A. The Inventory Item’s existing UOM (`pcs`, `bottle`, `set`, or `roll`) is displayed and snapshot on the issue. No conversion is performed.

## PIC inheritance

The replacement’s existing performed-by choice remains authoritative. The generated issue copies the exact `performed_by_name_snapshot`; users are not asked for a second PIC. When the selected account member has an active `operational_people.linked_user_id`, its stable person ID is also resolved. Manual performer names remain valid with a null operational-person ID. `created_by` and its actor snapshot continue to identify the authenticated submitter separately.

## Roles, RLS, and security

Owner, admin, technician, and operator may call the controlled replacement RPC when actively enrolled in the account. The function validates machine, lifecycle, component profile, item, location, quantity, source, and stock under the tenant boundary.

Technician and operator receive no generic inventory mutation grant. They still cannot directly insert/update/delete `inventory_movements` or execute owner/admin-only adjustment and transfer operations. Anonymous users, suspended memberships, cross-account machines/items/locations, archived masters, mismatched component links, and direct immutable-history mutations are denied. The function uses an empty `search_path` and fully qualified objects.

## Idempotency

The replacement’s account-scoped `client_request_id` covers the lifecycle, optional counter reading, issue, and replacement event. An identical retry returns the existing event. The comparison includes source and, for inventory, item/location/quantity; for external stock it includes the normalized external reason. Reusing a request ID with a changed inventory payload is rejected.

## Counter and lifecycle regression

- Equal replacement counter: no extra reading.
- Higher replacement counter: an effective `component_replacement` Total Impressions reading is inserted atomically.
- Lower replacement counter: rejected with the established Daily Counter correction guidance.
- The previous lifecycle closes with actual usage equal to replacement minus installed counter.
- The new lifecycle is active at the replacement counter, starts with zero usage, and snapshots the current effective profile baseline.
- Adaptive eligibility and expected-life formulas are unchanged.

## Toner

Consumption-based toner profiles use the identical inventory source, matching, location, quantity, and issue flow. A toner issue can use `bottle` and contributes both completed lifecycle evidence and inventory consumption evidence. Fractional refill intelligence is not part of M2.4B.

## UI and persistence

The existing Replacement `BlockingDialog` was extended, not replaced. Portal, inert background, scroll lock, focus trap/restoration, Escape handling, and busy-state protection remain owned by the shared dialog.

Inventory source, item, location, quantity, and external reason are fields in the existing lifecycle-scoped `usePersistentDraft` object. Workflow state remains handled by the Components workflow hook. Cancel/Escape closes the workflow without arbitrarily deleting its recoverable draft; successful replacement clears only that lifecycle’s draft and active workflow. Existing pre-M2.4B drafts are accepted and receive safe UI defaults without being attached to another lifecycle.

Replacement History shows Inventory, External / Untracked, or Not recorded / Legacy context without UUIDs. Inventory Movements resolves a component replacement reference to machine code/name and component name.

## Acceptance procedure

Without submitting a real replacement:

1. Open Components → Machine Components and select the intended machine in Detailed view.
2. Open Replace Component on an active initialized lifecycle.
3. Confirm both Inventory Source choices appear.
4. Choose Inventory and confirm only explicitly component-linked active items appear.
5. Select a physical location; verify available quantity, default quantity `1`, UOM, and after-stock preview.
6. Switch to External / Untracked and confirm its reason is required.
7. Switch back and verify the lifecycle-scoped draft behaves correctly.
8. Escape or Cancel, reopen, and confirm the recoverable draft.
9. Do not submit unless a real physical replacement is operationally justified.
10. Confirm no replacement event or inventory movement was created during UI-only acceptance.

For a later real replacement, submit once and verify the issue, linked histories, lifecycle closure/new lifecycle, stock reduction, counter behavior, and idempotent refresh.

## Future boundary

Purchase Orders, suppliers, receiving, valuation, FIFO/weighted-average costing, replacement unit cost, machine cost per click, reordering, notifications, and prediction remain out of scope. The immutable issue and stable replacement link provide the consumption evidence those future modules may reference without introducing cost facts now.
