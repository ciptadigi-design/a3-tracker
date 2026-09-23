# Maintenance V2.1 Full Manual Ingestion Design

Status: design and read-only validation only. This document does not authorize ingestion, Production access, deployment, migration, or `--apply`.

## 1. Baseline and scope

- Audited branch: `develop`.
- Audited repository SHA and `origin/develop`: `476709c817dd59bf20e3fb88718f4656ff096f63`.
- Source: `bizhub_PRESS_C1070_C1070P_C1060_PRO_C1060L_E_SM_v1.5.pdf`, SHA-256 `ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4`.
- Prior gate: `V2_1_FULL_MANUAL_DRY_RUN=PASS`; `READY_FOR_FULL_MANUAL_INGESTION_DESIGN=YES`.
- The audit reproduced the parser result locally, without database writes: 628 entries, 625 PASS, 1 WARN, 2 FAIL. It did not run the ingestion command.
- Existing M2.12E/M2.12F work was present before this task and is outside this design.

The official tables remain independent of `machine_error_codes`, `maintenance_error_solutions`, `maintenance_tickets`, and every legacy candidate/import/extraction/review table. No bridge, backfill, translation, simplification, or placeholder solution is permitted.

## 2. Current model audit

### Capacity

The current five-table schema can hold all 625 eligible v1.5 entries without widening any existing content column. Read-only reproduction of the eligible set found:

| Field | Observed maximum | Current type/limit | Result |
|---|---:|---|---|
| `code` | 6 characters | `varchar(64)` | safe |
| `variant_key` | 13 characters | `varchar(160)` | safe |
| entry `section_number` | 7 characters | `varchar(80)` | safe |
| applicability label | 15 characters | `varchar(160)` | safe |
| part name | 90 characters | `varchar(200)` | safe |
| reference type | 15 characters | `varchar(40)` | safe |
| reference value | 184 characters | `text` | safe |
| reference section | 8 characters | `varchar(80)` | safe |
| source hash | exactly 64 ASCII characters | `char(64)` | exact |
| page number | at most 1,670 in the eligible chapter data (PDF has 2,639 pages) | unsigned integer | safe |
| raw source section | 3,153 bytes / 3,122 characters | `longtext` | safe |
| largest normalized `text` value | note: 1,378 bytes | `text` | safe |
| largest step instruction | 521 bytes | `text` | safe |

The eligible raw sections total only 868,038 bytes. The planned write is 8,687 official rows: 625 parents, 636 applicabilities, 2,011 parts, 3,877 steps, and 1,538 references.

### Identity and indexes

Identity remains `(document_id, code, variant_key)`. All 628 discovered identities are distinct after the same normalization used by `MaintenanceOfficialErrorEntry`; the eligible subset has 625 distinct identities. Values are normalized to uppercase ASCII using only letters, digits, `_`, and `-`. On the configured `utf8mb4_unicode_ci` MySQL/MariaDB collation, the unique key therefore enforces the intended case-insensitive normalized identity. Its worst declared payload is approximately 1,040 bytes under `utf8mb4`, below the 3,072-byte modern InnoDB index-key limit used by the validated Hostinger MariaDB 11.8/InnoDB environment. The observed keys are much smaller. A future implementation test must nevertheless insert the complete metadata-only identity fixture on MySQL/MariaDB, not infer collation safety from SQLite.

The existing eight-case and 50-case providers are hard-coded selector manifests and cannot represent full discovery, exclusions, reviewed counts, or a dataset digest. They do not constrain the schema or service, but the current command cannot safely perform the full ingestion and must not be repurposed by simply adding 628 selectors.

### Update, provenance, and atomicity

