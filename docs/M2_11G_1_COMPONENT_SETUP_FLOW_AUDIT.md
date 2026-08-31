# M2.11G.1 Component Setup Flow, Initialize Eligibility & Graha Reconciliation Audit

**Audit status:** documentation-only, no hosted data or runtime behavior changed.

## Scope and evidence

This audit was performed on `develop` at the accepted M2.11G implementation (`8592bf9263d6601fb10f6ca6df320a2cd30c4d24` before this document). Evidence comes from the Components page, component dialogs/services, Laravel migrations/services/tests, and the Supabase reference migrations/tests. Graha hosted rows were not queried; the reported 18-component composition therefore remains an observation, not a database assertion.

## The Initialize discrepancy

The exact frontend gate is `machineComponentCapabilities()` in `src/features/components/componentAssignmentContracts.js`:

```js
canManage && assignment.lifecycle_status === "unknown" &&
assignment.model_component_profile_id
```

`ComponentsPage.jsx` applies the same `model_component_profile_id` requirement before rendering the Initialize action. `canManage` is owner/admin (or the operator permission `operator_can_initialize_component`). The lifecycle action resolver then returns `initialize` only for an unknown row whose capability is true. There is no stock, catalog, machine-state, lifecycle-method, or source-type test in this UI gate.

