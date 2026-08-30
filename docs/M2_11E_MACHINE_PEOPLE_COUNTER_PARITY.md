# M2.11E — Machine, People and Counter Parity

Laravel migrations now provide manufacturers (shared or account-scoped), machine models, account/branch machines, operational people, person↔branch assignments, counter types and immutable counter readings. Composite account foreign keys prevent cross-tenant machine, branch and operator relationships.

Operational authorization reuses the M2.11D account and branch resolvers. A machine write requires an active account, membership, branch context and active machine. Operational people are business identities, not Auth users; a new reading requires an active person assigned to the machine branch.

Counter submissions are transactional and lock the machine. `client_request_id` is idempotent, same-day readings are allowed, equal values produce zero usage, lower values and older timestamps are rejected, and the first reading is a baseline. Each row stores an immutable operator name snapshot and the authenticated actor separately. History is evidence and has no generic edit/delete endpoint.

`MachineTimezoneResolver` applies machine → branch → account default timezone and converts period boundaries to UTC. Period usage is the sum of effective rows' linked usage within the machine-local half-open range; no synthetic boundary counter is fabricated.

Laravel endpoints are under `/api/v1` and are opt-in through `VITE_DATA_BACKEND=laravel`; Supabase remains the current frontend default. Component, inventory, lifecycle, error, cost and report domains remain deferred.

## Verification

Local SQLite migrations and the existing Laravel suite pass. MySQL/InnoDB and reference PostgreSQL CI remain required milestone gates.
