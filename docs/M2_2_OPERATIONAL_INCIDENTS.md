# M2.2 Operational Incidents

> M2.2.1 adds transactional Edit Log revisions and the deliberate solve workflow. See [M2_2_1_INCIDENT_REVIEW.md](./M2_2_1_INCIDENT_REVIEW.md).

## Domain boundary

`operational_incidents` records human and production-process errors: incorrect
designs, materials, procedures, production setup, test-print loss, and
specification mismatches. The `machine_operation` incident type is displayed as
“Mesin”, but means incorrect operational use or setup. It does not represent a
technical machine fault code, hardware diagnosis, service manual, or
maintenance event.

## Loss and lifecycle

Material and service losses are non-negative IDR values. PostgreSQL generates
`assessed_loss` as `(material_loss + service_loss) * penalty_multiplier`.
M2.2 creation always uses multiplier `1`; no dynamic punishment is fabricated.

Posted incident content is immutable in V1. Lifecycle changes use controlled
RPCs: owner/admin/technician may resolve an open incident, while only
owner/admin may void an open or resolved incident with a required reason.
Voided records are never hard-deleted.

## Scope and summary

Composite foreign keys enforce account/branch scope and optional machine scope.
A linked responsible user must belong to the same account, while the name
snapshot preserves historical context across staff changes.

The Errors page shows all records visible for the active branch. Its simple V1
summary totals open and resolved records across that visible branch dataset;
voided records remain in history but do not contribute to operational counts or
loss totals.

## Frontend persistence

The create form uses `usePersistentDraft` with the standard
user/account/branch/feature/new key. Its open workflow uses
`usePersistentUIState`, so navigation and refresh restore both the modal and its
unsaved values. Explicit close clears only workflow state, successful creation
clears both workflow and draft, and logout uses the existing prefix cleanup.
