# M2.5A Machine Cost Engine Foundation

## Scope and terminology

M2.5A establishes the component-consumption portion of machine economics. It is not an all-inclusive machine-cost or accounting system.

- **Purchase Cost** is the acquisition value purchased in a period. It follows `inventory_purchases.purchase_date` and does not enter component cost per click.
- **Inventory Cost Basis** is known acquisition cost still held in inventory. Unknown-cost quantity remains explicitly unknown.
- **Consumption Cost** is FIFO inventory cost consumed by an operational replacement. It follows the replacement/issue occurrence time.
- **Realized Lifecycle Cost** is the installation consumption cost of a completed component lifecycle. It is analytical evidence and is not posted again as consumption.
- **Component Consumption Cost / Click** is known component consumption cost divided by valid machine click volume for the period.
- **Machine Cost** is a future broader metric. M2.5A must not be presented as including fixed machine cost, service contracts, electricity, labor, waste, selling price, or margin.

The same purchased stock can therefore be purchase cost in July, Inventory Cost Basis through August, and consumption cost only when physically issued in September.

## Query architecture

The database remains authoritative:

- `machine_component_consumption_events` joins replacement events to M2.4D FIFO consumption evidence and structured component metadata.
- `machine_lifecycle_cost_evidence` exposes completed-lifecycle economics for analysis only.
- `get_machine_cost_period(account, machine, start_date, end_date)` returns the machine-period summary, component breakdown, and lifecycle evidence used by the UI.

The RPC returns one row with resolved timezone and UTC boundaries, boundary counters, clicks and counter status, known and unknown consumption evidence, cost status, known cost per click, context values, component composition, and completed lifecycle evidence. No React calculation decides authoritative clicks, cost allocation, completeness, or period attribution.

The existing indexes already match the dominant access paths:

- `counter_readings_effective_history_idx` supports effective counter boundary lookup.
- `component_replacement_events_machine_history_idx` supports machine and occurrence-period selection.
- M2.4D movement, allocation, and FIFO-lot indexes support cost lineage and historical branch position.

No duplicate summary table, mutable monthly snapshot, or additional ledger was introduced.

## Period and timezone semantics

The RPC accepts inclusive operational dates. For `2026-08-01` through `2026-08-31`, it resolves a half-open timestamp interval `[2026-08-01 00:00, 2026-09-01 00:00)` in the machine's operational timezone.

Timezone resolution is deterministic:

1. `machine.timezone`
2. `branch.timezone`
3. `account.default_timezone`

The browser timezone is never an operational boundary. A replacement exactly at the exclusive ending boundary belongs to the following period.

The UI supports Today, This Week (Monday through the current operational day), This Month, Last Month, This Year, and an arbitrary custom inclusive range. Filter preference is stored per user, account, and branch.

## Counter boundary semantics

Total Impressions is cumulative and is never summed.

- Start counter: latest effective Total Impressions reading at or before the period-start instant.
- End counter: latest effective Total Impressions reading after the start instant and at or before the period-end boundary.
- Total clicks: `end_counter - start_counter` only when both boundary roles have evidence.

Ordering is deterministic by `observed_at DESC, created_at DESC, id DESC`, so multiple readings per day are safe.

Requiring a reading after the start boundary is deliberate. Reusing the same old reading at both boundaries would fabricate a confirmed zero. The counter status is:

- `COMPLETE`: both boundary roles are evidenced. Equal values represent valid zero clicks.
- `INSUFFICIENT_START`: an end-period reading exists but no reading exists at or before the start.
- `INSUFFICIENT_END`: a start reading exists but no later reading exists through the end boundary.
- `NO_DATA`: neither role has evidence.

Incomplete evidence returns `total_clicks = NULL`, never a fabricated zero. Upstream counter validation remains responsible for rejecting regressions.

## Consumption and machine attribution

Only `component_replacement_events` for the selected machine inside the resolved half-open interval are eligible. Known cost is the sum of M2.4D `inventory_cost_allocations` reached through the replacement's inventory issue. Purchase date, receipt date, cost-lot creation date, account-wide consumption, adjustment out, and another machine's replacement do not enter the numerator.

M2.5A counts external/untracked and legacy replacement evidence as consumption events with unavailable cost. It does not fabricate an allocation or infer a price.

## Known, unknown, and status semantics

A known event is an inventory-backed replacement whose linked outbound movement has complete FIFO cost evidence. Other replacement evidence is unknown for machine-cost completeness.

The RPC exposes:

- known consumption event count;
- unknown consumption event count;
- known and unknown allocated quantity where quantity evidence exists;
- known consumption cost;
- event coverage percentage: `known events / all consumption events × 100`.

