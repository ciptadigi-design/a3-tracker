# M2.5B Machine Economics

## Terminology and formula

Purchase Cost is inventory acquired in a period. Inventory Cost Basis is acquisition cost still held in tracked stock. Component Consumption Cost is FIFO-backed inventory cost consumed by replacement. Operating Cost is explicit non-inventory cost directly attributable to a machine. Error / Waste Cost is explicit monetary loss on a non-voided, machine-attributed operational incident.

Known Machine Operating Cost is:

`Known Component Consumption Cost + Known Operating Cost + Known Error / Waste Cost`

Known Machine Operating Cost / Click divides that result by M2.5A valid period clicks. It is operational machine economics, not full accounting cost or P&L.

## Operating-cost evidence

Posted records are account, branch, and machine scoped. Categories are controlled codes: electricity, service contract, routine service, labor, rental/lease, depreciation, calibration, cleaning material, external technician, software/license, and other operating. Manual is the only M2.5B creation source; imported, integrated, and derived provenance codes are reserved for truthful future integrations.

One-time costs use an effective timestamp and belong to the half-open operational interval `[period start 00:00, day after period end 00:00)` in machine → branch → account timezone.

Period costs use `daily_proration_v1`. Stored coverage dates and query dates are inclusive. Allocation is `amount × overlapping calendar days ÷ coverage calendar days`, rounded to two IDR decimal places per record using PostgreSQL NUMERIC. Click-based allocation is not implemented.

Depreciation, electricity, and labor are explicit configured evidence only. There is no asset schedule, kWh inference, salary inference, payroll, or tax/accounting model. Service and external-technician costs do not create replacements.

## Errors and waste

The existing Errors domain already stores explicit material loss, service loss, PostgreSQL-generated assessed loss, machine attribution, immutable revisions, and void history. Non-voided assessed loss greater than zero is known Error / Waste Cost. A machine incident with zero recorded assessed loss is unpriced evidence, not an invented Rp0 loss. Error count never receives an arbitrary monetary rate. Technical machine fault and Maintenance workflows remain separate.

## Completeness and double counting

Economics status is COMPLETE, PARTIAL, INSUFFICIENT_COUNTER_DATA, or NO_DATA. Unknown M2.5A consumption and unpriced machine incidents make otherwise valid economics PARTIAL. Missing click boundaries prevent Machine Operating Cost / Click without hiding known cost.

Parts already consumed through Inventory → Replacement must not also be entered as Operating Cost. For a service invoice containing Rp500,000 labor and a Rp2,650,000 Inventory-backed drum, only Rp500,000 belongs in Operating Cost. Purchase Cost, Ending Inventory Cost Basis, and completed lifecycle analytical evidence remain context and are never added again.

## Lifecycle, permissions, and correction

Operating costs are posted at creation and immutable. Owners/Admins may create and void them through retry-safe RPCs. An identical `client_request_id` retry returns the same fact; changed payload is rejected. Void requires a reason and its own request ID, retains history, and excludes the record from future economics. Technicians/Operators may read but cannot mutate operating costs. Anonymous, suspended, and cross-account access is denied by RPC checks and RLS.

## UI and zero data

Machine Cost retains its M2.5A summary and adds broader economic layers plus a separate Operating Costs surface. The entry draft is machine-scoped and persistent. With no operating costs, the page displays an honest empty state and keeps existing component numbers unchanged. Hosted DEV is not seeded with example economics.

## Future Reports contract

Future Overview/Reports can consume `get_machine_economics_period` for clicks, M2.5A component consumption, allocated operating costs, error/waste costs, known Machine Operating Cost, known cost/click, completeness, and category breakdown. Monthly totals remain derived arbitrary-range queries, never stored summaries.

Selling Price / Click, contribution, and margin are intentionally deferred. Maintenance schedules, tickets, work orders, dispatch, and notifications are excluded.

## Portability

The implementation uses PostgreSQL enums, NUMERIC, range-overlap date arithmetic, JSONB aggregation, generated incident loss, RLS, security-definer RPCs, and Supabase `auth.uid()`. A future shared-hosting port requires replacements for Supabase Auth/RLS/RPC exposure; the allocation and formula logic itself is standard PostgreSQL and does not require extensions.
