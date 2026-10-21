# Assisted Knowledge Coverage Verification — 2026-10-21

Read-only. No Production data, knowledge, tickets, migrations or deploys were touched. Production at `c3a7def2e52162c77a465bc99349f6479bca22aa` (`/api/v1/version`, schema_batch 26).

## Original scope (recovered)
No written coverage criteria exist in the repo; the item is only named in `PHASE_3_3C_COMBINED_TROUBLESHOOTING_SEARCH.md` ("assisted-knowledge coverage") and the acceptance roadmap. Scope here is therefore the practical coverage of already-ingested official knowledge (C: 625 parents, reviewed contract `konica-c1070-v1.5-full-reviewed`; J: 376 entries / 371 codes, contract `konica-c1070-v1.5-jam-code-list`) plus the Phase 3.3 search behaviour. No new criteria were invented. The Indonesian simplified layer (`assisted-samples/id-v1.json`, 8 records) was not re-reviewed: its content is not part of this sample.

## Method and evidence labels
- **SRC** Parsed the source manual (SHA-256 `ae679f5b…`, matches the Production source) into a disposable in-memory DB, then compared each sampled entry (code, page range, classification, cause, correction, every step) to an independent plain-text extraction of the cited PDF pages. Reproduced datasets: C digest `e6ef17a7…` (628 entries), J digest `d4df7fce…` (376 accepted, 2 quarantined).
- **CI** automated tests (local run; Laravel MySQL/Database CI green on 842913b).
- **PROD** Import workflow run 39418813679 (`APPLY=COMMITTED`, `VERIFY=PASS`, 376 J entries, 625 C parents unchanged), public `/api/v1/version`.
- **USER** J-code search confirmed by the user (acceptance doc).
- Production field-experience rows were not read (no authenticated access; private data).

## Sample (12 table rows, 13 codes)
| Case | Code | Variant / page | Result | Evidence |
|---|---|---|---|---|
| C, official solution, single | C-3913 | MAIN_BODY, p1606, 5 steps | PASS | SRC all text/steps match |
| C, long procedure with references | C-0213 | MAIN_BODY, p1420-1421, 12 steps, 4 refs | PASS | SRC; also the field-experience test code |
| C, minimal | C-0001 | MAIN_BODY, p1412 | PASS | SRC |
| C, option variant | C-0102 | LU, p1413 | PASS | SRC |
| C, combined variant key | C-1101 | FS-531_FS-612, p1449 | PASS | SRC; stays distinct from J-1101 |
| J, single | J-1001 | BYPASS, p1310 | PASS | SRC (cause, resulting operation, correction) |
| J, single, numeric twin of C-1101 | J-1101 | TRAY1, p1310 | PASS | SRC + CI identity test |
| J, multiple options | J-7225 | FS and SD-510, p1319 | PASS | SRC; two separate entries, different causes/corrections; FS has no resulting operation in print and none is invented |
| J, multiple options | J-7226 | FS and SD-510, p1319 | PASS | SRC |
| J, multi-page cause | J-7243 | PK, p1320-1321 | PASS | cause continues on next page, verified manually (automated verbatim check flagged only because of page-break text interleave) |
| J, last page | J-9451 | ADU, p1334 | PASS | SRC |
| J, ambiguous in source | J-7272 (SD-510), J-7524 (FD) | p1321 / p1328 | GAP | quarantined, no official entry; search returns nothing for these two |

Mechanical cross-check on the full datasets: 628/628 C and 375/376 J entries match their cited pages verbatim; the single miss is J-7243 above (false positive).

## Search experience
| Behaviour | Result | Evidence |
|---|---|---|
| Exact code (`C-3913`, `J-1101`) | PASS | CI `MaintenanceOfficialKnowledgeTest`, `JamCodeIngestionTest` |
| Hyphen/case/space variants (`C3913`, `c-3913`, `j1101`, `cc152`, ` c 0213 `) | PASS | CI backend + 44 frontend node tests |
| C/J identity separation (official and field) | PASS | CI: J-1101 vs C-1101; field `C`/`J` never match each other |
| Numeric-only `0213` | PARTIAL → RESOLVED (see Resolved findings) | Field experience lists `C-0213` and `J-0213` separately. Official knowledge treats bare digits as the C code only (`MaintenanceOfficialErrorEntryController`), so a J procedure is reachable only by typing the J prefix. The UI note says so. 14 codes exist in both families (1101, 1201, 1251, 1501, 1502, 1551, 1552, 3101, 3102, 5101, 6702, 6703, 6704, 9401) |
| Official vs field distinguishable | PASS | separate groups "Pengetahuan Resmi" / "Pengalaman Lapangan", independent errors (CI `combinedSearchUi`) |
| Account-wide field experience | PASS | CI `test_search_spans_all_branches_of_the_account_and_never_another_account` |
| Ticket detail permissions | PASS | CI `test_can_open_mirrors_ticket_detail_access…`, restricted viewers get no names |
| Code with no field experience | PASS (CI) / BLOCKED (Production) | empty result is a normal response; absence is not treated as an official-knowledge gap |
| Production field-experience content | BLOCKED | needs authenticated access; private data |

## Classification
Content: PASS 11, GAP 1 (row covering two codes: J-7272 SD-510, J-7524 FD). Search: PARTIAL 1 (numeric-only official search is C-only), BLOCKED 1 (Production field-experience content), the rest PASS. Details above.

## Local test evidence
Backend maintenance suites (Jam, Official Knowledge, Ingestion, Field Experience, Assisted, Error Knowledge, Troubleshooting flow): 74 passed, 1 SQLite-only failure (`MaintenanceOfficialKnowledgeTest` rollback test, pre-existing, green on MySQL CI); real-manual reproduction test passed with the source PDF; ingestion tests 25 passed. Frontend troubleshooting node tests 44/44.

## Still pending
Phase 3.2 Production Acceptance (PARTIAL) · M2.20 Production Acceptance (PARTIAL) · J-code Team Feedback · M2.20D Deferred Audit Events · CSV formula neutralization G-P1 · M2.12F historical data decisions · other deferred V2+ milestones. Optional follow-ups from this check (not started): decide whether numeric-only official search should also offer the J twin; decide on the 2 quarantined J rows (needs manual review of the printed cells).

## Resolved findings
- **Numeric-only official search (was PARTIAL): RESOLVED 2026-10-21.** `GET /maintenance/official-error-entries` (`search` and `code`) now matches both `C` and `J` for bare digits and returns each record under its own code, variants and references. Explicit `C…` stays C-only and `J…` stays J-only; a number with a single family returns only that family; unknown numbers return an empty list. The UI sends bare digits unprefixed and its numeric note was shortened. Backend tests in `JamCodeIngestionTest` (1101 → C-1101 + J-1101, 1001 → J-1001 with both option variants, C1001 → empty, 9999 → empty, `code=1101`) and the frontend routing tests cover it. No ingestion, content or permission change.
- Still open: J-7272 (SD-510) and J-7524 (FD) remain quarantined with no official entry (GAP, unchanged).
