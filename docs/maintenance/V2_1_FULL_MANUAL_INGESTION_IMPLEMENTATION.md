# Maintenance V2.1 Full Manual Ingestion Safety Implementation

Status: implementation and disposable rehearsal complete. This work does not authorize or perform Production access, deployment, migration, preview, or ingestion.

## Authoritative contracts

- Source PDF SHA-256: `ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4`.
- Reviewed eligible dataset digest: `e6ef17a74c8b9c92a26d03c9b856e1244d4f215c3db806adfb4fdaed52e05775`.
- Contract: `konica-c1070-v1.5-full-reviewed`, version `v1`.
- Parser/extractor: `semantic-error-parser/v2.1-full-manual-1` and `pypdf/6.14.2-layout`.
- Reviewed physical pages: 1,412 through 1,670 inclusive.
- Metadata-only fixture: 628 identities with section, variant, outcome, page range, raw-section hash, normalized entry digest, child totals, safety flags, and diagnostics. Its own SHA-256 is pinned in code. It contains no extracted manufacturer text.

The canonical parser reproduced the reviewed contract twice from the real PDF: 628 discovered, 625 PASS, 1 WARN, 2 FAIL, 625 eligible, 617 distinct discovered codes, 614 distinct eligible codes, zero collisions, zero unresolved applicability, and zero unverified boundaries. C-1547, C-C131, and C-D0F8 remain excluded without placeholder steps.

## Safety implementation

`maintenance:v2-ingest-full-manual` has three mutually exclusive modes: read-only preview, atomic apply/no-op rerun, and read-only verification. Apply requires the exact document UUID, canonical stored PDF path, source SHA, dataset digest, discovered/eligible counts, contract, and runtime environment. Production additionally requires the contract-specific confirmation token and separate external authorization.

The resolver accepts only an active published PDF backed by a local storage disk, resolves it inside that disk root, requires `--pdf` to resolve to that exact canonical file, validates stored size metadata, copies it to a distinct mode-0600 snapshot, and verifies the snapshot hash. Cleanup compares snapshot and source realpaths before unlinking. It cannot delete the authoritative path if the paths alias. Apply re-resolves storage metadata and re-hashes the authoritative file under the document-row lock and again before commit.

Preview validates the pinned metadata inventory and dataset digest, plans all actions, captures protected legacy/Documents/Tickets counts and canonical fingerprints, and performs no database mutation. Apply repeats the plan after acquiring the document-row lock. Any mixed state or UPDATE is refused. All run, parent, and child writes occur in one transaction; only a matching `COMPLETED` run is authoritative. Child inserts are bounded bulk inserts inside that transaction.

Every parent records a canonical normalized digest and producing run. The run records source storage identity, PDF SHA, parser/extractor/release revisions, contract version, dataset digest, reviewed/action/persisted/safety totals, and timestamps. Detail API provenance exposes non-sensitive run identity/digests/revisions but not private storage paths.

The post-ingestion verifier reconciles the completed run, all 625 exact reviewed identities and normalized digests, raw hashes/text, pages, ordered children/references, counts, duplicate/orphan state, contiguous steps, safety totals, C-1103 variants, and the dataset digest. Protected legacy, Documents, and Tickets fingerprints must remain unchanged across apply.

## Disposable rehearsal

SQLite and a fresh local MySQL 9.7.1 database both ran the real authoritative PDF. The MySQL command rehearsal produced:

- preview: 625 CREATED, 0 UNCHANGED, 0 UPDATED, 3 REJECTED, no mutation;
- first apply: one committed run, 625 parents, 636 applicabilities, 2,011 parts, 3,877 steps, and 1,538 references; verifier PASS; 5.01 seconds;
- identical apply: the same run, 0 CREATED, 625 UNCHANGED, 0 UPDATED, 3 REJECTED, no mutation; 3.90 seconds;
- explicit verify: PASS;
- concurrency: two simultaneous first applies yielded one success and one fail-closed plan-change refusal, with exactly one completed run and 625 parents;
- failure injection at early, middle, final-child, reconciliation, and run-completion checkpoints left zero run/parent/child rows after each rollback;
- protected legacy/Documents/Tickets fingerprints were unchanged.

Both task-specific MySQL databases were dropped after verification. The source PDF retained the same canonical path, inode, byte size, modification timestamp, permissions, and SHA-256, and no temporary snapshots remained.

## Regression

- Complete Laravel SQLite suite: PASS, 83 passed / 4,040 assertions (repository/runtime deprecation notices only).
- Focused real-PDF SQLite rehearsal: PASS, 36 assertions.
- Focused real-PDF MySQL 9.7.1 rehearsal: PASS, 59 assertions across the safety and contract tests.
- Maintenance UI: PASS, 41 tests.
- Complete Node suite: PASS, 732 passed / 2 skipped.
- Vite production build: PASS.
- Focused Pint: PASS.
- `git diff --check`: PASS.

## Operational boundary

No Production access, database access, migration, preview, ingestion, deployment, or modification occurred. A future Production operation must begin with schema deployment and a fresh read-only preview under separate authorization. It must not infer authorization from this implementation gate.

## Gate

`V2_1_FULL_MANUAL_INGESTION_IMPLEMENTATION=PASS`

`READY_FOR_PRODUCTION_SCHEMA_DEPLOY_AND_PREVIEW=YES`

STOP. DO NOT DEPLOY. DO NOT RUN PRODUCTION PREVIEW. DO NOT INGEST.
