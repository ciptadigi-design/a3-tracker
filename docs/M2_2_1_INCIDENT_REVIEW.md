# M2.2.1 Incident Review Standard

## Current truth and revision history

`operational_incidents` remains the latest accepted operational truth. An Edit Log submission updates that row and inserts exactly one append-only `operational_incident_revisions` row in the same PostgreSQL transaction. Revisions contain the complete editable before/after snapshots, a stable ordered list of changed fields, the authenticated actor, timestamp, and optional change reason.

Revision rows are historical context, not incidents. They never contribute to incident counts or financial summaries.

## Authorization and lifecycle

- Owner and admin may edit an `open` incident through `update_operational_incident`.
- Technician retains the existing resolve capability but does not receive Edit Log permission.
- Operator can read history but cannot edit, solve, or void a posted record.
- Owner, admin, and technician may complete the deliberate solve flow.
- Owner and admin retain the existing reason-required void flow.
- Resolved and voided incidents reject normal edits server-side.

The stable database status remains `resolved`; the Indonesian UI presents it as **Diselesaikan**. Reopen is intentionally not part of M2.2.1.

## Transaction and conflict behavior

`update_operational_incident` locks the incident, verifies authentication, active role, `open` status, current `updated_at`, tenant-safe machine/PIC references, and field validation. It then updates the incident and appends one revision atomically. Any exception rolls back both operations.

Edit drafts use the global sessionStorage draft layer with user/account/branch/feature/incident isolation. The draft records the incident `updated_at` value used as its base. A mismatch produces an explicit choice:

- **Use latest server data** discards the stale edit draft and hydrates the form from Supabase.
- **Restore my draft** preserves the operator's draft values while rebasing them on the latest server version.

Submission remains disabled until that choice is made. The RPC repeats the `updated_at` check so UI state cannot bypass concurrency protection.

## Solve workflow

`solve_operational_incident` locks and authorizes the open incident, then records `resolved`, `resolved_by`, `resolved_at`, and an optional `resolution_note`. The confirmation UI summarizes cause, prevention, customer resolution, and current assessed loss before completion.

`assessed_loss` remains a generated PostgreSQL value. Neither Edit Log nor its draft authors it directly.
