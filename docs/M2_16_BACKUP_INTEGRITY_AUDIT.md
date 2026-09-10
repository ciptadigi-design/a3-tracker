# M2.16 — Production Backup Integrity Audit

Read-only audit of every artifact under
`/home/u777904340/a3-production-app/backups/` as of 2026-09-10/11. No backup
was deleted, moved, or modified. Classifications below were produced by
`scripts/deployment/validate-db-backup.sh` / `validate-frontend-backup.sh`
run directly against each artifact on the Production host.

## Named-milestone matrix (unambiguous pairing by directory name)

| Milestone | Timestamp (UTC) | DB backup | DB status | DB SHA256 (short) | Frontend backup | Frontend status | Notes |
|---|---|---|---|---|---|---|---|
| M2.12L5 attempt 1 | 20260907T051108Z | `a3production.sql` (0 bytes, uncompressed) | **INVALID** — 0 bytes | — | — | — | First attempt produced an empty file outright (not even gzipped). |
| M2.12L5 attempt 2 | 20260907T051304Z | `a3production.sql.gz` | VALID | `3453afd0…` | `m2-12l5-frontend-pre-deploy-20260907T051315Z.tar.gz` | VALID (no `.htaccess` in archive) | Retry ~3 min later succeeded. **Redundancy available** — no gap. |
| M2.13.1 attempt 1 | 20260909T110626Z | `a3_production_db_20260909T110626Z.sql.gz` | **INVALID** — gzip valid, 0 bytes decompressed | — | — | — | The known incident. Root cause: same `.env` quoting failure as M2.15.2's near-miss (see below). |
| M2.13.1 attempt 2 | 20260909T110723Z | (directory created, no file written) | **INVALID** — no artifact produced | — | — | — | Second attempt failed before producing any file. |
| M2.13.1 attempt 3 | 20260909T110749Z | `a3_production_db_20260909T110749Z.sql.gz` | VALID | `7d2281ad…` | `m2-13-1-pre-cutover-frontend-20260909T110801Z/public_html-frontend-20260909T110801Z.tar.gz` | VALID (no `.htaccess` in archive) | Third attempt, ~2 min after the first, succeeded. **Redundancy available** — no gap. |
| M2.13.2.1 | 20260909T141746Z | *(none found under this label — see note)* | UNKNOWN | — | `m2-13-2-1-pre-cutover-frontend-20260909T141746Z/public_html-frontend-20260909T141746Z.tar.gz` | VALID (no `.htaccess` in archive) | No DB backup directory found for this specific milestone label. Cannot confirm whether one was taken and is simply absent, or was never created. Not claiming data loss — flagging as **unknown coverage**, not proven safe. |
| M2.14.1 | 20260910T100413Z | *(none found under this label)* | UNKNOWN | — | `m2-14-1-pre-cutover-frontend-20260910T100413Z/public_html-frontend-20260910T100413Z.tar.gz` | VALID (includes `.htaccess`) | Same caveat as M2.13.2.1 — no DB backup found under this label. |
| M2.15.2 | 20260910T164607Z | `full-db.sql.gz` | VALID | `95fff17b…` | `public_html-pre-cutover.tar.gz` | VALID (includes `.htaccess`) | Created and validated live during this cutover (see M2.15.2 report). First attempt at this same step silently failed the same way as M2.13.1 — caught before migration, not left as a false record. |
| M2.16 self-test | 20260910T170652Z | `full-db.sql.gz` | VALID | `8bd7150c…` | `public_html-pre-m2-16-validator-selftest.tar.gz` | VALID (includes `.htaccess`) | Real end-to-end dry run of the new `production-backup.sh` tool against live Production, created during this audit. Not a cutover backup — kept as evidence the tooling works. |

**M2.13.2.1 and M2.14.1 DB backup coverage is genuinely unknown**, not
confirmed absent and not confirmed present under a different, unlabeled
name. This audit does not assume data loss — it only reports that no DB
backup artifact could be located and verified under those two milestone
labels. If DB backups for those two cutovers exist under a different name,
they were not identified by directory-name pattern matching in this audit.

## Earlier, unnamed backups (pre-M2.12L5, before the milestone-slug-in-dirname convention)

These use a git-SHA-and-timestamp directory naming convention and were
created before the current `production-backup.sh`-style tooling existed.
Pairing between a DB dump and a frontend directory below is **inferred only
from timestamp proximity**, not from an explicit shared label — treat any
specific DB↔frontend pairing implied by adjacency in this list as
unconfirmed.