- An actual update keeps the parent UUID and, inside the transaction, deletes references before steps and then replaces all steps, parts, applicabilities, and references. That replacement order and parent stability are safe.
- The entry model stores exact `raw_source_text` and recomputes its SHA-256 on every save; the service also rejects a DTO whose supplied section hash does not match its raw text. Section-level provenance is therefore exact.
- Current provenance is not sufficient for source revision proof. A section hash proves only section bytes. Neither `maintenance_documents` nor an official entry records the PDF SHA, parser revision, reviewed dataset digest, or completed ingestion run. Replacing the stored PDF later would make v1.5 versus v1.6 unprovable from official rows alone.
- Current `UNCHANGED` compares only `source_hash`. A parser revision that produces different normalized fields/children from the same raw section would incorrectly be called unchanged. Full-manual ingestion must compare a canonical per-entry digest instead.
- `ingestBatch()` uses one outer transaction, so current batch writes are atomic. Planning occurs before that transaction and missing identities cannot be row-locked; the full implementation must also lock the document row and repeat contract/action preflight inside the transaction to serialize concurrent runs.

No existing column-width/type/index migration is required.

## 3. Minimum source-revision contract

Add one small audit entity, `maintenance_official_ingestion_runs`, and link current official parents to it. This is less duplication and stronger evidence than copying the PDF SHA/parser/dataset fields onto 625 rows.

Minimum run fields:

| Field | Contract |
|---|---|
| `id` | UUID primary key |
| `document_id` | required FK to `maintenance_documents`, restricted delete |
| `source_file_name` | basename/display evidence only; never identity |
| `source_sha256` | required lowercase `char(64)` |
| `parser_revision` | required explicit parser contract identifier |
| `release_sha` | required exact 40-hex application Git SHA |
| `contract_name`, `contract_version` | required reviewed-contract identity |
| `dataset_digest` | required lowercase `char(64)`, unique with document/source as appropriate |
| reviewed counts | discovered, PASS, WARN, FAIL, eligible, distinct discovered codes |
| persisted totals | parents and all four child totals; CREATED/UNCHANGED/UPDATED/REJECTED |
| safety totals | eligible warning-, DIPSW-, and detached-control-bearing entries |
| `status` | only `COMPLETED` is externally authoritative for V2.1 |
| timestamps | started/completed and normal timestamps |

Add nullable `ingestion_run_id` (indexed, restricted FK) and nullable `normalized_digest char(64)` to `maintenance_official_error_entries`. Nullable schema columns keep the migration additive if non-full fixture data already exists; the full v1.5 service requires both values and accepts no unlinked existing row. They may be tightened only after every environment has an explicit backfill/disposition.

Create the run, write/link all entries, reconcile totals, and set `COMPLETED` in the same transaction. A failed transaction leaves no authoritative run. Command logs may describe a failed attempt, but only a committed `COMPLETED` row proves application. This preserves the current-snapshot model; it is not a historical snapshot architecture.

Use a small explicit parser identifier such as `semantic-error-parser/v2.1-full-manual-1`. Record the exact release Git SHA and the locked extractor identity (`pypdf 6.14.2`, layout mode) too. The explicit identifier says which behavior contract was intended, the release SHA makes its code recoverable, and the dataset digest detects any dependency/environment/output drift. Git SHA alone is insufficient because a later command-only commit changes it; a version string alone is insufficient because it does not locate code.

## 4. Eligibility

The only default policy is `PASS_ONLY`:

- PASS: eligible, subject to every reviewed-contract gate.
- WARN: visible in preview/evidence and rejected from persistence unless a future, separately reviewed contract explicitly promotes that exact identity. No automatic promotion exists.
- FAIL: never eligible.
- `C-1547` / section `2.14.8` / `PB`: WARN, `NO_SOLUTION_STEPS`; excluded. Its manufacturer safety content remains in the evidence report only.
- `C-C131` / section `2.25.17` / `MAIN_BODY`: FAIL; empty manufacturer solution item; excluded.
- `C-D0F8` / section `2.26.21` / `MAIN_BODY`: FAIL; empty manufacturer solution item; excluded.