The Laravel `ComponentConfigurationService::initialize()` locks the machine component and creates an idempotent lifecycle; it does **not** require a profile slot, inherited source, tracking method, stock, or existing lifecycle. Thus a manual row with `profile_slot_id = NULL` is accepted by Laravel (subject to the route's account lookup and lifecycle chronology). The Supabase reference RPC is stricter: it requires an active model-profile slot, baseline expected clicks, a current Total Impressions counter, and owner/admin authorization. This creates a port/reference mismatch. Toner Yellow shows Initialize because profile assignment plus Sync supplies `model_component_profile_id`; the other observed rows are manual and have it null. Stock availability is not involved.

## Add/Assign/Sync action inventory

| Context and label | Handler/entity | Effects |
|---|---|---|
| Global header `+ Add Component` (non-machine tabs) | `open('component-create')` | Creates a catalog definition only; no profile, machine assignment, stock, or lifecycle. Owner/admin. |
| Machine Components `+ Add Component` | `open('machine-component-add')` → `addMachineComponent` | Creates a machine-specific/manual Machine Component for the selected machine; profile unchanged; no lifecycle. Owner/admin. |
| Global `+ Add Profile` | `open('profile-create')` | Creates a Model Profile for the selected machine model; does not assign machines or create lifecycles. Owner/admin. |
| Catalog `Assign to Model` | `save_machine_model_component_profile` | Creates/updates a Model Profile Slot (component, durable slot code, tracking method, baseline, thresholds/order). It does not immediately create machine rows; Sync provisions them. |
| Machine `Sync Model Profile` | `sync_machine_component_assignments_internal` / `syncMachineComponents` | Model Profile → Machine Components. Creates missing inherited rows, preserves active exclusions and manual overrides, is idempotent, and does not initialize lifecycles or create stock. |

The repeated label `Add Component` is therefore ambiguous: the global action creates a catalog definition while the machine action creates a physical manual assignment.

For an existing manual assignment at the same machine and normalized slot as a newly-added profile slot, Sync treats the manual assignment as an override (`not exists` configured slot/profile logic). It leaves the manual row manual and skips the inherited row; it does not convert, merge, or surface a conflict.

## Tracking methods and initialization

`counter_based`, `consumption_based`, and `inspection_based` are persisted values. Catalog has a default; Model Profile Slot stores the per-model authoritative method and baseline. The reference assignment contract also stores method/baseline on each physical assignment so machine-specific components can be configured independently. Laravel's current `machine_components` table has neither these override fields nor baseline; its manual-add service silently drops the dialog's tracking/baseline/notes fields.

* **Counter based:** latest effective `Total Impressions` minus lifecycle installed counter is current usage. Expected life is the profile/adaptive or install snapshot; remaining, percentage, health bands, and estimated replacement counter derive from that. Initialization requires a valid current counter and baseline in the reference implementation.
* **Consumption based:** current health still uses the same counter calculation. The UI relabels fields as Used/Expected Yield and changes the replacement caption; no inventory quantity, yield-per-unit, or consumption event calculation exists. M2.11G FIFO/replacement supplies cost and stock evidence, not this health metric.
* **Inspection based:** the enum is selectable, but no inspection schedule/event/grade/pass-fail/recommendation domain or RPC was found. Current lifecycle health remains counter-based; this is a placeholder/partial implementation.

Recommended implementation direction is Option A: store explicit machine-specific tracking configuration (method, baseline, and method-specific settings) on the manual assignment, with catalog values only as defaults. This preserves the accepted first-class manual model and allows the same catalog component to differ by machine model.

## Reconciliation safety

The durable identity is the existing Machine Component/assignment UUID. Lifecycles, replacements/inventory evidence, exclusions, and future joins reference that UUID; deletion/recreation is unsafe. A controlled future reconciliation may update provenance in place only after deterministic matching and conflict checks. The safe key is:

`machine_id + normalized durable slot_code + component compatibility + matching machine-model profile (and account/branch scope)`.

Never match by `component_id` alone (for example DRUM-C/M/Y/K are repeated logical components). A no-history manual row can be reconciled if there is no exclusion/conflict. A row with lifecycle/history can retain all evidence and UUID, but the reference assignment contract currently treats source identity as immutable; conversion therefore needs a dedicated audited migration/RPC or should remain manual. In either case, lifecycle timestamps/counters/cost allocations must not be rewritten.

Removal is intentionally two operations hidden behind similar wording: inherited/no-history removal creates a durable profile-slot exclusion and prevents resync; manual removal retires the machine-specific assignment and leaves the profile unchanged. Restore clears an exclusion and resyncs the eligible inherited assignment, returning UNKNOWN without fabricating a lifecycle. Recommended copy: “Exclude profile component” versus “Remove machine-specific component,” with history-aware confirmation.

The Catalog badge (`1 model · 1 slot`, etc.) is calculated by `activeAssignmentSummary()` from active effective Model Profile Slot rows, not Machine Components.

## Recommended setup and Graha strategy

**Standard model component:** Catalog → Assign to Model (slot, method, baseline) → Sync → inherited Machine Component → Initialize using the machine counter/evidence.

**Machine-specific component:** Catalog → Add Machine-Specific Component → explicit method/baseline configuration → Initialize. No profile slot should be required by the accepted 11F manual-first architecture; the reference RPC/UI gate must be brought into parity before enabling this flow.

For Graha, keep manual rows where identity or intent is uncertain; reconcile only deterministic same-machine/same-slot matches with explicit approval and UUID preservation; archive/remove only obsolete, no-history rows; send lifecycle-backed or ambiguous rows to review. Do not delete/recreate rows or mass-assign catalog components.

## Test coverage and gaps

Existing Laravel parity tests cover repeated slots, manual add, exclusions/restore, lifecycle idempotency, duplicate-slot rejection, and chronology. Supabase pgTAP covers repeated-slot identity, manual override, exclusion/restore, Sync idempotency, and security. Missing or insufficient coverage includes: manual `profile_slot_id=NULL` initialization in both ports; matching manual slot during Sync; UUID-preserving reconciliation with and without lifecycle history; persisted manual tracking configuration; distinct consumption/inspection semantics; and UI Initialize visibility for manual rows. These are the proposed M2.11G.1 implementation/test scope, not changes made by this audit.

## UX recommendation

Make actions contextual: Machine Components: **Sync Model Profile**, **Add Machine-Specific Component**; Model Profiles: **Add Profile** and **Add Slot**; Catalog: **New Component** and per-card **Assign to Model**. This can be implemented at the UI boundary once the manual tracking/eligibility contract is made explicit.

## Non-scope

No Error/Incident, Waste Attribution, full Machine Cost, Reports, or Maintenance work was started. No hosted DEV, Supabase production, Vercel, Hostinger, DNS, or real Graha data was mutated. Historical purchases remain acquisition evidence only; no legacy opening/11-unit seed exists.

