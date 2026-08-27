# M2.4D Inventory Cost Allocation

## Scope and terminology

M2.4D adds operational inventory costing without creating an accounting ledger.

- **Purchase Cost** is the immutable ordered acquisition value (`ordered_quantity × unit_price`) dated by `inventory_purchases.purchase_date`. Creating a purchase does not change stock or machine cost.
- **Inventory Cost Basis** is acquisition cost still represented by physical FIFO layers. It is operational evidence, not an accounting “asset value.”
- **Consumption Cost** is cost allocated to an outbound operational inventory movement at that movement's `occurred_at`. It is not dated by purchase or receipt.
- **Realized Lifecycle Cost** is the known inventory consumption cost that installed a now-completed component lifecycle.
- **Realized Cost / Click** is realized lifecycle cost divided by positive `actual_usage`. Active, zero-usage, and unknown-cost lifecycles return `NULL`, never a fabricated zero.
- **Machine Cost / Click** remains a future aggregation boundary. M2.4D supplies component-level facts but no Overview, selling price, margin, or accounting journal.

## Immutable FIFO model

`inventory_cost_lots` records cost-bearing quantity introduced by a receipt, opening balance, adjustment in, or transfer in. `inventory_cost_allocations` records quantity moved out of one or more lots. Neither table stores mutable remaining quantity.

Remaining quantity is derived by `inventory_cost_lot_balances`:

```text
source_quantity - SUM(inventory_cost_allocations.quantity)
```

All allocations record:

```text
allocation_policy = FIFO
algorithm_version = fifo_v1
```

Posted lots, cost inputs, and allocations are protected by immutable-history triggers. There is no direct authenticated mutation grant. Future corrections must use a separately designed correction or revaluation workflow; M2.4D never edits historical purchases, receipts, movements, replacements, or allocations.

## Sources and unknown cost

- A receipt line creates a known-cost lot using its immutable `unit_price_snapshot` and links to its purchase, supplier, and receipt evidence.
- Opening balance uses the optional `opening_unit_cost`. A missing cost stays `NULL` and is explicitly unknown.
- Adjustment in accepts a known nonnegative unit cost or explicit unknown cost.
- Adjustment out and inventory issue accept no cost override; both allocate existing physical layers FIFO.
- Zero is a valid known unit cost only when explicitly supplied by acquisition or a controlled inbound workflow. Unknown is always `NULL`.

`inventory_cost_position` separately exposes `known_cost_quantity`, `unknown_cost_quantity`, and `known_inventory_cost`. A partial cost basis can therefore never masquerade as a complete Rp0 balance.

## Location-aware FIFO and transfer preservation

FIFO candidates must match account, inventory item, and physical location. They are locked and processed deterministically by:

```text
effective_at → created_at → cost lot UUID
```

A source-location transfer out receives ordinary FIFO allocations. Each transfer allocation creates a destination cost lot with the same quantity, unit cost, and `origin_receipt_line_id`. `source_transfer_allocation_id` preserves the lineage hop. Quantity and total cost are unchanged; no arbitrary destination price is created.

## Transaction, locking, and concurrency

Cost capture uses database triggers inside the existing inventory mutation transaction. A successful replacement therefore follows the effective lock/order boundary:

```text
Machine
→ Lifecycle
→ Inventory Item
→ Location
→ FIFO cost lots (effective_at, created_at, UUID)
→ issue movement
→ cost allocations
→ replacement/lifecycle facts
→ commit
```

The pre-existing inventory-item row lock serializes balance-changing operations for an item. FIFO rows are additionally locked with `FOR UPDATE`. A failed allocation raises an exception and rolls back the movement and surrounding replacement transaction. Competing replacement tests prove that the last layer can be allocated only once.

Receipt lines create their lots in the same receipt transaction. Transfer out allocations and transfer in layers are also one transaction. Backfill during the forward migration establishes layers and allocations for accepted pre-M2.4D movement facts without updating those facts: receipt evidence is known; historical opening and adjustment-in evidence without a recorded unit cost is unknown.

## Replacement and lifecycle linkage

The authoritative chain is:

```text
Component Replacement
→ inventory issue movement
→ FIFO cost allocation(s)
→ origin receipt line
→ receipt
→ purchase and supplier
```

`component_replacement_history` derives the installation consumption cost, known/unknown quantities, completeness, and layer count. It does not copy cost onto the replacement row. External/untracked replacements have no inventory movement and remain **Unknown / External**, not Rp0.

`component_lifecycle_costs` joins a lifecycle to the replacement event that installed it. Active lifecycles may expose `installed_component_cost`; they never expose realized cost/click. Closed lifecycles expose realized cost and cost/click only when the installation is inventory-backed, every allocated quantity has known cost, and `actual_usage > 0`. Toner uses the same rules, with actual usage interpreted as realized yield.

## Period and future reporting contract

The composable database surfaces are:

- `inventory_cost_lot_balances`: per-layer source, allocated, and remaining quantity/cost.
- `inventory_cost_position`: current FIFO cost position by account, branch, item, and location.
- `inventory_cost_allocation_history`: allocation evidence with receipt, purchase, and supplier lineage.
- `inventory_consumption_cost_history`: outbound issue/adjustment cost by account, branch, item, reference, and operational date.
- `component_lifecycle_costs`: machine/component lifecycle installation and realized economics.
- `monthly_inventory_cost_summary`: account/month purchase cost and operational consumption cost flows.

Future Overview code should query these surfaces rather than reimplement FIFO in React:

```text
Purchase Cost             ← purchase period / purchase lines
Consumption Cost          ← issue period / FIFO allocations
Ending Inventory Cost     ← inventory_cost_position
Realized Lifecycle Cost   ← component_lifecycle_costs
Realized Cost / Click     ← component_lifecycle_costs
Machine Cost / Click      ← future aggregation of real operational consumption
```

Purchases are account-scoped in the current procurement model and have no authoritative branch until a future business rule introduces one. Consumption and current inventory position are branch-queryable through physical locations. Dashboard-specific grouping is intentionally absent from core tables.

## Purchase number strategy

New UI purchases call `create_inventory_purchase_auto`. PostgreSQL generates the authoritative account/month sequence in `inventory_purchase_number_sequences`:

```text
PUR-YYYYMM-####
```

The sequence row is updated atomically and is unique through the existing account/normalized-number index. Same-request retries take a transaction advisory lock, return the same logical purchase, and reject changed payloads through the existing purchase idempotency validation. The older manual-number RPC remains available only for backward compatibility with accepted M2.4C clients and tests; the product UI no longer asks an operator to invent an internal number.

`supplier_reference` is presented as optional **External Reference** and may carry a supplier invoice, Cetakia/Peachtree reference, PO, or future integration ID.

## Security

Cost relations use account RLS and active-membership visibility consistent with Inventory. Anonymous and suspended users cannot read through RLS or execute controlled RPCs. Owner/Admin keep their existing purchase, opening, and adjustment workflows. Technician/Operator can read visible cost evidence and can only create consumption through the already-authorized replacement workflow. There is no policy editor or arbitrary cost override permission.

Every new `SECURITY DEFINER` function uses `search_path = ''`, schema-qualified references, explicit authorization through the controlled inner mutation RPCs, and revoked default execution privileges. Authenticated clients cannot directly insert, update, or delete cost allocations.

## Deliberately deferred

Weighted average, LIFO, journals, accounts payable, tax, payment, landed cost, revaluation, allocation correction, external-cost correction, machine-cost dashboards, selling price, margin, forecasting, and reorder automation remain out of scope.