Never create a fake step such as “No solution provided.” The reviewed contract stores excluded identity, outcome, diagnostic code/severity, section hash, pages, and safety-bearing flags. Preview/apply evidence prints these three exclusions and `REJECTED=3`; no official parent or child is created for them.

## 5. Reviewed dataset contract and digest

Commit a metadata-only contract fixture; do not commit the PDF or extracted manufacturer text. The contract is immutable by name/version and contains source SHA, parser/extractor revision, the reviewed physical-page acquisition range 1,412–1,670 inclusive plus its verified terminal marker, the expected identity/page/source-hash/entry-digest inventory, the exact exclusions, and these gates:

```text
DISCOVERED=628
PASS=625
WARN=1
FAIL=2
ELIGIBLE=625
DISTINCT_CODES_DISCOVERED=617
DISTINCT_CODES_ELIGIBLE=614
IDENTITY_COLLISIONS=0
UNRESOLVED_APPLICABILITY=0
VERIFIED_NEXT_HEADING_BOUNDARIES=606
VERIFIED_STRUCTURAL_TERMINAL_BOUNDARIES=21
VERIFIED_CHAPTER_TERMINAL_BOUNDARIES=1
UNVERIFIED_BOUNDARIES=0
ELIGIBLE_APPLICABILITIES=636
ELIGIBLE_PARTS=2011
ELIGIBLE_STEPS=3877
ELIGIBLE_REFERENCES=1538
ELIGIBLE_WARNING_BEARING=53
ELIGIBLE_DIPSW_BEARING=242
ELIGIBLE_DETACHED_CONTROL_BEARING=204
```

`DISTINCT_CODES_ELIGIBLE=614`, not 617: each of the three excluded entries has a code not duplicated by an eligible variant.

For these gates, warning-bearing means a non-null normalized warning field; DIPSW-bearing means `DIPSW` occurs in any normalized scalar field or ordered step (covering both the dedicated field and embedded safety instructions); detached-control-bearing means a non-null normalized detached-control field. These definitions reproduce the reviewed 53/242/204 counts and must be shared by preview and verification.

The canonical eligible dataset representation is UTF-8 JSON with no insignificant whitespace and this top-level key order: `schema`, `source_pdf_sha256`, `entries`. `schema` is `maintenance-official-eligible-dataset/v1`. Entries are bytewise sorted by `(code, variant_key, section_number, raw_source_hash)`. Every entry includes, in fixed key order:

1. section number, normalized code, normalized variant key, page start/end, raw-section SHA-256, and outcome;
2. classification, cause, alert measure, correction, warning, note, isolation DIPSW, and detached control, retaining `null` versus empty-array distinctions;
3. applicability labels and parts in source order;
4. steps in source order as `{number,instruction}`;
5. references sorted bytewise by canonical `{step_number,type,value,page_number,section_number}` JSON;
6. diagnostics sorted bytewise by canonical `{code,severity,message,context}` JSON, with context object keys bytewise sorted.

JSON encoding uses unescaped Unicode and slashes, preserves zero fractions, emits no trailing newline, and rejects invalid UTF-8. Array order is significant. SHA-256 over those exact JSON bytes is the dataset digest. The same entry object without the top-level wrapper is each parent's `normalized_digest`. Raw text need not be duplicated in the contract because its SHA is included; all persisted normalized content is included in the digest.

Read-only reproduction under this exact proposed v1 canonicalization yielded:

```text
ELIGIBLE_DATASET_DIGEST=e6ef17a74c8b9c92a26d03c9b856e1244d4f215c3db806adfb4fdaed52e05775
```

The earlier `2240175a...` digest remains valid evidence for that dry-run report's metadata algorithm, but is not silently reused as this eligible persistence digest. Before implementation authorization, the production command's canonicalizer and full metadata fixture must reproduce `e6ef17a...` in two independent runs. Any mismatch requires review and a new contract version; apply must never accept “whatever currently passes.”

## 6. Preview contract

