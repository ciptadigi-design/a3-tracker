# Maintenance V2.1 Full Manual Semantic Dry Run

This evidence records a local, deterministic, database-free analysis of the complete 2,639-page service manual whose SHA-256 is `ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4`. It does not authorize ingestion or deployment.

## Result

The manual contains 628 detailed semantic malfunction sections representing 617 distinct normalized code strings. Eleven code strings have two applicability variants, producing 11 additional semantic entries. There are no `(document, code, variant)` identity collisions.

The preserved initial run was 621 PASS, 5 WARN, and 2 FAIL. Full-source inspection justified three generalized parser corrections: preserve an unindented first Classification value, recognize an explicit unpunctuated accessory prefix, and terminate sections at verified `Solution N (C-xxxx_xxxx)` family headings. No code, section, or page was special-cased.

The final run is 625 PASS, 1 WARN, and 2 FAIL. C-1547 is a manufacturer-sparse safety-only solution with no substantive numbered step. C-C131 and C-D0F8 each contain only an empty `1.` and remain fail-closed. The two final canonical metadata digests are identical: `2240175affdfdbf49ad485e1698201b76c33db49035bc69e2f65a69fdc18eba1`.

## Coverage

- Applicability: 628 resolved, 0 ambiguous, 0 unresolved.
- Families: 563 numeric entries, 28 `C-Cxxx`, 21 `C-Dxxx`, 15 `C-Exxx`, and one additional mixed family code, `C-6F01`.
- Safety: 54 warning-bearing, 243 DIPSW-bearing, and 205 detached-control entries; no confirmed silent safety loss.
- Boundaries: 606 next-heading, 21 structural family-terminal, and one chapter-terminal boundary; 410 single-page and 218 two-page sections; no unverified boundary, neighbor leak, duplicate page, or running-header/footer contamination remained.
- Solutions: 3 zero-step, 21 one-step, 260 with 2-5 steps, 303 with 6-10, and 41 with more than 10; maximum 16.
- Parts: 2,018 parsed part rows; 13 source sections have an empty parts list.
- References: 598 wiring, 580 I/O, 82 service-section, and 280 DIPSW references.
- Manual comparison: 25 MATCH, 0 PARTIAL, 0 FAIL. The set includes C-3911, both C-1103 variants, accessory/tandem scopes, all observed letter-bearing families, single-step and long-step records, safety content, references, and cross-page records.

Focused V2 tests, the complete backend suite on SQLite and local disposable MySQL, all Node tests, Maintenance UI safety tests, the production build, changed-file Pint, and `git diff --check` passed. Repository-wide ESLint remains red on 1,118 unrelated baseline findings because its current scope includes `backend/vendor`, protected pre-existing production bundles, and legacy frontend findings.

The complete compact statistics, duplicate inventory, deterministic samples, and mutation proof are in `docs/maintenance/evidence/V2_1_FULL_MANUAL_DRY_RUN.json`. Full extracted text, source sections, and the PDF are deliberately excluded.

## Legacy context

V2 reports 617 distinct codes versus 705 historical normalized-code groups, and 628 semantic entries versus those 705 legacy groups. The legacy 2,680 candidate occurrences include repeated summary-table and contextual occurrences rather than only detailed semantic sections. V2 retains 11 real applicability variants and the real `C-6F01` format, but it was not altered to reproduce 705.

## Gate

`V2_1_FULL_MANUAL_DRY_RUN=PASS`

`READY_FOR_FULL_MANUAL_INGESTION_DESIGN=YES`
