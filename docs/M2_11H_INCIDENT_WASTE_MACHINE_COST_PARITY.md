# M2.11H Incident, Waste Attribution and Machine Cost Parity

Laravel now provides a tenant-scoped `operational_incidents` persistence path and a derived Machine Cost service. Operator and PIC Terlibat are separate operational-person references; eligible people must be active members of the selected branch and immutable name snapshots are captured at creation. A machine is optional, and branch-only incidents remain visible history but never enter a machine's cost.

`assessed_loss` is resolved once: a stored value is authoritative (protecting imported historical values from double multiplication); newly-created incidents calculate `(material_loss + service_loss) × penalty_multiplier`. Machine Cost adds only explicitly machine-attributed, non-voided incident loss and actual `component_replacements.consumed_cost` in the selected machine-local half-open period. Purchases, receipts, opening stock, and catalog prices are context only. Null replacement cost is surfaced as unknown evidence. Daily Counter effective usage is the click denominator; zero clicks yields an unavailable per-click value.

The Laravel API exposes incident list/create/detail and `GET /api/v1/machines/{machine}/cost`. Supabase remains the default frontend backend and behavioral oracle; no hosted data or migration disposition was changed. Advanced operating-cost allocation is intentionally deferred because the current Laravel schema has no reference-equivalent account toggle/attribution model. Reports and Maintenance remain outside this milestone.

Focused coverage should exercise machine/branch attribution, operator/PIC distinction and snapshots, branch and tenant authorization, idempotency, FIFO replacement cost, unknown evidence, timezone boundaries, and zero-click periods against MySQL/InnoDB.
