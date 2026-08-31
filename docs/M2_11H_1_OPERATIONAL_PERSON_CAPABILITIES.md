# M2.11H.1 Operational Person Capabilities

An Operational Person is an operational identity; an Auth User and account membership role are separate authorization concepts. `can_record_counter` is a branch-scoped capability on each Operational Person ↔ Branch assignment and defaults to `false`.

Eligibility is resolved per selected branch:

- Daily Counter Operator and Error Operator: active person, active branch assignment, and `can_record_counter = true`.
- Error PIC Terlibat: active person with an active assignment; counter capability is not required. A Counter Operator may also be the PIC for the same incident.

Archived people are excluded from new selectors. Revoking capability changes future eligibility only; counter and incident snapshots, operator identities, entered-by identities, and all historical evidence remain immutable.

Branch assignments can be independently configured in Settings with **Can record Daily Counter**. No capability is inferred from Auth roles or from historical PIC participation, and this change does not alter inventory, FIFO, counters, or Reports.
