# M2.12 data cutover matrix

This matrix is a planning artifact. No Production import is executed in M2.12. Final source selection requires a freeze snapshot and explicit approval.

| Domain | Source | Transformation / identity | Target | Validation | Rollback / readiness |
|---|---|---|---|---|---|
| Accounts, branches, machines | accepted legacy snapshot plus approved DEV delta | natural codes; explicit crosswalk; never DEV UUID equality | Laravel account/branch/machine tables | counts, codes, scope | restore target backup; READY after snapshot |
| Auth users, memberships | approved identity export / explicit invitations | email/username crosswalk; new MySQL UUIDs; reset credentials | users, memberships | login, disabled denial, membership matrix | revoke/import retry; approval required |
| Operational people and branch assignments | accepted snapshot plus reviewed DEV configuration | person code + branch code; capability is current config only | operational people/branches | active assignment and capability checks | delete nothing; reconcile by crosswalk |
| Counter readings | accepted historical snapshot plus final frozen delta | preserve reading/operator snapshots, timestamps, corrections | counter readings/history | row counts, period totals, snapshot labels | restore DB; immutable history |
| Component catalog, profiles, machine components | accepted snapshot plus reviewed Graha setup delta | catalog/model/slot natural keys; preserve slot codes | catalog/profile/assignment tables | slot uniqueness, exclusions, active assignments | restore DB; no history rewrite |
| Lifecycles and replacements | accepted evidence plus approved final snapshot | preserve lifecycle/replacement timestamps and cost evidence | lifecycle/replacement tables | counts, FIFO allocations, unknown costs | restore DB; immutable evidence |
| Inventory items, locations, purchases, receipts, openings, movements | accepted ledger plus approved stock-opname delta | ledger identity/crosswalk; purchases/receipts/opening are distinct | inventory tables | movement/layer/allocation totals | restore DB; no manual cleanup |
| Incidents | accepted incidents plus approved frozen delta | preserve machine attribution, Operator/PIC snapshots, assessed loss | incidents | count, loss, branch/machine scope | restore DB; immutable evidence |
| Settings | explicit approved configuration | current values only; no secret migration | settings/config | version/config smoke | restore env/config backup |

Post-legacy DEV examples are classified as follows: Counter Operator capability and Graha component setup are **RECREATE AS CONFIG / REVIEW REQUIRED**; Opening Balances are **SOURCE FROM FINAL SNAPSHOT** only when approved; new counters/incidents/replacements are **IMPORT** only when present in the frozen accepted snapshot; rehearsal/test rows are **EXCLUDE AS TEST**. No individual row is silently promoted.

Imports must be idempotent using source identity, natural keys, client request IDs, and a durable crosswalk artifact. Validation compares source counts, accepted dispositions, target counts, counter totals, incident loss, purchase/receipt/movement/lifecycle/replacement counts, and operator snapshots. Existing accepted fingerprints under `scripts/migration/reconciliation/` are evidence and must not be rewritten.
