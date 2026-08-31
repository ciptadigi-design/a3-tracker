# M2.11I — Reports / Analytics & Operational Economics Parity

Reports are read-only projections over trusted operational domains. No report
summary is stored and the frontend never recomputes monetary economics.

## Canonical sources and contract

- Counter usage: effective `total_impressions` counter rows and their immutable
  period usage evidence (`[start, end)` in machine-local time).
- Component cost: `component_replacements.consumed_cost`, including FIFO
  allocations. Missing evidence remains `null` and increments the unknown-cost
  completeness count.
- Incident/Error/Waste: stored/effective assessed loss. Only non-voided rows
  explicitly attributed to the selected machine enter Machine Cost; branch-only
  loss remains visible but separate.
- Machine Cost: `component consumption cost + machine-attributed assessed loss`.
  Purchases, receipts, opening stock, labor, electricity, depreciation, and
  overhead are not included. Advanced Economics remains deferred.
- Lifecycle and replacement detail: machine component/lifecycle and replacement
  evidence, preserving external/untracked source status.
- Inventory consumption: issue movements linked to replacement events only;
  purchase, receipt, opening, transfer, and physical adjustments are not
  machine consumption.

The stable report DTO includes `period`, `scope`, `overview`, `machine_cost`,
`daily_clicks`, `counter`, `operator_activity`, `incidents`, `replacements`,
and `inventory_consumption`. Money is integer/business-unit decimal data at the
backend boundary: known zero is `0`, unknown is `null`, and cost/click is `null`
when clicks are zero.

## Scope, time, and people

Account scope is resolved from authenticated membership. The selected global
Branch is authoritative; Machine is optional and must belong to that branch.
Timezone inheritance is machine → branch → account default → UTC. Periods are
local half-open ranges and browser timezone is never used for classification.

Counter reports use the immutable historical operator snapshot. Current
Counter Operator capability (active person + active branch assignment +
`can_record_counter`) governs new entries only and never rewrites history.
Incident `Operator` and `PIC Terlibat` are separate fields; one person may
legitimately appear in both.

## Adapters and security

Supabase RPCs remain the reference implementation (`get_report_overview`,
`get_report_machine_economics`, usage, component, incident, inventory,
comparison, and ranking RPCs). Laravel/MySQL exposes the equivalent
`GET /api/v1/reports` contract through `OperationalReportService`, with account,
branch, and machine authorization enforced before projection. Both adapters
preserve null/partial evidence and deterministic newest-first detail ordering.

Detailed tables use the shared client pagination contract (10/25/50); KPIs and
charts are based on the complete filtered result, never page 1 only.

Maintenance analytics and hosted operational data generation are out of scope.
