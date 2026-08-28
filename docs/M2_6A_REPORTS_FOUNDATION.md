# M2.6A Reports Foundation

## Purpose

Reports is a read-only operational projection layer over the existing A3 Tracker evidence. It does not store monthly totals, create a reporting ledger, or reproduce business formulas in React. PostgreSQL remains authoritative for Daily Counter usage, FIFO consumption, Error/Waste, Machine Cost, historical selling price, Estimated Machine Revenue, and Contribution.

Maintenance reporting is explicitly excluded from M2.6A.

## Scope and filters

The Reports workspace contains Overview, Machine Performance, Machine Economics, Component Consumption, Error / Waste, and Inventory / Purchasing. Its persistent filter state uses the shared `usePersistentUIState` architecture and includes report tab, branch, machine, period preset, custom range, and Error/Waste filters.

Period presets reuse Machine Cost semantics: Today, This Week, This Month, Last Month, This Year, and Custom Range. Operational dates resolve through:

1. machine timezone;
2. branch timezone;
3. account default timezone.

A selected machine must belong to the selected branch and account. All Machines aggregates active machines in the selected branch. All Branches aggregates active machines in the authenticated account. Purchase headers have no branch ownership, so purchase totals remain clearly labelled account-scoped acquisition context; receipt, movement, and stock projections can be branch-scoped through inventory locations.

## PostgreSQL contracts

M2.6A adds modular, read-only RPCs instead of one mega-RPC:

- `get_report_overview`
- `get_report_machine_performance`
- `get_report_machine_economics`
- `get_report_daily_clicks`
- `get_report_component_consumption`
- `get_report_error_waste`
- `get_report_inventory_activity`
- `get_report_purchase_lines`
- `get_report_inventory_stock`

`resolve_operational_report_scope` is an internal helper. It validates authentication, active membership, account scope, branch scope, machine scope, date ordering, and timezone resolution. It is not executable by application roles.

The modular tabular results are suitable for future CSV, Excel, and PDF exporters without requiring a different calculation contract. Export is not implemented in M2.6A.

## Overview and machine performance

Overview returns six primary operational values: Total Clicks, known Component Consumption, Error/Waste, Standard Cost / Click, Estimated Machine Revenue, and Estimated Contribution. Machine-attributed and branch-only Error/Waste totals remain separately available even when their concise UI presentation is combined.

Machine Performance derives usage from effective `TOTAL_IMPRESSIONS` evidence in `machine_counter_history`. Baselines have no usage; voided and superseded readings are excluded by the authoritative history view. Multiple readings on one operational date are summed, while active days count the date once. Daily average is clicks divided by active recorded days. No capacity, utilization, or efficiency percentage is invented.

## Machine Economics

`get_report_machine_economics` projects `get_machine_economics_period` for each in-scope machine. It does not recalculate FIFO, selling-price intervals, revenue, or contribution.

Estimated Machine Revenue means machine utilization revenue:

`effective click usage × historically applicable selling price per click`

It is not invoice revenue, sales ledger revenue, or accounting revenue. Multiple price periods remain effective-date aware. Missing price evidence produces unavailable or partial results according to the M2.5C contract.

Estimated Contribution means:

`Estimated Machine Revenue - Standard Machine Cost`

Contribution Margin means:

`Estimated Contribution / Estimated Machine Revenue × 100`

Contribution is not net profit. Standard Machine Cost includes tracked component consumption and exact-machine assessed Error/Waste. It does not imply that rent, payroll, tax, depreciation, or other company overhead has been deducted.

Advanced Machine Economics remains optional. When disabled, Full fields are unavailable. When enabled, Full Operating Cost and Full Contribution are exposed as secondary fields. Enabling Advanced never changes Standard meaning or values.

## Component consumption

Component reporting reads `machine_component_consumption_events` and replacement lifecycle evidence. It returns logical component identity, machine and branch context, replacement count, known FIFO-backed consumed cost, unknown-cost event count, and observed replacement yield. CMYK components remain distinct because aggregation uses component identity rather than display-name similarity. Unknown acquisition cost is never converted to zero.

## Error / Waste

Error/Waste reporting exposes machine attribution, branch-only attribution, category, type, PIC snapshot, status, time, and assessed loss details. A machine filter returns exact-machine incidents only. Branch-only incidents may appear in branch/account operational reporting but never enter a machine's economics. Voided incidents remain available for audit in the detailed report and are excluded from current overview/economics totals by their authoritative contracts.

## Inventory and purchasing

Inventory/Purchasing is operational context, not accounting valuation. It exposes purchases, receipts, issues, replacement issues, adjustments, transfer legs, current stock, out-of-stock/low-stock status, and read-only purchase lines.

Purchase cost does not become Machine Cost. Creating a purchase does not increase stock. Receiving creates stock and FIFO acquisition evidence. Only later component consumption can enter Machine Cost. Remaining inventory is not an expense. Optional SKU and multiple item variants remain supported.

## Completeness statuses

The overview projection exposes concise statuses:

- `COMPLETE`
- `PARTIAL_COST`
- `PARTIAL_PRICE`
- `NO_COUNTER_DATA`
- `NO_DATA`

Per-machine economics preserves the stronger M2.5C revenue, contribution, and cost evidence statuses. A partial aggregate may display the known/priced portion, but its status and unavailable margin prevent that value from being mistaken for a complete period result.

## Security

All report RPCs are `SECURITY DEFINER` with an empty `search_path`. Execution is granted to authenticated and service roles only. Every public report entry point independently validates active account membership and requested account/branch/machine scope. Owner, Admin, Technician, and Operator can read operational reports according to the existing account data scope. Anonymous, suspended, and cross-account callers are denied. Reports exposes no mutation actions and does not bypass operational RLS through unguarded parameters.

## Performance

Existing indexes already cover machine counters, replacement times, Error/Waste dates, purchase dates, and selling-price effective ranges. M2.6A adds two justified broad-range indexes:

- inventory movements by account and occurrence time;
- inventory receipts by account and received time.

No speculative report-total tables or broad redundant indexes are added.

## UI and accessibility

The report workspace uses six semantic tabs, labelled native filters, visible focus, responsive KPI grids, and table rows that become labelled cards on smaller screens. Read-only details use the established Eye → `BlockingDialog` convention, including Escape, focus trap, and focus restoration. Status meaning is communicated with text, not color alone. Empty scopes never receive fake data.

## Portability and future work

Contracts use PostgreSQL `NUMERIC`, explicit dates/timestamps, stable tabular results, and no hosted-only fake fixtures. Future report exports and richer drill-downs should consume these RPCs or compatible successors. A future invoice integration may compare utilization revenue with actual sales revenue, but must not change the meaning of M2.6A Estimated Machine Revenue.

Maintenance schedules, preventive work, service tickets, technician jobs, and fault-code reports remain out of scope.
