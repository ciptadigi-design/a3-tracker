# M2.4C Purchasing, Receiving & Inventory Cost Foundation

## Architecture

M2.4C adds an account-owned procurement evidence layer beside the existing inventory ledger. A supplier and purchase describe the commercial commitment; neither changes stock. Only the atomic receiving RPC creates immutable receipt evidence and positive `receipt` movements in the M2.4A ledger. Stock remains derived exclusively from `SUM(inventory_movements.quantity)`.

The model deliberately stops before accounting, payment, valuation, FIFO, weighted-average cost, or machine-cost policy. Unit prices are acquisition evidence, not inventory value or component replacement cost.

## Supplier model

`inventory_suppliers` is an account-owned operational master with a normalized, case-insensitive supplier code. Owner/admin may create, edit, archive, reactivate, and delete an unreferenced supplier. All active account members may read it. Purchases use restrictive foreign keys, so referenced suppliers are archived rather than destructively removed.

## Purchase and line models

`inventory_purchases` stores one supplier, purchase number/date, optional supplier reference, `IDR` currency snapshot, supplier identity snapshots, notes, status, actor audit fields, and a creation idempotency key. Purchase numbers are normalized-unique per account.

`inventory_purchase_lines` stores an explicitly account-owned inventory item, ordered `numeric(20,4)` quantity, `numeric(20,2)` unit price, item/SKU/UOM snapshots, notes, and a database-generated line total. One item appears at most once per purchase. Purchase creation is one RPC transaction, so a header cannot be left without valid lines. Creating a purchase posts no inventory movement.

## Receiving model and inventory integration

`inventory_receipts` is the immutable receiving header. It snapshots purchase/supplier identity, the active physical location, receipt time, operational PIC identity, authenticated actor identity, notes, and a receipt-level idempotency key.

`inventory_receipt_lines` links a purchase line to one positive immutable inventory movement and snapshots item identity, UOM, quantity, unit acquisition price, and acquisition value. The movement uses the existing `receipt` type and `purchase_receipt` reference, with the receipt ID as its reference. No secondary balance table is introduced.

## Transaction boundaries and locking

Purchase creation validates and snapshots the supplier and every active account inventory item before inserting the purchase and lines.

Receiving is one `SECURITY DEFINER` PostgreSQL transaction. It validates the active owner/admin membership, request identity, purchase, line payload, active location, active operational PIC, remaining quantities, and account ownership. It then creates the receipt, one positive movement per received line, links each receipt line to its movement, refreshes the purchase status, and returns the receipt. Any error rolls back every fact.

The receiving lock order is purchase, purchase lines in UUID order, inventory items in UUID order, then location. Purchase-line locks serialize competing receipts and reject over-receiving. Inventory-item locks retain compatibility with the M2.4A ledger serialization boundary. The concurrency test uses independent sessions against the last remaining purchase quantity and proves one winner.

## Partial receiving and cancellation

Receipt quantities cannot exceed each line's remaining ordered quantity. Any receipt below the total changes the purchase to `partially_received`; completion changes it to `received`. Over-receiving is rejected transactionally.

An unreceived or partially received purchase may be cancelled by owner/admin. Existing receipts and stock remain immutable; cancellation does not reverse inventory. A fully received purchase cannot be cancelled. Physical corrections use the established inventory adjustment workflow.

## Idempotency

Purchase creation and receipt posting require account-scoped `client_request_id` values. An identical retry returns the original logical result. Reusing a request ID with a different header or canonicalized line payload is rejected. Receipt movements use distinct internal request IDs because one receipt may contain several lines at the same location; receipt-level idempotency prevents duplicate movements and stock.

## Cost evidence and currency

Authoritative financial values use PostgreSQL `numeric`, never floating point. Purchase line totals and receipt acquisition values are database-generated quantity × unit-price values. Currency is an immutable ISO-style purchase snapshot and is restricted to `IDR` for this milestone; there is no exchange-rate engine.

Purchase/cost-history views expose supplier, purchase date, ordered and received quantities, unit price, receipt date, location, and immutable receipt snapshots. The Stock UI may label the latest purchase unit price as “Last purchase”; it is evidence only. M2.4C does not change lifecycle baselines, adaptive intelligence, replacement cost, machine cost, cost-per-click, or reports.

## PIC, permissions, RLS, and immutability

Receiving reuses active `operational_people`. The physical receiver ID/name snapshot is distinct from the authenticated creator ID/name snapshot.

Owner/admin manage suppliers, create/cancel purchases, and receive. Technician/operator have read-only access to supplier, purchase, receipt, cost, and stock evidence. Anonymous, suspended, and cross-account access is denied. Clients cannot directly insert receipt evidence or ledger rows. Receipt headers/lines and purchase lines reject update/delete; posted purchase facts cannot be rewritten.

All privileged RPCs use explicit authorization and an empty `search_path`. Inputs are revalidated against account-owned active rows instead of trusting client account IDs.

## UI workflows and persistence

Inventory adds a compact `Purchasing` section with Suppliers, Purchases, and Receiving views. Owner/admin workflows use the shared `BlockingDialog`, `usePersistentUIState`, and `usePersistentDraft` architecture. Workflow identity remains separate from editable data and is scoped by user/account/entity. Successful operations clear only their own workflow and draft; Cancel/Escape preserves recoverable drafts under the existing policy. Stale supplier/purchase references close safely rather than attaching a draft to another entity.

Receipt movements render supplier, purchase number, location, PIC, quantity, and acquisition-price evidence without raw IDs. Existing opening, adjustment, transfer, and component-replacement movement rendering is unchanged.

## Future costing and purchasing boundaries

The immutable purchase-line and receipt-line prices allow a later milestone to choose exact-batch, FIFO, weighted-average, latest-cost, or another policy without rewriting stock history. M2.4C does not implement approval chains, RFQ, requisitions, payments, tax accounting, landed cost, valuation, automatic replacement costing, forecasting, reorder automation, or notifications.

## Acceptance procedure

1. Create, edit, archive/reactivate, and safely delete an unreferenced supplier as owner/admin; verify read-only roles.
2. Create an IDR purchase with one or more active inventory items and verify line/purchase totals and zero stock change.
3. Receive part of a line into an active location with an active operational PIC; verify immutable receipt evidence, one positive movement per line, location/account balance increase, and `partially_received` status.
4. Receive the remainder; verify `received` status and rejection of any over-receipt.
5. Verify Movements and cost history show supplier, purchase, location, PIC, quantity, UOM, and unit-price evidence without raw UUIDs.
6. Exercise purchase/receiving drafts across Inventory tabs and route navigation; verify successful submit clears only its own draft.
7. Verify desktop/tablet/mobile, light/dark, keyboard focus, Escape, busy state, and no horizontal overflow.
8. Regression-check opening balance, adjustment, transfer, replacement inventory issues, Components, and Daily Counter.
