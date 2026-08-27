# M2.3C Component Replacement and Lifecycle History

M2.3C replaces the legacy “Reset Part” idea with one auditable PostgreSQL transaction. The frontend never directly updates a lifecycle, inserts a replacement event, or creates the next lifecycle.

## Transaction boundary

`replace_machine_component` is an authenticated `SECURITY DEFINER` RPC with an empty `search_path`. It verifies an active owner, admin, technician, or operator membership; locks the active machine and lifecycle; validates the effective model profile and slot; reads the latest effective Total Impressions reading at runtime; validates the submitted physical counter; closes the previous lifecycle; creates the next lifecycle; and writes the immutable replacement event. Any error rolls the whole operation back.

The machine row lock serializes the machine counter stream and replacement operations. The lifecycle row lock plus the existing partial unique index permits only one open lifecycle for a machine slot. A concurrent second replacement therefore observes a closed previous lifecycle and receives a conflict instead of creating duplicate state.

`client_request_id` is unique per account. A retry with the same payload returns the original replacement event. Reuse with different values is rejected. This protects the event, optional counter reading, old lifecycle, and new lifecycle from double-clicks and network retries.

## Counter integration

The dialog always starts from the latest effective Total Impressions value returned by Supabase; no counter snapshot is hardcoded.

- A physical counter equal to the latest effective reading creates no extra reading.
- A higher physical counter appends an effective, monotonic `counter_readings` row with source `component_replacement`, the replacement timestamp, actor, previous-reading link, and the same idempotency key. It becomes the latest reading in the same transaction.
- A lower value is rejected. Counter correction remains exclusively in the Daily Counter correction workflow.

The previous lifecycle closes with `removed_counter`, `removed_at`, and `actual_usage = replacement_counter - installed_counter`. Overdue usage is valid and is never capped or rejected.

The new lifecycle starts at the same replacement counter and time with installation source `replacement`. It snapshots the current effective profile baseline into `baseline_expected_clicks_snapshot` and `expected_at_install`. M2.3C does not fabricate an adaptive estimate. Derived usage is therefore zero immediately after replacement, with health and remaining life continuing to come from `machine_component_health`.

Historical lifecycle and event snapshots are immutable. Editing a model profile later does not change completed evidence; only a lifecycle installed after the edit receives the new baseline.

## Replacement facts

`component_replacement_events` preserves tenant, branch, machine, effective profile, component, slot, previous/new lifecycle links, counters, actual usage, expectation snapshots, reason, removal condition, learning eligibility, PIC identity and name snapshot, replacement time, notes, actor, and optional generated counter-reading link.

Reasons use stable values: `normal_eol`, `depleted`, `print_quality`, `preventive`, `failure`, `damage`, `contamination`, `diagnostic`, and `other`. The UI supplies clear Indonesian labels. `other` requires notes.

Removal conditions use `good`, `fair`, `worn`, and `failed`, displayed as Baik, Menurun, Aus, and Rusak.

Adaptive-learning defaults are true for normal end-of-life, depleted, and print-quality replacements. Preventive, failure, damage, contamination, diagnostic, and other default false. The operator can intentionally override the value. M2.3C only stores eligibility; `component_lifecycle_samples` exposes facts for a future adaptive engine and performs no averaging or baseline rewrite.

Consumption-based toner uses the identical transaction. Its `actual_usage` is presented as actual yield, making a completed toner lifecycle an eligible future sample when operationally appropriate.

Unknown lifecycles remain Initialize-only in M2.3C. Replacement rejects `unknown` status, so missing installation history can never produce fabricated actual usage or an adaptive sample. `TEST_COMPONENT` remains excluded from operational lifecycle and replacement flows.

## UI and persistence

Detailed Machine Components cards expose Replace Component (or Replace / Refill Toner). Compact cards remain unchanged and contain no permanent replacement action. The shared `BlockingDialog` provides portal rendering, inert background, scroll lock, Escape handling, focus trapping/restoration, responsive behavior, and busy-state protection.

The form shows machine/component/slot context, latest recorded and physical counters, date/time, linked active member or manual PIC, reason, condition, lifecycle usage/performance preview, learning eligibility, notes, and higher-counter consequences. Drafts persist by user, account, branch, machine lifecycle/slot, including the request ID. Workflow state also persists through the existing Components workflow store. Only a successful replacement clears that replacement’s draft and workflow.

Machine-scoped chronological history shows the completed usage/yield, expected snapshot, derived performance, reason, condition, PIC snapshot, replacement counter, and learning eligibility. Each expandable event shows the previous lifecycle → replacement event → new lifecycle transition without raw IDs. Events have no edit/delete UI and authenticated clients receive no direct event mutation privileges.

## Future milestones

Later work may consume eligible samples for adaptive expected-life calculations or connect replacements to inventory and purchasing. M2.3C intentionally implements neither adaptive calculations nor inventory deduction, costing, supplier, maintenance-work-order, or historical-event editing behavior.
