# M2.3A Component Intelligence Foundation

## Domain boundaries

`components` is the reusable catalog. A null `account_id` is a platform/shared definition; a non-null value is an account-owned custom definition. Shared definitions are readable but immutable to tenant roles. Owner/admin users manage their account definitions without any cross-account write path.

`machine_model_components` is the model/slot contract. Shared baseline rows have a null `account_id`. An account row with the same machine model and slot is an override. The application resolves shared rows first, then account rows; an inactive account override therefore hides a shared slot without corrupting the shared history. This remains compatible with later profile-copy tooling.

Stable `slot_code` values are machine-readable identifiers. Names remain user-facing labels. Active slots are unique within machine model and scope.

## Tracking and baseline semantics

- `counter_based`: mechanical lifetime measured against machine counter movement.
- `consumption_based`: expected yield, used initially for toner.
- `inspection_based`: counter expectation may be a reference while condition/inspection remains authoritative.

`baseline_expected_clicks` is editable profile configuration. It is never a manufacturer-immutable fact. A future installation must snapshot it into `expected_at_install`; editing the profile then affects future installs only and must not rewrite historical snapshots.

Thresholds are stored on every profile. Defaults are Healthy above 30%, Watch above 15%, Warning above 5%, Critical above 0%, and Overdue at or below 0%. Database checks require strictly descending boundaries.

## Adaptive lifetime direction (M2.3C architecture)

No adaptive estimate or lifecycle row is created in M2.3A. Completed lifecycle history will later derive `adaptive_expected_clicks`, sample count, confidence, and `effective_expected_clicks` without mutating the manual baseline:

- 0–2 valid samples: baseline, low/insufficient confidence.
- 3–5 valid samples: adaptive estimate may activate, medium confidence.
- 6+ valid samples: adaptive estimate may become primary, high confidence.

Future lifecycle completions require `replacement_reason` and `include_in_adaptive_learning`. Normal end-of-life, depletion, and normal replacement may be included. Physical damage, wrong installation, testing, preventive replacement, and accidental replacement are excluded by default. The sample thresholds should become configuration rather than presentation constants.

## Deletion and lifecycle linkage

Unused account-owned catalog/profile rows may be hard deleted. Foreign keys use `ON DELETE RESTRICT`, so any profile or future history reference blocks permanent deletion. The UI then archives/deactivates the record. Archived records leave active selectors but remain readable for historical joins.

M2.3A intentionally creates no installations, replacement events, inventory, purchases, stock movement, health percentage, remaining clicks, technical faults, or counter mutations.

## Initial C1070 data

The authoritative initial profile contains exactly 28 supplied components: 24 counter-based profiles and four consumption-based toner profiles. The migration seeds every supplied name and click baseline with deterministic UUIDs.
