# Phase 3.3B — Pengalaman Lapangan search (backend)

Status: implemented and tested locally; **not committed, not deployed**. No schema change.

Read-only projection over existing `maintenance_tickets`, `downtime_events` and `maintenance_actions`. Nothing is copied, published or reviewed; the Official Knowledge API is untouched.

## Endpoints (GET only, `maintenance.view` + active membership in `account_id`)
- `GET /api/v1/maintenance/field-experiences?account_id=&q=&machine_id=&machine_model_id=&page=&per_page=`
  `q` 2–100 chars, no control characters; `per_page` 1–25 (default 10); `page` 1–1000. Response: `data`, `meta{current_page,per_page,total,last_page}`, `query{kind,codes}`.
- `GET /api/v1/maintenance/field-experiences/{ticketId}?account_id=` — safe summary with effective activities. The id is the ticket's random UUID; an id outside the selected account, an ineligible ticket and an unknown id are all the same 404. A non-member gets 403 whether or not the account exists.

## Eligibility (defined once in `MaintenanceFieldExperienceSearch::eligibleTickets`)
`type = breakdown`, `status = DONE`, `resolution_test_result IN (normal, monitoring)`, and `resolution_cause` / `resolution_action` each ≥ 5 trimmed characters. Nothing else qualifies; existing tickets are never changed.

## Search
- `C-0213` / `C0213` / `c0213` and `J-…`: exact match on the compact code from the official reference (only when its document is visible to the account), the account error-code reference, or the downtime `error_code_text`; or the code written in ticket/resolution/activity text (`matched_by = code_in_text`). C and J never match each other.
- `0213` (exactly 4 digits): searches `C-0213` and `J-0213` separately; each result keeps its own family. Fewer digits are ordinary text.
- Anything else: every term (max 8) must appear in title, description, resolution cause/action/notes or an effective activity. `LIKE` wildcards are escaped (`ESCAPE '!'`, portable to SQLite and MySQL).
- Ranking: exact code, then model match, then same machine, then newest incident (`downtime.actual_start_at`, else `opened_at`), then ticket id DESC.

## Corrections
One result per ticket. An activity is searched and shown only in its effective form: the latest correction (ties: id) supersedes its original; superseded text is never searchable or listed. The append-only log is not touched.

## Access and privacy
`can_open` reuses `BranchAccessResolver` (the ticket-detail rule), evaluated once per distinct branch. When false (list and detail alike, enforced in the response, Phase 3.3E) the internal PIC, operator, external technician and vendor names are all withheld. Responses carry no user ids, emails, reporter/assignee, audit metadata or account ids. Long text is truncated server-side (list 240, detail 4000 characters).

## Not in this phase
Frontend (3.3C/3.3D), deployment (3.3E), official J-code ingestion, official-search pagination.
