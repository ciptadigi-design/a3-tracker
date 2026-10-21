# Phase 3.3C — Combined Troubleshooting search UI

Status: implemented and tested locally; **not committed, not deployed**. Frontend only; no schema or backend change.

One search input feeds two independent groups: **Pengetahuan Resmi** (existing official search) and **Pengalaman Lapangan** (Phase 3.3B `GET /maintenance/field-experiences`).

## Routing (`classifyTroubleshootingQuery`)
| Query | Official | Field |
|---|---|---|
| `C-0213`, `J2113`, `cc152` (explicit code) | normalized code | raw query (backend interprets) |
| `0213` (exactly 4 digits) | legacy default `C-0213` | raw digits (backend searches C and J separately) + a note that C and J may differ |
| partial (`152`, `C-02`) | unchanged legacy search | text search (no prefix matching) |
| anything else (`hasil kotor`) | not called (calm note) | text search |
Too-short text (no word of 2+ chars) skips the field call instead of provoking a 422.

## Independence
`troubleshootingSearch.js` keeps one state per group (status, items, page, total, hasMore, error). A failing source shows an error with its own retry and never a zero-result message, and never hides the other. Newer searches supersede older ones. "Muat lebih banyak" appends the next backend page (official 20, field 10; backend cap 25) with de-duplication; a failed later page keeps what is loaded.

## Safety
Field cards render only the server's safe projection (code, problem, machine/model, branch, date, downtime, cause, action, test result). No PIC, technician, user, ticket id or navigation: opening an experience or ticket is Phase 3.3D. Official cards keep manufacturer navigation, applicability classes (Sesuai model / Kemungkinan / Tidak sesuai model) and client-side prioritisation; no model filter is sent to the backend.

## Still pending
3.3D detail/navigation, 3.3E deployment, official J-code ingestion (parser only recognises `C-` headings), Phase 3.2 acceptance, Financial Impact, M2.12F, M2.20 acceptance, assisted-knowledge coverage, other deferred items.

> Update 2026-10-21: official J-code ingestion and Financial Impact are deployed. Phase 3.2 and M2.20 acceptance are PARTIAL - see `docs/PRODUCTION_ACCEPTANCE_2026-10-21.md`. M2.12F, assisted-knowledge coverage, J-code team feedback, M2.20D and other deferred items remain pending.
