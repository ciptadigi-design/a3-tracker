# Post-M2.5B Standard / Advanced Economics Acceptance Patch

## Business decision

M2.5B's operating-cost schema, immutable history, proration, provenance, RLS, RPCs, and tests remain intact. For initial production go-live, Cipta Grafika's primary operational KPI is **Standard Machine Cost**. Advanced Machine Economics remains future-ready but is disabled by default because recurring electricity, manpower, depreciation, lease, software, and other overhead evidence is not yet operationally reliable.

## Authoritative formulas

The database function `get_machine_economics_period` is authoritative:

```text
Known Standard Machine Cost
= Known Component Consumption Cost
+ Known assessed Error / Waste Cost

Known Standard Cost / Click
= Known Standard Machine Cost / valid Total Clicks

Known Full Machine Operating Cost
= Known Standard Machine Cost
+ Known Advanced Operating Cost

Known Full Operating Cost / Click
= Known Full Machine Operating Cost / valid Total Clicks
```

Purchase Cost and Known Inventory Cost Basis are context only. A purchase enters Component Consumption only when physical stock is consumed through the established FIFO allocation. Remaining stock is never added to Standard or Full cost. Completed lifecycle evidence also remains analytical context and is not double-counted.

An incident contributes money only when it has an explicit assessed loss. Unpriced incidents and component consumption without acquisition cost remain explicit unknown evidence. They are never assigned an arbitrary amount and never presented as zero. Zero valid clicks produces an unavailable per-click result rather than division by zero.

## Feature configuration

`accounts.machine_economics_advanced_enabled` is the authoritative account setting and defaults to `false`. `set_machine_economics_advanced_enabled` is an idempotent Owner/Admin-only RPC. Active Technicians and Operators may read the resulting state but cannot change it; suspended, anonymous, and cross-account callers are denied.

The setting controls current entry and presentation. While off, the database rejects new `machine_operating_costs` inserts. Disabling never deletes, voids, updates, reprorates, or rewrites an existing operating-cost record. Enabling never creates evidence. Historical records remain readable, immutable, and available to the economics engine. Standard metrics never change meaning when Advanced is toggled.

The account column is intentionally a single feature flag. Future category policy can extend the account configuration with explicit category activation (for example electricity on, labor off) without changing the Standard/Full formula boundary. This patch does not infer an unknown cost merely because an enabled category has no record. Advanced ON with zero period records means a known recorded total of zero and is presented as “No advanced operating costs recorded for this period.” Advanced OFF is presented distinctly as disabled.

## Completeness

Standard economics completeness depends on counter boundaries, component-consumption cost evidence, and error/waste price evidence. Missing electricity, labor, depreciation, or another advanced category never makes Standard incomplete. Advanced activation does not imply that all possible categories require a row. Full cost completeness currently follows the same explicit known/unknown evidence boundary because every posted advanced record has a required known amount.

The RPC retains M2.5B output aliases for compatibility and adds explicit fields for feature state, component consumption, Standard cost/click, Advanced cost, Full cost/click, counter completeness, cost completeness, and Full availability.

## UI policy

Machine Cost Summary prioritizes Total Clicks, Component Consumption, Error / Waste, Standard Machine Cost, Standard Cost / Click, and Data Status. When Advanced is off, no overhead categories appear in the primary KPI area. The Operating Costs tab remains visible and explains the disabled account policy; it allows no new entry and preserves readable history. When Advanced is on, a separate Advanced section adds Advanced Operating Costs, Full Machine Operating Cost, and Full Operating Cost / Click while retaining Standard values unchanged.

Settings exposes plain-language “Advanced Operating Costs” account control. Standard's permanent component-consumption plus assessed error/waste rule is stated beside it.

## M2.5C contract

This patch does not add selling prices, revenue, contribution, margin, reports, or stored monthly summaries. M2.5C can consume the explicit Standard and Full outputs for:

```text
Selling Price / Click - Standard Cost / Click = Standard Contribution / Click
Selling Price / Click - Full Operating Cost / Click = Full Contribution / Click
```

Full contribution is available only under the Advanced feature policy. Standard contribution remains independently meaningful.

## Inventory Movement detail convention

The Movements list no longer renders an inline/floating expandable panel. A compact Eye action with `aria-label="View movement details"` opens the shared `BlockingDialog`-based read-only detail dialog. It groups Movement, type-specific Reference, Cost Evidence, and Audit facts; receipt details include supplier/purchase/receipt prices, replacement issues include machine/component context, transfers resolve readable source/destination locations, and inbound/outbound FIFO evidence is shown when available. Missing facts are not fabricated and raw UUIDs are never presented.

The dialog is internally scrollable, uses a single column on mobile, follows existing light/dark variables, closes by Close or Escape, traps focus, and restores focus to the Eye action. Read-only detail does not create form-draft persistence; the established workflow identity is reused only to restore the selected movement safely.

Preferred future project convention:

- Detail → Eye → read-only detail dialog
- Edit → Pencil/Edit action
- History → History/Clock action
- Delete → Trash with confirmation where permitted
- Archive → Archive action

This patch applies that convention only to Inventory Movements and does not refactor unrelated application surfaces.
