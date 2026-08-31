# Bounded migration roadmap

1. **M2.11C Foundation** — Laravel 11 skeleton, local Docker MySQL/InnoDB, baseline migrations, `/health` + version, error envelope, Sanctum shell, API adapter shell, dual-stack CI.
2. **M2.11D Auth/governance** — users/profiles, platform privilege, account/membership/branch policies; parity tests. *(Implemented.)*
3. **M2.11E Masters/counters** — machines, models, branches, operational people, counters and timezone tests.
4. **M2.11F Components/lifecycle** — catalog, model profiles, assignments/exclusions, lifecycle transactions and locks. *(Implemented; exact-SHA CI pending.)*
5. **M2.11G Inventory/FIFO** — purchases vs receipts, immutable movements/lots, receiving, transfers, replacement consumption and idempotency.
6. **M2.11H Incidents/cost/reports** — snapshots, PIC rules, Machine Cost, standard/advanced policy, report queries and pagination.
7. **M2.11I Frontend cutover** — feature-by-feature adapter switch against golden fixtures; no fallback.
8. **M2.11J Legacy adapter** — five approved source tables, snapshots/fingerprints/disposition/crosswalk; opening stock only from physical opname.
9. **M2.11K Hostinger staging** — topology, limits, backups, cron/health, rehearsal and rollback.
10. **M2.12 Production cutover** — only after parity, independent export, go/no-go, DNS and rollback approval.

Keep Supabase migrations/pgTAP as reference specification until M2.12 acceptance; Laravel migrations become target source of truth after cutover.
### M2.11E — Machine, People & Daily Counter parity

Laravel operational-core schema, scoped APIs, timezone resolver, transactional counter service and audited corrections are implemented. Status: **PARITY_TESTED** (exact-SHA MySQL and reference CI green).
