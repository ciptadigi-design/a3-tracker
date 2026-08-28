# Machine Cost Simplification and Period Click Semantics

## Business decision

Machine Cost is simple by default and deep when needed. The Summary answers four operational questions: Total Clicks, Component Consumption, assessed Error / Waste, and Cost / Click. Purchase, Inventory, FIFO/acquisition, component composition, unknown-cost, and completed-lifecycle evidence remain available in Cost Details.

This patch does not remove or redefine the Standard versus Advanced Machine Economics architecture. Advanced Machine Economics remains off by default and the existing Operating Costs tab remains the entry point when an account enables it.

## Period click semantics

`get_machine_cost_period` now sums the authoritative `usage` values exposed by `machine_counter_history` for effective Total Impressions readings whose `observed_at` falls inside the selected operational period:

```text
period_total_clicks = SUM(effective Total Impressions reading usage in period)
```

The period is resolved with the established timezone fallback:

```text
machine.timezone → branch.timezone → account.default_timezone
```

Usage belongs to the effective reading event. If an August reading is linked to a previous effective July reading, its database-derived delta belongs to August because the effective reading occurred in August. The engine does not require or fabricate an August 1 reading.

### Baselines and empty periods

A baseline establishes the first trusted value. It has no previous effective reading, so its usage is `NULL` and contributes zero to the sum. Its absolute reading value is never interpreted as usage.

- An in-period baseline followed by usage readings returns the sum of those later deltas.
- A period containing only a baseline returns Total Clicks `0` with complete counter evidence.
- Effective readings whose accumulated usage is zero return Total Clicks `0`.
- A period with no effective Total Impressions reading returns No Counter Data and no numeric Total Clicks.

### Corrections

Daily Counter owns previous-effective linkage and database-derived usage. Machine Cost reads `machine_counter_history` and includes only rows with `status = 'effective'`. Voided and superseded rows do not contribute, so historical correction rows are not double counted.

Multiple effective readings on the same day are supported. Every authoritative delta is included according to its effective reading timestamp; no React-side boundary subtraction or daily reconstruction is performed.

## Cost / Click

The go-live operational numerator remains:

```text
Known Component Consumption Cost
+ Known Assessed Error / Waste Cost
= Known Cost Numerator

Known Cost Numerator / Period Total Clicks = Cost / Click
```

Purchase Cost, remaining Inventory Cost Basis, completed-lifecycle analytical evidence, and Advanced Operating Costs while Advanced is off do not enter this metric.

When a consumed component has unknown acquisition cost, the component amount is shown as unknown rather than Rp0. If known costs and valid clicks exist, the UI qualifies the result as a known Cost / Click and reports the unknown event concisely. Unpriced incidents remain explicit unknown evidence and are never assigned an arbitrary amount.

## Interface structure

### Summary

The default Summary contains:

- Total Clicks
- Component Consumption
- Error / Waste
- Cost / Click

A compact status badge reports Complete, Partial cost data, No counter data, or No consumption. There is no standalone Data Status card. A stronger message appears only when missing counter data makes Cost / Click unavailable.

### Cost Details

Cost Details retains audit and analytical context:

- unknown component and Error / Waste evidence;
- component cost composition and Component Cost / Click;
- Purchase Cost with its consumption-attribution explanation;
- known and unknown Inventory Cost Basis quantities;
- completed lifecycle evidence, explicitly marked as not added again to period cost.

Unknown-only composition does not render a monetary percentage bar.

### Operating Costs and Advanced economics

Operating Costs remains a separate tab. The existing account-authoritative Advanced feature setting, permissions, immutable history, Standard fields, Advanced fields, and Full fields are preserved. When Advanced is enabled, Full Machine Operating Cost remains separate and does not replace the operational Standard meaning.

## Database contract and compatibility

Forward migration `20260828000900_machine_cost_period_usage.sql` replaces the body of `get_machine_cost_period` without changing its signature or returned columns. `get_machine_economics_period` continues to reuse that RPC, so Standard and Full economics receive the corrected `total_clicks` without duplicated calculations. Legacy start/end counter fields remain available as first/last in-period effective-reading context for backward shape compatibility; they are not calculation boundaries.

## M2.5C handoff

This patch does not implement selling price, revenue, contribution, or margin. M2.5C can continue to consume the unchanged database-authoritative fields, including `known_standard_machine_cost`, `known_standard_cost_per_click`, `known_full_machine_operating_cost`, and `known_full_operating_cost_per_click`, after this UX is accepted.
