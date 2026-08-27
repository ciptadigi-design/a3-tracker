# M2.4A Inventory Foundation & Stock Ledger

## Architecture

M2.4A introduces an account-owned inventory boundary without changing the shared/workspace Component Catalog model. Inventory definitions, physical locations, and immutable signed movements are separate concepts:

```text
Component definition (shared or workspace)
              │ optional link
              ▼
Account inventory item ─── inventory movement ledger ─── account location
                                      │
                                      ▼
                         database-derived balances
```

There is no writable current-quantity column. React displays authoritative database views and does not calculate persisted balances.

## Inventory item model

`inventory_items` is account-owned. Its stable, normalized account SKU is unique. It stores name, optional category, constrained unit, optional non-negative minimum stock, notes, archive state, and standard creation/update audit fields. `minimum_stock = null` or `0` disables low-stock warning; a zero total is still Out of Stock. Positive stock above a disabled threshold is Healthy.

Quantities use `numeric(20,4)`. This safely supports discrete parts and future fractional consumables without floating-point arithmetic. M2.4A permits `pcs`, `bottle`, `set`, and `roll`; it deliberately has no conversion system. Unit changes are rejected after the first movement because each movement snapshots its unit.

## Component relationship

`inventory_items.component_id` is optional and references `components`. A shared active component or an active component owned by the same account may be linked. Cross-account or archived component links are rejected by the database. No replacement event deducts inventory in M2.4A.

## Location model

`inventory_locations` belongs to an account and may optionally reference a branch in that same account. Code is normalized and unique per account. Names are not hard-coded. Account members can read locations; owner/admin manages them. Branch association describes physical placement and does not introduce a second membership model.

## Movement ledger

`inventory_movements` is the stock source of truth. Quantity is signed:

- Positive: `opening_balance`, `receipt`, `adjustment_in`, `transfer_in`
- Negative: `issue`, `adjustment_out`, `transfer_out`

Database checks enforce sign, four-decimal precision, reference semantics, adjustment reason, and paired-transfer metadata. Clients have no insert/update/delete table grant. RPCs are the authenticated mutation boundary, and an immutable trigger additionally rejects movement update/delete. Corrections require a compensating adjustment.

Stable movement types are a PostgreSQL enum. Stable reference types include the implemented opening/manual/transfer origins and reserved future origins for purchase receipt, component replacement, toner refill, and maintenance. The reservation creates a safe linking vocabulary but implements none of those workflows.

## Balance derivation

- `inventory_stock_balances`: `SUM(quantity)` by account, item, and location.
- `inventory_item_totals`: `SUM(quantity)` by account and item, including inventory items with zero movements.
- `inventory_movement_history`: business-readable immutable history with item/location labels and PIC/actor snapshots.

Totals across locations are never stored or calculated as authoritative frontend state.

## Opening balance

`initialize_inventory_stock` accepts an active account item, active account location, positive quantity, effective time, active operational PIC, notes, and required client request UUID. It posts one `opening_balance` movement. A partial unique index permits only one opening movement for an item/location. An identical idempotent retry returns the existing row; reuse with different data is rejected.

## Manual adjustment

`adjust_inventory_stock` accepts a signed delta. The UI collects direction plus a positive magnitude and translates it into the signed RPC value. A reason and active operational PIC are mandatory. The RPC rejects zero, excessive scale, future effective time, inactive/cross-account references, and a delta that would make the location balance negative.

## Transfer

`transfer_inventory_stock` accepts one positive magnitude and two different active locations. In one PostgreSQL transaction it posts a negative `transfer_out` and equal positive `transfer_in`. Both share `client_request_id`, `transfer_id`, and a stock-transfer reference. Any error rolls back both legs. Insufficient source stock is rejected, and account-wide total remains unchanged.

## Concurrency and idempotency

Every balance-changing RPC locks the inventory-item row with `FOR UPDATE` before final balance validation/insertion. This serializes mutations for the item across all locations and prevents two concurrent deductions from overselling. This deliberately favors integrity and simple auditability over maximum per-location write throughput at the current operational scale.

The item lock also closes the concurrent idempotency race. After acquiring it, each RPC rechecks the required `client_request_id`. Database uniqueness constrains request legs, and a transfer may have exactly its distinct inbound/outbound legs. The pgTAP concurrency test opens two sessions against stock 5, attempts two simultaneous deductions of 4, and proves only one succeeds and final stock is 1.

## PIC and authenticated actor

