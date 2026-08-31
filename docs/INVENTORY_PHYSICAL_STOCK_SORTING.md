# Physical Stock sorting

Physical Stock is a presentation of balances derived from posted inventory ledger movements. The list defaults to:

- total quantity descending (highest stock first)
- item name ascending for equal quantities

The client pipeline is **active/archived filter → search and scope filters → numeric quantity sort → pagination → render**. Sorting the complete filtered dataset before pagination keeps the first pages globally ordered.

Zero-stock items remain visible and sort after positive balances. Negative numeric balances, if encountered in legacy/anomaly data, remain numeric and sort below zero. An unresolved non-numeric/unknown balance is preserved and placed after known numeric balances.

This change does not add a duplicate quantity field or mutate Inventory Items, Inventory Movements, FIFO layers, purchasing, receiving, opening-balance, adjustment, transfer, replacement, or cost semantics.
