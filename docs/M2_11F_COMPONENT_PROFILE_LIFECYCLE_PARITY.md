# M2.11F — Component, Profile, Assignment and Lifecycle Parity

Laravel adds separate `component_catalogs`, `model_profiles`, `model_profile_slots`,
`machine_components`, `machine_component_exclusions`, and `component_lifecycles`
tables. A catalog row is a reusable logical definition; a profile is scoped to one
machine model; slots are durable identities (so repeated `component_id` values are
valid); machine components are persistent physical assignments; and lifecycles are
optional evidence intervals attached to those assignments.

Profile synchronization is transactional, model-scoped and idempotent. It creates
inherited assignments in deterministic display order, skips active machine-level
exclusions, and never creates lifecycle rows. Manual assignments use their own slot
code and never modify the profile. Exclusion is allowed only for inherited
assignments with no lifecycle history, retires the assignment, and remains durable
until explicitly cleared. Profile/catalog archive is forward-only and reference
protected; restoring a catalog does not restore children.

Lifecycle initialization is explicit. Zero lifecycle rows means `UNKNOWN`; no date
is fabricated. The service locks the assignment, rejects a second active lifecycle,
supports a client request key, and preserves A–D evidence-level representation for
future migration. MySQL uses nullable `active_key` columns plus composite unique
indexes as the portable equivalent of PostgreSQL partial unique indexes.

Inventory, purchasing, replacement, errors, cost and reports are intentionally
deferred to M2.11G and later.