Preview performs file acquisition, parsing, validation, digest construction, database reads, and action planning only. It writes no run and no official row. Machine-readable JSON is authoritative; concise console lines may summarize it. It reports:

- actual and expected PDF SHA, authoritative document UUID, canonical stored PDF path/disk, source file name;
- parser revision, extractor revision, release SHA, contract name/version, actual and expected dataset digest;
- discovered/PASS/WARN/FAIL/eligible and discovered/eligible distinct-code counts;
- CREATED/UNCHANGED/UPDATED/REJECTED and expected parent count after apply;
- applicability/part/step/reference totals;
- every excluded identity with outcome, diagnostics, page range, section hash, and safety flags;
- identity collisions, all boundary categories/failures, unresolved applicability;
- warning-, DIPSW-, and detached-control-bearing eligible counts;
- current official counts for the document plus legacy Tickets/Documents/legacy-table baseline counts/fingerprints;
- `contract_match`, `mutation_performed=false`, and a non-zero exit on any mismatch.

For the first empty-table v1.5 preview, actions must be CREATED 625, UNCHANGED 0, UPDATED 0, REJECTED 3, expected parents 625. An identical rerun after a successful apply must be CREATED 0, UNCHANGED 625, UPDATED 0, REJECTED 3, expected parents 625. Any mixed state or unexpected UPDATED blocks V2.1 apply.

## 7. Apply contract

The full command must require all of the following, with no default for an identity-bearing argument:

```text
--document=<exact UUID>
--pdf=<exact canonical local path resolved from that document's storage metadata>
--sha256=ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4
--dataset-digest=e6ef17a74c8b9c92a26d03c9b856e1244d4f215c3db806adfb4fdaed52e05775
--expected-discovered=628
--expected-eligible=625
--contract=konica-c1070-v1.5-full-v1
--expected-environment=<actual APP_ENV>
--apply
```

Production additionally requires a typed, contract-specific token such as `--confirm=APPLY-625-e6ef17a74c8b` and separate human authorization recorded outside the command. The token is useful because the present command deliberately forbids Production; replacing that absolute guard must require an unmistakable opt-in, not a generic `--force`. Preview never accepts or needs the token. This design does not provide the authorization.

Before opening the transaction, apply repeats the entire preview from a mode-0600 temporary snapshot copied from the authoritative stored file, verifies the snapshot SHA, and removes it on exit. The snapshot is transient processing input, not a second supplied authority. Inside one transaction it locks the document row, verifies storage metadata is unchanged, re-hashes the authoritative file before commit to detect replacement, revalidates the contract/action plan, creates the run, persists in deterministic identity order, reconciles all rows/digests/counts, marks the run completed, and commits. Any source, count, outcome, identity, boundary, digest, path, environment, action, or expected-total mismatch exits non-zero and commits nothing.

First apply additionally requires zero existing official parents for the document and exactly 625 CREATED. An identical rerun is a verified no-op: it requires one matching completed run and exactly 625 UNCHANGED, creates no second run, and changes no UUID/timestamp/child row. UPDATED is forbidden in the v1.5 initial contract. A future revision-update mode requires a new reviewed contract and explicit revision semantics.

## 8. Atomicity and scale

Choose **A: one transaction for all 625 eligible entries**.

This actual dataset is small for InnoDB: 8,687 rows, less than 0.9 MB of raw-section text, maximum 16 steps per entry, and no existing rows on first apply. PDF extraction and parsing occur before the transaction, so the transaction holds only database work. One document-row lock plus deterministic identity order provides serialization. A command-line process avoids HTTP request limits. The implementation should use prepared/bulk inserts for children where practical and measure a full disposable MariaDB rehearsal, but should not trade atomicity for speculative optimization.

Per-entry transactions can expose a partial reviewed dataset as apparently usable and require complex recovery state. Chunking has the same problem and would need a hidden/staged generation plus activation mechanism. Neither complexity is justified for 8,687 rows. A failed single transaction rolls back the run, parents, and children together. Only a completed run is authoritative.