External/untracked quantity may not be known, which is why event coverage is the stable M2.5A coverage measure. Unknown cost is never converted to zero.

Consumption status is `COMPLETE`, `PARTIAL`, or `NO_CONSUMPTION`. Overall cost status is:

- `COMPLETE`: counter evidence and all consumption cost evidence are complete.
- `PARTIAL`: counter evidence is complete but one or more consumption events have unknown cost.
- `NO_CONSUMPTION`: counter evidence is complete and no replacement consumption occurred.
- `INSUFFICIENT_COUNTER_DATA`: clicks cannot be established even if consumption is visible.
- `NO_DATA`: there is no counter boundary or consumption evidence.

## Cost per click and zero handling

When counter evidence is complete and clicks are positive:

`known_component_cost_per_click = known_consumption_cost / total_clicks`

The database rounds to four decimal places. With partial coverage, the UI calls this **Known Cost / Click**. With complete evidence it calls it **Component Cost / Click**.

- Valid positive clicks with no consumption produce zero consumption cost and zero component cost per click.
- Valid zero clicks preserve consumption cost but return cost per click as unavailable.
- Incomplete counter evidence returns clicks and cost per click as unavailable.
- No data, unknown cost, and numeric zero remain distinct states.

## Component composition

The RPC groups eligible replacement events by structured `components.id` and `components.category`. Missing structured category is labeled `Other`; arbitrary component names are not parsed. Each row includes known cost, all event count, unknown-cost event count, and percentage of the known-consumption denominator. Percentages therefore never imply that unknown cost is represented.

The normalized event view remains available for future account, branch, machine, component, category, and arbitrary-period reporting.

## Purchase and inventory context

Purchase Cost context is account-wide purchase-line acquisition value whose purchase date falls in the requested operational dates. It is displayed separately and never enters the machine numerator.

Ending Inventory Cost Basis context is calculated for the selected machine's branch as of the exclusive period-end instant. It derives each historical lot's remaining quantity from source quantity minus allocations whose outbound movement occurred before the boundary. Known cost quantity, unknown-cost quantity, and known cost remain separate. Transfers retain M2.4D layer identity and effective timing.

These labels are operational cost evidence, not accounting claims such as asset value.

## Lifecycle evidence and double-count prevention

Completed lifecycles removed in the selected period expose:

- component and slot;
- installed component cost when known;
- actual clicks or yield;
- realized lifecycle cost;
- realized cost per click.

Lifecycle completion measures performance of the installed part. It does not create another cost event. Period consumption is recognized only at the installation replacement/issue, so the engine cannot add installation cost once at consumption and again when the lifecycle closes.

Active lifecycles remain outside completed-lifecycle evidence. No provisional realized economics are invented.

## Security

The summary RPC is `SECURITY DEFINER` with `search_path = ''`. It explicitly requires authentication, active membership in the target account, and a machine belonging to that account. Anonymous, suspended, and cross-account calls are denied.

Evidence views use `security_invoker=true` and existing tenant RLS. Active members, including technicians and operators under existing inventory/replacement visibility, may read evidence. There is no client mutation path for derived machine-cost facts and no cost override or policy editor.

## UI contract and zero-data behavior

The dedicated **Machine Cost** navigation surface provides:

1. machine and operational-date filters;
2. clicks, known consumption, known component cost per click, and unknown event count;
3. a textual, non-color-only data status;
4. component composition using known cost as denominator;
5. separately labeled Purchase Cost and Ending Inventory Cost Basis context;
6. completed lifecycle evidence explicitly marked as not added to consumption.

Empty periods show an explanatory state instead of a broken chart. Missing counter evidence and unknown cost show unavailable/partial text rather than `NaN`, division errors, or false `Rp0` completeness.

## Future Overview and M2.5B boundary

Future Overview/reporting can aggregate normalized consumption events by account, branch, machine, component, category, and arbitrary periods, while calling the period RPC per machine for counter completeness. Branch reporting must retain machine-level completeness rather than blindly dividing partial aggregate cost by clicks.

M2.5B may introduce explicitly modeled fixed machine cost, service contracts, electricity, labor/operator cost, waste/error economics, broader machine cost per click, selling price, and margin. None are inferred or stored by M2.5A.

Maintenance remains intentionally deferred until after the initial production go-live.

## Deployment portability

This milestone adds no infrastructure migration and no hosted-only data. Its database contract uses PostgreSQL views, PL/pgSQL, IANA timezone conversion, RLS, and Supabase `auth.uid()`. Moving to shared hosting later therefore requires PostgreSQL support plus a replacement or compatible implementation for Supabase authentication/RLS claims and frontend RPC transport. Vercel remains only the current static application deployment path. These existing platform bindings should be addressed in Production Readiness; they do not justify speculative redesign in M2.5A.
