# M2.11G — Inventory, FIFO and Replacement Parity

Laravel adds separate inventory items/locations, purchases and lines, receipts and
lines, immutable signed movements, FIFO layers and allocations, and replacement
events. Purchases have no stock side effect. Receipts create positive ledger facts
and nullable-cost FIFO layers. Balances are derived from movement sums.

Transfers create matched OUT/IN facts in one transaction and copy FIFO provenance.
Adjustments require a reason. FIFO consumption locks the item context and oldest
layers, allocates deterministically, preserves NULL unit cost as unknown, and
rejects insufficient stock. Replacement supports exact inventory item/location
consumption and external/untracked evidence. Tracked replacement creates movement,
FIFO allocations and immutable cost evidence; both modes transition the lifecycle
without fabricating a predecessor.

All critical paths use transaction retry and row locks. UUID request keys provide
idempotency. Inventory, replacement, errors, full Machine Cost, Reports and
Maintenance remain separate/deferred domains.
