# M2.6B — Reports Export, Comparative Analytics & UX Polish

## Purpose

M2.6B extends the accepted read-only M2.6A Reports foundation with management comparison, export-ready tabular projections, and browser print/save-PDF presentation. It does not store reporting totals, mutate operational evidence, or introduce a parallel economics model.

## Period comparison semantics

`get_report_period_comparison` accepts the same inclusive operational date range as M2.6A. The current range is converted by the existing machine → branch → account timezone resolution into half-open timestamps `[local start 00:00, day after local end 00:00)` within the underlying report/economics contracts.

PostgreSQL receives the selected preset and derives its calendar comparison:

- Today → previous day.
- This Week → previous Monday through Sunday.
- This Month → previous full calendar month.
- Last Month → the calendar month before the selected month.
- This Year → previous full calendar year.
- Custom 10–20 August (11 days) → 30 July–9 August (11 days).

PostgreSQL derives the prior range. React does not independently calculate it. Daily Counter evidence remains effective `total_impressions` usage only; baseline, voided, superseded, unrelated-machine, and out-of-period evidence is excluded by the accepted contracts.

## Delta semantics

The database returns current/previous values, evidence states, a nullable percentage, and one of:

- `COMPLETE`: compatible evidence and a non-zero previous denominator; percentage is `(current - previous) / abs(previous) × 100`.
- `NEW`: previous is zero and current is positive; no percentage is fabricated.
- `NO_COMPARISON`: missing values, no data, or a zero denominator without a positive new value.
- `PARTIAL`: either side has incompatible partial price/cost/counter evidence.

The UI always renders a textual state. Color is supplementary. `NaN`, infinity, and exaggerated sentinel percentages are never emitted.

## Machine comparison

`get_report_machine_comparison` projects the authoritative M2.5C economics rows for the selected scope. It exposes clicks, Standard Cost/Click, exact-machine Error/Waste, Estimated Machine Revenue, Estimated Contribution, margin, and price/cost/contribution evidence states. Only `COMPLETE` contribution evidence receives a contribution rank. Partial rows remain visible and qualified.

The Reports UI provides one focused selectable comparison chart for Estimated Contribution, clicks, Cost/Click, or Error/Waste. It does not use a dual axis and does not allocate branch-only loss to machines.

## Component ranking

`get_report_component_ranking` aggregates by logical component identity across the current machine/branch/account scope. CMYK component identities therefore remain distinct. It returns replacement count, known FIFO-backed consumed cost, unknown-cost event count, and three database ranks.

Known-cost share uses only total known component consumption as its denominator. A zero/absent known denominator returns no percentage; unknown acquisition evidence is never treated as zero cost.

## Error / Waste analytics

`get_report_error_summary` provides category, type, machine-vs-branch attribution, and readable PIC groupings. Voided incidents are excluded from current assessed analytics. Machine filters exclude branch-only incidents. Branch-only loss remains a separate `BRANCH_ONLY` group and is never distributed across machines.

## Inventory / Purchasing analytics

`get_report_inventory_analytics` retains separate counts/values for purchases, receipts, issues, replacement issues, adjustments, transfer legs, out-of-stock items, and low-stock items. Received value is derived from immutable receipt acquisition snapshots.

Purchase value and receipt value are acquisition context, not consumed Machine Cost. FIFO consumption remains authoritative only when inventory is actually issued/consumed.

## CSV export contract

CSV export is produced from the already-authorized server report response for the selected account, branch, machine, dates, report tab, and Error/Waste filters. It never scrapes rendered HTML and never performs a broader data call. Export mappings contain human-readable business fields and omit internal IDs, auth metadata, client request keys, and audit UUIDs.

Supported datasets are Overview/machine summary, Machine Performance, Machine Economics, Machine Comparison, Component Consumption, Error/Waste, and the combined Inventory/Purchasing context. Optional SKU values are readable, empty values are blank, CSV escaping is RFC-style, and raw JSON/`undefined` values are not emitted. Filenames include report, readable scope, and selected dates.

The tabular mapping is intentionally independent of download mechanics so it can later feed workbook or server-rendered formats.

## XLSX decision

XLSX is deferred. The current project has no spreadsheet dependency, and adding a comparatively heavy client workbook library for the current operational dataset would increase bundle and dependency risk disproportionately. The export mapping is workbook-ready: numeric values remain unformatted in source rows, dates remain ISO-compatible, and each report has a bounded tabular projection. A future implementation can map these definitions into separate workbook sheets without changing report economics.

## Print / Save PDF

`Print / Save PDF` invokes the browser print dialog; it does not auto-print and does not generate a server PDF. Print CSS:

- uses a light, ink-friendly presentation even when the app is in dark mode;
- hides sidebar, top navigation, filters, tabs, and interactive-only controls;
- prints report title, readable scope, selected dates, and generated timestamp;
- includes the selected report summary/content;
- preserves table grids and avoids clipped overflow or avoidable card breaks.

## Completeness and terminology

Estimated Machine Revenue remains utilization revenue from effective Daily Counter usage × historical Selling Price/Click. It is not invoiced or accounting revenue. Estimated Contribution remains Estimated Machine Revenue − Standard Machine Cost. It is not net profit. Advanced Full Contribution remains separate and optional.

Comparison preserves `PARTIAL_PRICE`, `PARTIAL_COST`, no-counter, and no-data meaning. Incompatible current/previous evidence does not receive a trustworthy percentage.

## Permissions and security

All new functions are read-only `SECURITY DEFINER` entry points with an empty search path. Their first data path reaches the accepted active-membership scope resolver or an accepted guarded report RPC. Execute is granted only to `authenticated` and `service_role`; anonymous users have no grant. Active Owner, Admin, Technician, and Operator roles read the same operational scope permitted by Reports. Suspended memberships and cross-account requests are rejected.

Exports can only serialize report data already returned through these authorized calls, so export cannot bypass RLS/RPC scope.

## Performance and indexes

Comparison and ranking reuse the date, machine, account, and branch predicates of accepted report/economics functions. M2.6A already added the justified inventory movement/receipt account-time indexes, while counter, replacement, incident, and price paths already have operational indexes. No speculative M2.6B index is added. Machine comparison and period comparison remain modular, permitting later query-plan tuning without a mega-RPC.

## Read-only dialog cleanup

All Reports detail dialogs share `ReportDetailDialog`. Its redundant footer Close button and footer spacing were removed. The single top-right X has the accessible name `Close report detail`. Shared `BlockingDialog` behavior remains unchanged: Escape, backdrop policy, focus trap, focus restoration, body scroll lock, and keyboard operation continue to work. Actionable dialogs elsewhere are untouched.

## Future Reports roadmap

The modular results can later power a multi-sheet XLSX workbook, scheduled exports, or server-side PDF rendering. Future consumers must continue to request PostgreSQL projections rather than rebuild Daily Counter, FIFO, price history, revenue, or contribution formulas in frontend code.

Maintenance reporting is explicitly excluded from M2.6B. Shared-hosting migration is also excluded. PostgreSQL/Supabase portability remains straightforward: numeric money, ISO date/timestamp inputs, explicit enums/status text, ordinary views/RPCs, and no vendor-specific reporting store.