Movements reference `operational_people` and snapshot the selected PIC name. They independently store `created_by` and snapshot the authenticated profile display name. Renaming or archiving a person does not erase who physically handled historical stock, and the entering user remains separately auditable.

## Permissions and RLS

- Anonymous: no table/view/RPC access.
- Suspended/revoked/non-members: no access through active-membership helpers.
- Owner/admin: read inventory; manage item/location masters; post opening balances, adjustments, and transfers.
- Technician/operator: read inventory, balances, locations, and movement history only in M2.4A.
- Service role: explicit administrative grants; immutable triggers still protect updates/deletes to posted movements.

All security-definer functions use `search_path = ''`, require authentication and explicit owner/admin active membership, derive the actor from `auth.uid()`, and validate every item, location, branch, component, and PIC against the target account. The supplied account ID is never trusted as authorization.

## Archive and deletion

Unreferenced items and locations may be hard deleted by owner/admin. Foreign keys use `ON DELETE RESTRICT`, so referenced masters cannot be destroyed; they can be archived while history and balances remain readable. Archived items/locations cannot receive new RPC movements. Archived operational people retain movement references and name snapshots.

## UI

The existing Inventory navigation item is active. The workspace has:

- Stock: compact desktop ledger table, total stock, minimum threshold/status, linked component, CMYK marker, and expandable location breakdown.
- Movements: chronological business-readable history with item/location/type filters and actor/PIC detail.
- Locations: physical location management with optional branch association.

All meaningful forms use the global `BlockingDialog` lifecycle (Escape, focus trapping/restoration, scroll lock), persistent per-user/account drafts, loading/error/empty states, accessible labels, and mobile full-screen behavior. The compact table becomes a no-overflow operational card layout on narrow screens. Existing theme variables provide light/dark behavior.

## Future integration boundaries

M2.4A does not implement purchasing, receiving, replacement/toner deductions, vendors, costs, valuation, reorder automation, forecasting, or Reports. Future workflows can reference movement `reference_type`/`reference_id`, while immutable `unit_snapshot` and movement timestamps preserve the facts needed by later cost layers. Purchase cost lots can be added alongside receipt facts without rewriting stock history, allowing a later explicit FIFO or weighted-average policy.

## Database verification coverage

`013_inventory_foundation.test.sql` covers RLS/role boundaries, normalized uniqueness, cross-account references, opening/idempotency, positive/negative adjustments, negative-stock rejection, transfer legs/total/atomicity/idempotency, derived views, PIC and actor snapshots, immutability, archive/delete semantics, and RPC locks. `014_inventory_concurrency.test.sql` exercises simultaneous deductions with independent database connections.

## Acceptance checklist

- [ ] Inventory opens with Stock / Movements / Locations.
- [ ] Owner/admin creates an inventory location.
- [ ] Owner/admin creates an inventory item.
- [ ] Item can optionally link an existing Component Catalog component.
- [ ] Cyan/Magenta/Yellow/Black semantics reuse the existing marker.
- [ ] Minimum stock is optional and accepts non-negative four-decimal quantities.
- [ ] Opening stock posts a ledger movement with effective date.
- [ ] An active operational PIC is required and snapshotted.
- [ ] Location and total balances match derived database views.
- [ ] Positive adjustment increases derived stock.
- [ ] Negative adjustment decreases derived stock.
- [ ] Adjustment history retains both facts and the required reason.
- [ ] Transfer decreases source and increases destination atomically.
- [ ] Transfer preserves total account stock.
- [ ] Transfer exceeding source stock is rejected without a partial leg.
- [ ] Refresh restores server-backed state.
- [ ] Direct client movement update/delete is denied.
- [ ] Referenced archived items/locations retain history.
- [ ] Unreferenced item/location deletion succeeds; referenced deletion fails safely.
- [ ] Technician/operator can read but cannot manage or mutate stock.
- [ ] Anonymous, suspended, and cross-account access is denied.
- [ ] Light and dark theme remain legible.
- [ ] Desktop layout remains compact and scannable.
- [ ] Tablet layout has no horizontal page overflow.
- [ ] iPhone portrait uses compact rows/full-screen dialogs without horizontal overflow.
- [ ] Dialog Escape, focus trap/restore, scroll lock, and busy blocking follow the global standard.
- [ ] Meaningful unsaved forms restore per-user/account drafts.
- [ ] No fake operational stock or purchasing data exists.
- [ ] Component lifecycle/replacement/intelligence behavior remains unchanged.
- [ ] Clean reconstruction, all pgTAP, schema lint, build, CI, DEV migration, and deployment checks pass.
