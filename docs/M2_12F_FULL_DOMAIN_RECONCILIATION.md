# M2.12F — Full-domain reconciliation

Status: **PENDING_AUTHENTICATED_READ_ONLY_EVIDENCE**

Hostinger MariaDB is the intended authoritative Production store. The newer Supabase project `sxitqjxljoqsnpepymrl` is a read-only reconciliation source; the legacy project `wtslqxjwjqyjgcapfrrz` remains frozen historical provenance. Bulk database copying, DEV UUID copying, Production replacement, reseeding, and inferred stock writes are rejected.

## Confirmed Production baseline

Production already contains the C1070 configuration and history: 28 catalog records, one model profile with 28 slots, 28 machine assignments, and 47 closed lifecycle rows. It has zero active lifecycles and latest Total Impressions 1,441,597. These rows must not be migrated again. The legacy operational baseline also remains 183 counters, 161 purchases / 208 units / IDR 371,029,998, and 89 incidents after two source duplicates were skipped.

The Components failure was a projection defect. The combined code change now starts cards from `machine_components`, attaches an active lifecycle only when one exists, projects closed lifecycles once into Replacement History, resolves the latest effective Total Impressions reading, and enforces machine access scope. It does not fabricate active lifecycle evidence. The Settings operational-person adapter now calls one transactional full-set endpoint; omitted assignments are deactivated and lose counter capability atomically.

Machine Cost is `MIXED`: totals and reports are derived, while selling-price evidence, optional operating-cost evidence, lifecycle/replacement consumption, incidents, and counters are persisted source facts. Derived dashboard/report totals must not be copied. The count of newer-Supabase persisted inputs missing from Production remains unset until authenticated evidence is captured.

## Evidence blocker

The available publishable key received HTTP 401 from the newer Supabase REST API. Domain counts and classifications therefore remain deliberately unset rather than inferred. The machine-readable artifact at `docs/evidence/m2-12f-full-domain-reconciliation.json` records `null` for unresolved counts and zero planned writes.

Run `.m2-12f-full-domain-readonly-capture.sh --capture-read-only` with an authenticated, short-lived newer-Supabase bearer token. The script permits GET only, validates the exact project, captures only an allow-listed domain set, and reads Production MariaDB inside a `READ ONLY` transaction that is rolled back. Raw evidence is mode 600 under `.migration-private/m2-12f/`; it does not contain Supabase auth users or Production user rows.

## Reconciliation decision rules

- Crosswalk accounts, branches, machines, people, and configuration by canonical business keys; Production IDs remain authoritative.
- Match component configuration by model, normalized slot/component codes, tracking method, baseline, order, and status—not UUID.
- Match lifecycles by machine code, slot/component code, installed/removed counters, usage, and dates. Existing legacy matches are `ALREADY_MIGRATED_LEGACY`.
- Match counters by machine, type, timestamp, and value; purchases by business number/date/lines; incidents by machine/branch, time, code/type, people snapshots, loss inputs, and description.
- New post-freeze operational facts and canonical configuration remain approval-gated. Xerox and Graha provisioning remains deferred.
- Purchases never imply receipts, stock, movements, or FIFO. Report totals and calculated machine-cost totals are never migration facts.
- Supabase Auth identities and UUIDs are excluded. Production authentication remains locally provisioned and separate from operational people.

No whitelist write plan may be marked ready until every captured row has exactly one required classification and `UNEXPLAINED=0`. No Production mutation or deployment occurred in this checkpoint.
