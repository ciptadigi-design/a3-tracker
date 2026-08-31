# M2.11G.1 Component Setup Implementation

M2.11G.1 aligns manual component setup with the accepted first-class Machine Component model.

## Effective configuration and Initialize

Machine-specific assignments now persist `tracking_method`, `baseline_expected_clicks`, threshold fields, and notes (Laravel migration `2026_08_31_001000_add_manual_tracking_configuration`; Supabase assignment contract already contained these fields). The shared frontend resolver is `effectiveTrackingConfiguration()` in `componentAssignmentContracts.js`. Initialize requires manager/operator authorization, an UNKNOWN active assignment, and a complete positive-baseline configuration; `profile_slot_id` and inventory stock are not requirements for manual assignments. Supabase uses the assignment-based lifecycle RPC, retaining active-machine, counter-evidence, baseline, conflict, and tenant checks. Laravel rejects unconfigured/non-counter manual assignments.

Counter based is the only newly-configurable fully supported method. Consumption and Inspection remain readable legacy values but are marked “Coming soon” in new configuration controls; no historical rows are rewritten.

## Flows and UX

Standard component: Catalog → Assign to Model → Profile Slot → Sync Model Profile → inherited Machine Component → Initialize.

Machine-specific component: Catalog → Add Machine-Specific Component → configure counter baseline → Machine Component → Initialize. Sync remains Model Profile → Machine and never converts manual rows.

Actions are contextual (`New Component`, `Add Machine-Specific Component`, `Add Profile`, `Sync Model Profile`). Source is shown in the initialization context as Inherited or Machine-specific. Catalog badges continue to count active profile slots, not physical assignments.

## Reconciliation and history

An explicit `reconcile_manual_component_assignment` operation is available in both targets (Laravel API and Supabase RPC). It validates the deterministic machine/account/model/normalized-slot/component match, rejects conflicts/exclusions, updates the existing UUID in place, and leaves lifecycle/replacement evidence untouched. It is not invoked by ordinary Sync and does not automatically reconcile hosted Graha rows. Delete/recreate and component-id-only matching remain prohibited.

Executable coverage proves UUID-preserving manual → inherited conversion, exact slot/model/component matching, exclusion and duplicate-slot conflicts, lifecycle timestamp/evidence preservation, and ordinary Sync non-reconciliation. Machine Components expose a server-computed **Reconcile with Model Profile** action only for an eligible candidate. The shared `BlockingDialog` shows current and target slots plus: “Machine Component identity and existing lifecycle/replacement history will be preserved. This does not create a new lifecycle and does not change historical evidence.” The action is explicit, busy-protected, conflict-safe, and refreshes after success. Graha cleanup remains a manual, controlled review.

## Future User Manual Flow

**STANDARD COMPONENT:** Catalog → Assign to Model → Sync → Initialize

**MACHINE-SPECIFIC:** Catalog → Add to Machine → Configure Tracking → Initialize

## Validation

Component UX tests (51) and production build pass. Full Laravel tests pass after the migration compatibility fix; existing repository-wide ESLint still reports unrelated vendor/legacy errors. No hosted data, Vercel, Hostinger, DNS, or legacy import was changed. M2.11H remains not started.
