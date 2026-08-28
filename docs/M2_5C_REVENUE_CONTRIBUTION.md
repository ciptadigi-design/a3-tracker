# M2.5C — Selling Price, Revenue, and Contribution

## Purpose and scope

M2.5C adds operational machine economics: effective-dated selling price per click, machine-utilization revenue, and Standard/Full contribution. It does not add customers, invoices, orders, tax, payroll, depreciation accounting, a ledger, branch P&L, Reports, or Maintenance.

“Estimated Revenue” means machine-utilization revenue (`recorded clicks × applicable selling price`). It is not actual invoiced or collected revenue. “Contribution” is not accounting profit or net profit.

## Selling-price evidence

`machine_selling_prices` is account-, branch-, and machine-scoped append-only evidence. Money uses `NUMERIC(30,4)` and the only accepted currency is `IDR`. Each posted row has an immutable `effective_from`, price, optional notes, actor snapshot, request ID, and creation timestamp.

The active interval is `[effective_from, next posted effective_from)`. `effective_to` is derived by `machine_selling_price_history`; it is not stored or updated. This makes a later change close the earlier interval without rewriting either evidence row. A unique active `(machine_id, effective_from)` boundary prevents contradictory starts.

A mistaken row is corrected by `void_machine_selling_price`, which requires a reason and preserves void actor/time evidence. Voided records never price clicks. Voiding can cause the preceding posted price to resume until the next posted price; this is an explicit audited correction, not a silent update.

Create and void RPCs require a `client_request_id`. An identical retry returns the existing result; reuse with a different payload is rejected. Mutations lock the machine row before checking/inserting or voiding, giving every machine one deterministic serialization point. Database constraints remain the final safeguard.

## Period and timezone semantics

`get_machine_economics_period(account, machine, start_date, end_date)` remains the authoritative arbitrary-range contract. Operational dates resolve through machine, branch, then account timezone, as in M2.5A/B.

Revenue reuses effective `TOTAL_IMPRESSIONS` rows from `machine_counter_history`. Each row’s database-derived `usage` belongs to its actual `observed_at`; the applicable selling price is the latest posted price whose `effective_from <= observed_at`. Baselines contribute no fabricated clicks. Missing dates receive no distributed clicks. Superseded and voided readings are excluded by the existing effective-counter contract.

For multiple prices, revenue is the sum of each effective usage contribution multiplied by its applicable price. The engine exposes `priced_clicks`, `unpriced_clicks`, `estimated_revenue`, and `revenue_status`:

- `COMPLETE`: every positive effective click has a price.
- `PARTIAL`: only the priced portion is included in revenue.
- `NO_PRICE`: clicks exist, but none has price evidence.
- `NO_CLICKS`: counter evidence is complete and usage is zero.

No default price is invented.

## Cost and contribution formulas

The accepted Standard cost definition is unchanged:

`Standard Machine Cost = component consumption + machine-attributed assessed Error/Waste`

Branch-only Error/Waste remains excluded. Component cost continues to come from actual replacement consumption and FIFO acquisition evidence; purchase totals and remaining inventory are not machine expense.

With complete price coverage and revenue greater than zero:

`Estimated Standard Contribution = Estimated Revenue - Standard Machine Cost`

`Standard Contribution / Click = Estimated Standard Contribution / Total Period Clicks`

`Standard Contribution Margin % = Estimated Standard Contribution / Estimated Revenue × 100`

Partial or missing price coverage makes period contribution, contribution/click, and margin unavailable because the revenue and full-period cost bases are not comparable. Zero clicks/revenue never divides by zero.

Unknown component or Error/Waste cost is never treated as known zero. When price coverage is complete, the database can expose a numeric contribution based on available known cost evidence, but `standard_contribution_status = PARTIAL_COST`; the UI labels it accordingly.

## Standard and Full economics

`accounts.machine_economics_advanced_enabled` remains the only feature policy. Standard economics never changes meaning.

- Advanced OFF: Standard contribution is the production-primary result; Full contribution fields are unavailable.
- Advanced ON: `Full Machine Operating Cost = Standard Machine Cost + Advanced Operating Cost`, and Full contribution/margin are calculated separately. Standard values remain unchanged.

## Permissions and database access

RLS permits price-history reads only to active same-account members. The economics RPC independently requires active membership. Owner/Admin can create and void price evidence through guarded `SECURITY DEFINER` RPCs. Technician/Operator are read-only. Anonymous, suspended, cross-account, and direct unauthorized mutations are denied. Frontend visibility is convenience only, not the security boundary.

## UI and persistence

Machine Cost retains four Cost cards and adds four Business cards. Contribution Margin is secondary text in Estimated Contribution. The price action and history live beside the selected machine’s economics. Price forms reuse `BlockingDialog` and the shared persistent-draft store keyed by user/account/branch/machine. Daily Click Trend remains clicks-only with exact positive bar values and no monetary axis.

## Future Reports and portability

Future Reports should call the period RPC for arbitrary ranges and consume clicks, price coverage/history, utilization revenue, component cost, exact-machine Error/Waste, Standard cost/click/contribution/margin, and optional Full results. Reports must not reconstruct economics in React.

The contract relies on PostgreSQL `NUMERIC`, `timestamptz`, window/lateral queries, RLS, security-definer functions with empty search paths, and transactional row locks. A port must preserve decimal arithmetic, immutable evidence, half-open effective intervals, tenant isolation, idempotency, serialization, and timezone-resolved counter attribution.