## 9. Stale and future revisions

Absence from a later PDF is not an update and must never cause automatic deletion. Before the first v1.6 apply, add an explicit state such as `ACTIVE` / `STALE` plus `stale_since_run_id` to parents. In the new revision's single transaction:

- identities present in the new eligible set become/remain ACTIVE and link to the new completed run when changed;
- a previously ACTIVE identity absent from the reviewed new source becomes STALE, retaining its last content, parent UUID, and producing-run provenance;
- stale rows are excluded from default product reads but available through an explicit audit filter;
- reappearance can reactivate the identity under a later reviewed run;
- physical delete is never part of source reconciliation.

That migration and behavior may be deferred because v1.5 is the first population, but the v1.5 run link preserves the provenance needed to add it safely. Historical versions of changed content are still outside the current-snapshot V2.1 model; if full historical reconstruction becomes a requirement, add immutable snapshots rather than overloading stale state.

## 10. Production source file

Use **the existing private stored Maintenance Document file** as the one authoritative PDF. Do not upload a second deployment copy.

The selected document must have `storage_disk=local`, a non-null `file_path`, PDF metadata, and an existing regular file resolved through Laravel `Storage::disk($document->storage_disk)`. The resolved path must remain inside that disk's configured root. `--pdf` is still required for operator visibility but must resolve to exactly that canonical stored path; symlink/path alias differences are rejected after canonicalization. External URL/reference-only documents are ineligible. Compare stored size where present, copy to the private temporary snapshot without mutating the source, hash its bytes, and require the reviewed SHA. Record the disk-relative path in the run/report; do not expose private absolute paths through the API.

The command must re-read the document row under lock before persistence. Replacing the document file between preview and apply causes storage metadata/file hash mismatch and refusal.

## 11. Rollback and recovery

Keep the three rollback domains separate:

- **Application rollback:** follow `docs/production/ROLLBACK_RUNBOOK.md`; repoint the release and reset OPcache only after proving the prior code tolerates the additive schema. It does not remove ingested data.
- **Migration rollback:** do not run `migrate:rollback` in Production. The run table and nullable parent columns are additive and may remain during an application rollback. Schema reversal is not data-ingestion recovery.
- **Data-ingestion rollback:** for first population, retain a validated `BACKUP_GATE=PASS` database backup from immediately before apply, the run UUID, exact created parent UUIDs, pre/post counts, and report digest. If the transaction fails, verify it rolled back and do nothing destructive. If commit succeeds but integrity fails, freeze further official ingestion and product exposure.

For a failure proven to affect only this first completed run, a separately reviewed recovery operation may delete exactly the 625 parents linked to that run (children cascade) and then the run, in one transaction, only when: the pre-apply document parent count was zero; all 625 expected UUIDs/digests match the recovery manifest; no later run or external dependency exists; and legacy/Documents/Tickets fingerprints are unchanged. It must preview before deletion and require separate typed authorization. No such destructive command is implemented or authorized here.

If any boundary is uncertain, other Production data changed unexpectedly, or targeted recovery preconditions fail, use the provider-approved full database restore from the validated backup under the canonical rollback runbook. Never improvise row deletes. A restore can lose unrelated writes after the backup, so use a maintenance window and take a fresh backup immediately before apply if the release backup is no longer current.

## 12. Production release and ingestion order

Follow `docs/production/RELEASE_PROCEDURE.md`; the ingestion is a separately authorized post-deploy operation:

1. Pin the exact 40-hex implementation SHA; require clean reviewed source and exact-SHA Database CI/Laravel MySQL CI. Build and verify the Laravel-targeted frontend artifact as the runbook requires.
2. On Production, run the canonical backup script from the current release and require `BACKUP_GATE=PASS`. Record pre-release table counts/fingerprints. Do not expose secrets in evidence.
3. Create a fresh `releases/<sha>` checkout, install production Composer dependencies, set/verify release identity, link shared `.env` and storage, and require `RELEASE_PREFLIGHT=PASS`.
4. From the staged release, inspect `migrate:status` and `migrate --pretend`; confirm only reviewed additive changes. Run `migrate --force` if authorized.
5. While the old/current application is still available, smoke existing Tickets and Maintenance Documents against the additive schema.
6. Sync the verified frontend, atomically switch `current`, reset web OPcache, execute canonical health/login/version smoke, and verify the exact release SHA.
7. Verify the selected Maintenance Document record/file mapping and hash; verify this document has zero official parents and there is no completed v1.5 run. Snapshot legacy, Tickets, and Documents counts/fingerprints.
8. Run the full preview from the active exact release using the authoritative stored PDF. Preserve its JSON and compare every count, exclusion, action, boundary, safety total, source SHA, parser revision, and `e6ef17a...` digest to the reviewed contract.
9. Stop. Obtain separate ingestion authorization. If time or Production state changed, repeat the backup gate and preview; use a maintenance window for apply/recovery safety.
10. Run apply once with all exact arguments and the typed token. Do not proceed on any mismatch, unexpected UPDATE, or non-zero exit.
11. Run the database integrity verifier and preserve its report; then perform official API checks.
12. Re-run existing Maintenance Documents and Tickets regression/smoke and compare all protected-table fingerprints.
13. Record acceptance, run UUID, report hashes, operator/timestamps, backup ID/hash, release SHA, and exact command with secrets/private absolute paths redacted.

No deployment or Production step was executed for this design.

## 13. Post-ingestion integrity contract

The verifier must use database queries plus recomputation from the reviewed DTOs. Success requires all of the following:

```text
completed matching ingestion runs = 1
official parents for document = 625
distinct eligible code strings = 614
distinct (document, code, variant_key) = 625
duplicate identities = 0
applicabilities = 636
parts = 2011
steps = 3877
references = 1538
orphan children = 0 in every child table
unresolved applicability = 0
invalid/non-contiguous per-entry step sequences = 0
warning-bearing eligible entries = 53
DIPSW-bearing eligible entries = 242
detached-control-bearing eligible entries = 204
```

Additionally:

