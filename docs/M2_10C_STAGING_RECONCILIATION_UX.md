# M2.10C — Staging Reconciliation and UX Density

Status: implementation and hosted-cleanup acceptance in progress. This milestone is DEV-only. It does not authorize Production, shared-hosting, PHP/MySQL, or Maintenance work.

## Protected M2.10B evidence

The protected set is derived from every `target_id` and `actor_target_id` in the M2.10B first-apply crosswalk under `scripts/migration/reconciliation/m2-10b/958bb2b3-3110-410f-9c03-d8355a2f9be7/first-apply/`. It contains 490 unique target identities. The exact M2.10C deletion set contains 20 identities and has an empty intersection with that protected set. The two previous Toner lifecycles are restoration targets and the two Inventory Items are preservation targets, so neither group enters the deletion set.

## Dummy evidence identification

The user-facing purchase references contain four numeric digits in the database (`PUR-202608-0001` and `PUR-202608-0002`), although the acceptance report displayed five. Identification uses immutable UUIDs, client request IDs, creator, full foreign-key provenance, receipt/movement references, FIFO allocations, and replacement lifecycle links—not dates, names, prices, or screenshots alone.

The Cyan chain begins at purchase `aaecec6a-6ee4-4c6c-a535-30dbeda2d1dd`. Its receipt movement and the independently audited `+4` opening balance feed the acceptance replacement `392ffdb5-7ccf-4304-9d9c-db9727a73cfd`. The opening balance used the same acceptance creator, a new `TES SKU` item, no migration provenance, and the FIFO lot consumed by that replacement; it is therefore proven dummy evidence.

The Yellow chain begins at purchase `74526bbf-4554-47c6-89c9-d2304c0ffa69`. Its receipt/FIFO evidence feeds acceptance replacement `d063cc89-ec0d-4164-8746-bf2618cdedb5`.

Deletion follows the actual reverse dependency order: cost allocations; replacement events; dummy active lifecycles; restoration of the previous lifecycles; cost lots; receipt lines; movements; derived stock verification; receipts; purchase lines; purchases. The controlled script requires the exact DEV project, exact IDs, execution UUID, pre-cleanup fingerprint, verified recovery manifest, empty protected intersection, and explicit apply mode. It runs in one transaction and has a rollback dry-run mode. Inventory Items remain because 55 protected legacy purchase lines reuse them.

## Recovery and reconciliation

Before cleanup, the execution will store a logical export of all affected rows, a focused target fingerprint, dependency graph, preview, and recovery manifest under a versioned M2.10C execution directory. Hosted mutation is prohibited until the tooling commit has exact-SHA green CI and recovery verification succeeds.

Reconciliation locks these invariants: 161 `LEGACY_IMPORT` draft purchases remain; protected counters, lifecycles, and incidents remain; Graha counts remain byte-for-byte equivalent; affected Toner stock is derived from surviving movements; no orphan receipt/FIFO/replacement evidence survives; and the two pre-dummy lifecycles return to active without rewriting their installation evidence.

For 1–30 August 2026, the observed pre-cleanup Machine Cost was 29,725 clicks, Rp1,625,000 known component consumption, Rp120,170 Error/Waste, and Rp58.7105 per click. The final values will be recorded from the post-cleanup database. No value is hardcoded into application calculations.

## Generic historical Inventory Items

Generic acquisition evidence remains truthful and unlinked when physical identity is ambiguous. `Other Part`, `Drum Unit`, `Charging Corona`, and `Developing Unit` remain `component_id = NULL`; `Other Part` never maps to `TEST_COMPONENT`. Exact color/slot labels are linked only where the source semantics prove the canonical component. The UI now presents null links as “Legacy item / Unspecified component” with explanatory text rather than implying broken data.

## UX changes

Detailed Component cards retain their hierarchy while reducing the unknown-state action footprint. `Initialize Lifecycle` stays on one line at normal widths and uses the accessible short label `Initialize` at compact mobile widths. Machine Cost order is COST → Daily Click Trend → BUSINESS. Error history moves `Detail →` into the far-right action zone.

One shared client-pagination control serves Daily, Inventory Stock/Movements/Purchasing/Receiving, Errors, and detailed Reports lists. The default is 10; available sizes are 10, 25, and 50. Filters and Branch/Machine scope form reset keys, pages are clamped after data changes, and mobile shows Previous / page-of-pages / Next rather than a wide number strip. Empty lists keep their domain empty states and single-page lists show only compact context. Summary cards, charts, balances, and KPIs continue to use the complete filtered dataset.

All affected lists are currently `CLIENT_PAGINATED`: existing services already load bounded staging datasets, and M2.10C deliberately avoids a broad fetch architecture rewrite. Server pagination with `range` plus exact counts is the follow-up when dataset volume makes full reads material.

## Operator and PIC Terlibat

The prior form used `responsible_person_id` as the apparent Operator and mirrored its snapshot into editable PIC text. M2.10C keeps the existing canonical responsible identity as `PIC Terlibat` and adds nullable canonical Operator ID/name snapshot fields for new records. Existing historical incidents are not backfilled or rewritten.

Both dropdowns use the same active `operational_people` query joined to active `operational_person_branches` for the selected Branch. Operator initially defaults PIC Terlibat. PIC Terlibat can then be changed independently; an explicit choice survives unrelated changes and later Operator changes. If it remains untouched, changing Operator updates the convenience default. Branch changes revalidate both identities. Draft persistence stores both IDs, snapshots, and the override state.

## Acceptance and M2.11 prerequisites

Signed-in acceptance must verify the two dummy chains are absent, migrated history remains, Component and Inventory semantics are readable, Machine Cost reconciles, both PIC selectors behave correctly, and pagination is compact at 360, 390, 393, 430, 768, 1024, 1366, and 1440 widths. M2.11 remains blocked on M2.10C acceptance plus its separate Production snapshot, backup, freeze, physical stock-opname, opening-stock manifest, reconciliation, rollback, and approval gates.