| Directory / artifact | Type | Timestamp (UTC) | Status | Notes |
|---|---|---|---|---|
| `cd0912695fde1493ab53c13f191742ef5b4bc501-20260904T082622Z/` | frontend (directory-style, per-file manifest) | 20260904T082622Z | VALID (structural: index.html present, `public_html.manifest.sha256` present) | No `.htaccess` copy in the tree — tracked separately via `htaccess.sha256` + `htaccess.mode` sidecar files instead. |
| `cd0912695fde1493ab53c13f191742ef5b4bc501-buildfix-20260904T085418Z/` | frontend (directory-style) | 20260904T085418Z | VALID (same structural checks) | Same `.htaccess`-via-sidecar pattern. |
| `3d1100e76c79e18f1326f1d6984393b470ff8bb9-20260904T094223Z/` | frontend (directory-style) | 20260904T094223Z | VALID (same structural checks) | — |
| `db-full-20260904T094441Z/production.sql.gz` | DB | 20260904T094441Z | VALID | 45 CREATE TABLE, all required tables present. ~3.5 min after the frontend backup above — likely the same cutover's DB step, not confirmed. |
| `575235a417942ea2cc7a7bb716cb8211badca14a-20260904T103046Z/` | frontend (directory-style) | 20260904T103046Z | VALID (same structural checks) | — |
| `db-full-m2-12g3-20260904T112845Z/production.sql.gz` | DB | 20260904T112845Z | VALID | 45 CREATE TABLE, all required tables present. |
| `db-full-m2-12h1-20260904T130308Z/production.sql.gz` | DB | 20260904T130308Z | VALID | 45 CREATE TABLE, all required tables present. |
| `frontend-pre-m2-12i1-20260905T001549Z/` | frontend (directory-style) | 20260905T001549Z | VALID (structural) | No manifest sidecar found in this dir (unlike the cd09…/3d11…/5752… dirs above). |
| `db-full-m2-12h1-20260905T001549Z/production.sql.gz` | DB | 20260905T001549Z (same second as the frontend dir above) | VALID | 45 CREATE TABLE, all required tables present. Same timestamp as `frontend-pre-m2-12i1` — very likely paired despite the differing milestone label in the name (`m2-12h1` vs `m2-12i1`), but not confirmed. |
| `db-full-m2-12h1-20260905T011900Z/production.sql.gz` | DB | 20260905T011900Z | VALID | 45 CREATE TABLE, all required tables present. Same SQL byte count as the `T001549Z` dump above but a different SHA256 — a distinct dump, not a duplicate file. |
| `frontend-pre-m2-12j1-20260905T072033Z/` | frontend (directory-style) | 20260905T072033Z | VALID (structural, includes `.htaccess` directly this time) | — |
| `db-full-m2-12j1-20260905T072025Z/full-db.sql.gz` | DB | 20260905T072025Z | VALID | 45 CREATE TABLE, all required tables present. |
| `frontend-pre-m2-12k1-20260905T112825Z/` | frontend (directory-style, includes `.htaccess`) | 20260905T112825Z | VALID (structural) | — |
| `db-full-m2-12k1-20260905T112817Z/full-db.sql.gz` | DB | 20260905T112817Z | VALID | 45 CREATE TABLE, all required tables present. |

## Non-cutover / other-scope artifacts (not part of this matrix)

- `purchase-branch-backfill-20260904T094512Z/affected-rows-backup.json` — a
  targeted data-repair audit trail (JSON of affected rows for one specific
  data fix), not a full DB or frontend backup. Out of scope for this
  DB/frontend validity audit.
- `staging-final-cleanup/a3staging-final.sql.gz` — validates structurally as
  a real SQL dump (45 CREATE TABLE, required tables present), but its name
  indicates a **staging**, not Production, database snapshot. Included here
  for completeness only; not part of the Production rollback chain.

## Summary counts

- Backup directories found under `backups/`: 29
- DB-shaped artifacts found and validated: 13 (11 VALID, 2 INVALID)
- Frontend-shaped artifacts found and validated: 12 (5 as single tar.gz archives, all VALID; 7 as older per-file directory backups, all structurally VALID)
- Known rollback gaps: **0 confirmed** (every INVALID DB backup has a VALID one within minutes of the same cutover)
- Unknown DB coverage: **2** (M2.13.2.1, M2.14.1 — no DB backup artifact located under those labels; not proven present or absent)

## Root cause (Phase 6)

The naive `grep '^DB_PASSWORD=' .env | cut -d= -f2` pattern that caused both
the M2.13.1 and M2.15.2 failures **does not exist in any committed script or
documented procedure in this repository** (confirmed by searching
`scripts/`, `docs/`, and every root-level `.m2-12e-*.sh` / `.m2-12f-*.sh`
diagnostic script for `cut -d= -f2`; zero matches). It only ever existed as
an ad-hoc operator command typed directly into an SSH session during both
incidents — it was never institutionalized as tooling, which is exactly why
it could recur identically 12 months apart in project time (M2.13.1 →
M2.15.2) with nobody noticing the pattern.

By contrast, `.m2-12e-2b6-diagnose.sh` (a pre-existing, uncommitted-to-`scripts/`
diagnostic script already in the working tree) already used a correct,
dotenv-aware approach: parse `.env` in PHP, decode the quoting/escaping
properly, and write credentials into a MySQL option file rather than a shell
variable. `scripts/deployment/lib/mysql-defaults-from-env.php` (added in
M2.16) generalizes and formalizes that same proven pattern as the one
sanctioned way to get DB credentials into `mysqldump` from now on.
