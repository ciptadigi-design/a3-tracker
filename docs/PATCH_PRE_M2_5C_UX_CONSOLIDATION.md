# PRE-M2.5C UX Consolidation Patch

## Scope

This patch consolidates the existing Inventory, Machine Cost Summary, and Daily Counter operational surfaces. It does not start M2.5C and does not add selling price, revenue, contribution, margin, Reports, or Maintenance.

## Component and Inventory Item remain separate

A Component is the logical machine part tracked by the Component Catalog and lifecycle system. An Inventory Item is an account-owned purchasable and storable SKU. `inventory_items.component_id` remains an optional relationship, so one Component may have zero, one, or multiple Inventory Items. No Component receives a stock quantity and no Inventory Item is fabricated from Component Catalog data.

Physical stock remains derived from immutable `inventory_movements`. The operational chain remains:

`Component → Inventory Item / SKU → Purchase → Receive Goods → Inventory ledger → Replacement / Consumption → FIFO evidence → Machine Cost`

## Component-aware Add Item

Add Inventory Item now begins with the eligible active Component Catalog available to the account under the existing shared/workspace rules and RLS. Options use the Component name and code rather than identifiers. Choosing a Component may safely default an empty Display Name from the authoritative Component name.

SKU, Unit, and monetary values are never invented. A new item requires the user to enter the SKU and explicitly choose its physical unit. The existing optional category, minimum stock, notes, archive behavior, permissions, and account-scoped SKU uniqueness remain in place.

## Purchase discovery and inline Inventory Item creation

Each Purchase line remains authoritative against an Inventory Item ID. Its discovery control searches active Inventory Items by:

- Inventory Item display name;
- SKU;
- linked Component name or code.

Multiple active SKUs linked to one Component remain separate selectable results. If a matching eligible Component has no active Inventory Item, the line presents `No inventory item configured` and `Create inventory item`. It never permits a Component ID to become a Purchase line target.

Inline creation uses the existing nested `BlockingDialog` stack. The Component is preselected and Display Name is safely defaulted, while SKU and Unit remain explicit. The Purchase dialog remains mounted, so supplier, date, all lines, quantities, unit prices, notes, and external reference remain intact. The Inventory Item draft uses its own component-scoped `usePersistentDraft` key. After successful creation, the local eligible item list is refreshed with the returned database row and the new item is selected on the originating Purchase line.

Only the successful Inventory Item draft is cleared. The Purchase draft is cleared only after a successful Purchase submission.

## Purchase, Receive Goods, and Opening Balance

Purchase continues to record acquisition and price evidence only. It does not insert an inventory movement or increase stock. Receive Goods remains a separate atomic operation and the only purchasing workflow that posts positive receipt movements. Partial receipts, immutable receipt evidence, FIFO cost layers, and concurrency behavior are unchanged.

Opening Balance remains the explicit bootstrap path for stock already physically present when tracking begins. Unknown opening acquisition cost stays unknown and is never silently assigned a Purchase price or treated as zero-cost evidence.

## Machine Cost Summary

The primary Summary is a balanced 2 × 2 grid on desktop and practical tablet widths, collapsing to one column on mobile. It contains only:

1. Total Clicks
2. Component Consumption
3. Error / Waste
4. Cost / Click

Cost Details remains intact for component composition, Purchase Cost context, Inventory Cost Basis, FIFO evidence, known/unknown evidence, and completed lifecycle analysis. Advanced Operating Costs remain separately labelled and never enter Standard cost silently.

## Daily Click & Cost Trend

The Summary adds the compact operational `Daily Click & Cost Trend`. Bars use a dedicated click axis and the known Standard cost line uses a separate IDR axis.

Forward migration `20260828001000_machine_cost_daily_trend.sql` adds the read-only `get_machine_cost_daily_trend(uuid, uuid, date, date)` RPC. PostgreSQL remains authoritative for daily monetary totals.

Daily clicks are the sum of `machine_counter_history.usage` for effective `total_impressions` readings attributed to each resolved machine-local operational date. Baseline null usage contributes zero when an effective baseline reading exists. Voided and superseded readings, other machines, other tenants, and events outside the inclusive selected dates are excluded. No calendar-boundary subtraction or fabricated start-of-day reading is used.

Daily Standard known cost is:

`Known Component Consumption + Known assessed Error / Waste`

Advanced Operating Costs are excluded. The RPC returns generated operational dates with nullable evidence fields, allowing the UI to distinguish:

- no cost evidence (`known_daily_cost = null`, `NONE`);
- known zero cost supported by complete evidence (`known_daily_cost = 0`, `COMPLETE`);
- a known portion plus unknown events (`PARTIAL`);
- unknown-only events with no known monetary portion (`known_daily_cost = null`, `PARTIAL`).

The chart does not plot a cost point when known cost is absent. Partial evidence is marked independently and tooltips report date, clicks, known cost, and evidence status.

## Daily Counter simplification

The primary KPI row now contains Last Counter, Today's Usage, and Last Input. Today's Entries was removed only from the primary presentation; no history or database capability was removed.

The large Counter Type and Current / Last Counter context blocks were removed from the input card. A compact `Last recorded` helper remains. New Counter, PIC / Operator, Shift, observed date/time, Notes, submission, persistent drafts, corrections, multiple readings per day, and Counter History are unchanged.

## Permissions and tenant isolation

Inventory master and purchasing writes retain existing owner/admin policies and column grants. Technician/operator permissions are not widened. The daily trend RPC is readable only by authenticated active account members, validates the machine against the target account, uses `SECURITY DEFINER` with an empty search path, and grants no anonymous access. No direct ledger mutation capability was added.

## Test coverage

Focused frontend tests cover Component Catalog presentation, safe defaults, linked-Component discovery, multiple SKU results, missing-SKU creation, independent drafts, automatic selection, Purchase/Receive separation, the 2 × 2 Summary contract, separate chart axes, absent versus known-zero daily cost, Daily KPI removal, retained counter submission, history, and multiple-reading messaging.

pgTAP `024_machine_cost_daily_trend.test.sql` covers RPC grants, effective usage aggregation, correction and void exclusion, replacement-created readings, machine isolation, machine-local timezone attribution, unknown Component evidence, known and unknown Error / Waste, exclusion of Advanced cost, missing-cost nullability, period reconciliation, deterministic dates, and cross-tenant/anonymous denial. Existing Inventory, purchasing, receiving, FIFO, replacement, Daily Counter, Machine Cost, and economics suites remain the regression authority for unchanged semantics.

## Portability

The migration uses PostgreSQL `numeric`, `timestamptz`, IANA timezone conversion, and `generate_series`; it does not depend on hosted DEV records or client locale. The chart is lightweight repository-native SVG and adds no chart dependency. Currency formatting remains browser `Intl`-based while database numeric output remains authoritative.

## Manual acceptance checklist

### Inventory

- [ ] A. Add Item exposes Component Catalog.
- [ ] B. Existing Inventory Item can still be created normally.
- [ ] C. Multiple Inventory Items can map to one Component.
- [ ] D. Purchase search finds Inventory Items by item/SKU/Component.
- [ ] E. Missing Component SKU exposes `Create inventory item`.
- [ ] F. Inline creation preserves Purchase draft.
- [ ] G. Newly created item becomes selected in Purchase.
- [ ] H. Create Purchase still does not increase stock.
- [ ] I. Receive Goods remains the stock-increasing operation.

### Machine Cost

- [ ] J. Summary is visually clean 2 × 2.
- [ ] K. Only Total Clicks, Component Consumption, Error / Waste, and Cost / Click are primary cards.
- [ ] L. Daily Click & Cost Trend appears.
- [ ] M. Daily click totals reconcile with period Total Clicks.
- [ ] N. Unknown component cost remains explicit.
- [ ] O. No daily costs are fabricated.
- [ ] P. Cost Details remains available.

### Daily

- [ ] Q. Counter Type duplicate block is removed.
- [ ] R. Current / Last Counter duplicate block is removed.
- [ ] S. Today's Entries primary KPI is removed.
- [ ] T. Last Counter, Today's Usage, and Last Input remain.
- [ ] U. Counter submission still works.
- [ ] V. Counter History remains intact.
- [ ] W. Desktop/tablet/mobile and light/dark remain usable.

Automated contract and database tests verify the underlying behavior. The checklist stays unchecked until the exact deployed build receives interactive acceptance without creating hosted operational fixtures.