- every parent run link, per-entry normalized digest, raw source hash, exact raw text hash, section, and page range matches the reviewed DTO;
- recomputed eligible dataset digest is `e6ef17a74c8b9c92a26d03c9b856e1244d4f215c3db806adfb4fdaed52e05775`;
- `C-1103` has exactly two variants: `FS-531_FS-612` and `FS-532`;
- letter-bearing `C-Cxxx`, `C-Dxxx`, `C-Exxx`, and real `C-6F01` eligible identities match the contract;
- all 53 eligible warning strings match exactly; all 242 eligible DIPSW-bearing and 204 detached-control-bearing structures remain represented exactly;
- C-1547, C-C131, and C-D0F8 have no official parent for this document and appear in run evidence as rejected exclusions;
- the completed run records the correct PDF SHA, parser/extractor revision, release SHA, contract, counts, and child/safety totals;
- all snapshotted legacy tables, `maintenance_tickets`, and `maintenance_documents` retain their pre-apply counts/fingerprints (apart from the new run's read-only FK relationship; the document row itself is unchanged).

## 14. API verification readiness

The existing authenticated list endpoint can filter by document/code and return all 625 summaries; detail returns every normalized field, ordered child set, raw source, section hash, and page range. It is sufficient for product-boundary inspection and deterministic spot/full iteration. No frontend is needed.

It is not the authoritative batch-integrity tool: it has no run-level filter/metadata today, no aggregate/digest endpoint, and would require up to 625 detail requests. The implementation should expose the linked completed run's non-sensitive source SHA/parser/dataset metadata in detail provenance (and optionally list provenance), but must keep absolute private paths hidden. Database/report verification remains mandatory even with that small API addition.

## 15. Required implementation before ingestion

No existing width/type change is required. The following minimal code is required before full ingestion can be authorized:

1. additive ingestion-run migration/model; parent `ingestion_run_id` and `normalized_digest`;
2. metadata-only full reviewed-contract fixture/provider, including all expected identities, exclusions, counts, source hashes, entry digests, and acquisition boundaries;
3. canonical entry/dataset digest implementation reproducing `e6ef17a...`;
4. full preview/apply command contract, authoritative stored-file resolver, Production typed guard, JSON evidence, and fail-closed comparison;
5. ingestion service support for run linkage, canonical equality, document locking, in-transaction revalidation/reconciliation, and exact no-op rerun behavior;
6. non-sensitive run provenance in the official detail API;
7. post-ingestion integrity verifier.

Do not change the existing legacy tables or ingestion boundaries. Do not implement stale mutation until a later source revision is actually designed.

## 16. Required tests and authorization gates

Before any full apply authorization:

- metadata-only 628-entry reviewed-contract fixture reproduces all counts, 625 identities, 614 eligible codes, child/safety totals, exclusions, and `e6ef17a...` twice;
- exact source SHA mismatch, source path/document mapping mismatch, parser/extractor revision mismatch, digest mismatch, discovered/eligible count mismatch, outcome drift, new WARN/FAIL, identity collision, unresolved applicability, and any unverified boundary each refuse with zero writes;
- PASS persists; WARN and FAIL remain reported/rejected; C-1547/C-C131/C-D0F8 never become parents and no placeholder step exists;
- complete identity fixture inserts successfully under the target MySQL/MariaDB charset/collation; letter-bearing codes remain distinct;
- C-1103 retains its two exact variants;
- normalized warning, DIPSW, detached-control, step, reference, and raw-source values round-trip without loss;
- a parser-only normalized change with unchanged raw source hash is detected by `normalized_digest` and is not mislabeled UNCHANGED;
- injected failures at early, middle, final-child, reconciliation, and run-completion stages leave zero run/parent/child writes;
- first apply is exactly 625 CREATED; identical rerun is exactly 625 UNCHANGED with stable run, parent UUIDs, child UUIDs, and timestamps; any unexpected UPDATED or mixed state refuses;
- concurrent apply attempts serialize on the document; only one completed run can result;
- post-ingestion verifier catches duplicate/orphan/step-sequence/hash/page/digest/count/safety corruption;
- protected legacy/Documents/Tickets fingerprints are unchanged;
- environment tests prove Production preview is read-only, Production apply refuses without the exact typed token, non-Production/Production expectation mismatch refuses, and all tests use disposable local MySQL/MariaDB only—never Production;
- a full exact-PDF local rehearsal and rollback-injected rehearsal pass on disposable MySQL/MariaDB, with measured transaction duration below the agreed Hostinger maintenance-window/CLI limits.

## 17. Risks and recommendation

Primary risks are source-file replacement between preview/apply, raw-hash-only false UNCHANGED behavior, incorrect canonicalization, collation assumptions tested only on SQLite, concurrent runs, shared-host CLI/time limits, and a post-commit recovery that could affect unrelated Production writes. The design closes these with authoritative storage mapping, PDF/dataset hashes, normalized per-entry digests, a metadata fixture, MariaDB rehearsal, document locking, one atomic transaction, a completed-run marker, fresh backup, and fail-closed recovery gates.

Recommendation: `READY_FOR_FULL_MANUAL_INGESTION_IMPLEMENTATION`.

This means implementation and disposable verification may proceed in a separate task. It does not authorize ingestion, Production access, migration execution, or deployment.

## 18. Gate

`V2_1_FULL_MANUAL_INGESTION_DESIGN=PASS`

`READY_FOR_FULL_MANUAL_INGESTION_IMPLEMENTATION=YES`

STOP. DO NOT INGEST. DO NOT DEPLOY.
