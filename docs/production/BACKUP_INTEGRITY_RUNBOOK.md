# Production Backup Integrity Runbook

Authoritative for the actual Hostinger deployment layout in use since M2.12E:
`releases/<sha>/` + `current` symlink + `shared/.env`, `backups/` under
`/home/u777904340/a3-production-app/`. Supersedes any earlier backup guidance
in `CUTOVER_RUNBOOK.md` that predates this layout.

## The incident this runbook exists because of

During M2.15.2, the first Production DB backup attempt silently produced a
20-byte, gzip-valid-but-empty archive. The cause: a naive shell parse of a
quoted `.env` value —

```sh
DB_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d= -f2)
```

`.env` stores `DB_PASSWORD="actual-password"` (quoted). The command above
keeps the literal `"` characters as part of the value. `mysqldump` then
receives a corrupted credential, authentication fails, `mysqldump` writes
nothing to stdout — and because the failing exit code was never checked
before piping into `gzip`, `gzip` happily compressed an empty stream into a
**syntactically valid** empty `.gz` file. `gzip -t` on that file passes.
**File existence and gzip validity are not proof of a usable backup.**

Auditing history during M2.16 found the *same exact failure signature*
already present in a backup from M2.13.1
(`m2-13-1-pre-cutover-20260909T110626Z/a3_production_db_20260909T110626Z.sql.gz`,
20 bytes, `gzip -t` passes, decompresses to 0 bytes) — this was not a one-off.
The M2.13.1 operator (or script) retried ~2 minutes later and got a real
backup; that retry was never itself validated, it just happened to work.
That is not a process — it's luck.

## The rule

> **NO BACKUP_GATE=PASS = NO PRODUCTION MUTATION.**
>
> A migration, a code deploy, or any other Production write may only proceed
> after the backup tooling below prints `BACKUP_GATE=PASS`. If it prints
> `BACKUP_GATE=FAIL` for any reason, stop and diagnose — do not retry blindly,
> do not proceed on "it's probably fine", and do not hand-run mysqldump
> without validating the result the same way.

## Locked cutover order

1. Verify the exact source SHA locally and confirm `origin/develop` matches it.
2. Read-only Production preflight (current SHA, migrate:status, public_html
   identity/`.htaccess` hash).
3. Run `scripts/deployment/production-backup.sh <milestone-slug> <backend-dir>
   <public_html-dir> <backups-root>` from the release being deployed. This
   single command performs steps 4–9 below and is the only supported way to
   create a pre-cutover backup from now on.
4. Create the timestamped backup directory (mode 700, under the approved
   `backups/` root only).
5. Create the DB backup (safe credential extraction — see below — then
   `mysqldump --single-transaction --quick --routines --triggers --events`,
   gzip).
6. Validate the DB backup (`scripts/deployment/validate-db-backup.sh`) —
   gzip integrity, non-empty decompressed SQL, size floor, no error-text
   markers, required core tables present with a minimum `CREATE TABLE` count.
7. Create the frontend backup (`tar` of `public_html/.` into the same backup
   directory — the `public_html` directory itself is never replaced).
8. Validate the frontend backup (`scripts/deployment/validate-frontend-backup.sh`)
   — tar integrity, file-count floor, `index.html`/`assets/`/`.js` present.
9. Write `backup-manifest.json` in the backup directory.
10. `BACKUP_GATE=PASS` only if every one of steps 4–9 succeeded. Only then is
    code deployment/migration permitted.

If `production-backup.sh` exits non-zero, the migration step must not run.
Whatever partial artifacts exist under that specific timestamped directory
are left in place for diagnosis (never silently deleted, never treated as a
valid backup) — but the deploy proceeds no further.

## Known lessons baked into the tooling

- **`.env` values may be double-quoted.** Never parse them with
  `grep | cut` / naive `sed`. Use `scripts/deployment/lib/mysql-defaults-from-env.php`,
  which decodes dotenv quoting/escaping the same way Laravel's own Dotenv
  does, and writes credentials only into a `chmod 600` MySQL
  `--defaults-extra-file`, never onto the command line or into a shell
  variable that could be echoed or land in history.
- **File existence ≠ valid backup.** A backup step must check its own exit
  code and the resulting artifact's content, not just "a file appeared".
- **`gzip -t` passing ≠ semantically valid SQL.** An empty stream is a
  perfectly valid gzip file. Always decompress and inspect content.
- **A ~20-byte archive is a hard red flag**, not a small-but-fine backup.
  Real schema dumps for this project are consistently in the tens-to-hundreds
  of KB range even with modest data volume.
- **Validate required tables explicitly** (`users`, `accounts`, `branches`,
  `machines`, `inventory_movements`, `component_replacements`,
  `counter_readings`, `operational_people`) — a dump that ran against the
  wrong database, or partway through a schema change, can otherwise look
  superficially plausible.
- **Record SHA256** for every artifact, written into a `.sha256` sidecar
  file and the manifest, so a later restore can prove it's using the exact
  bytes that were validated.
- **`public_html`'s directory identity (device+inode) must be preserved**
  across a cutover — never `rm -rf`/recreate the directory, only add/replace
  files inside it. Record `stat` inode before and after; a cutover that
  changes the inode has replaced the directory and must be treated as a
  failure regardless of what the site looks like afterward.
- **`.htaccess` must be preserved exactly** — record its SHA256 before and
  after every cutover; it must be byte-identical unless a reviewed,
  explicit change to it was part of that specific release.
- **Frontend backups should include `.htaccess`.** Three historical
  tar.gz-style backups (`m2-12l5-frontend-pre-deploy-*`,
  `m2-13-1-pre-cutover-frontend-*`, `m2-13-2-1-pre-cutover-frontend-*`)
  do not contain `.htaccess` in the archive itself (older backups from the
  per-file-manifest era tracked its hash/mode separately instead). The
  current `production-backup.sh` always includes it because it tars the
  entire `public_html/.` tree, including dotfiles.

## Tooling reference

| Script | Purpose |
|---|---|
| `scripts/deployment/lib/mysql-defaults-from-env.php` | Safely turns a Laravel `.env` into a `chmod 600` MySQL defaults-extra-file. Never prints the password. |
| `scripts/deployment/validate-db-backup.sh <path>` | Standalone DB backup validator/classifier. Exit 0 = VALID, exit 1 = INVALID (reason printed). Safe to run against any existing `.sql`/`.sql.gz` without side effects. |
| `scripts/deployment/validate-frontend-backup.sh <path>` | Standalone frontend `tar.gz` validator/classifier. Extracts only to a disposable temp directory, never over a live path. |
| `scripts/deployment/production-backup.sh <milestone> <backend-dir> <public_html-dir> <backups-root>` | Orchestrates DB backup → validate → frontend backup → validate → manifest → gate. This is the only sanctioned way to create a pre-cutover backup going forward. |

All four were tested locally against synthetic valid/empty/error-text/
corrupt/tiny-invalid DB dumps and valid/invalid frontend archives (all
behaved correctly — valid PASS, invalid FAIL with a non-zero exit), and were
then run read-only against every existing Production backup as of M2.16
(11/13 real DB backups PASS, the 2 known-bad ones correctly FAIL; 5/5
tar.gz frontend backups PASS). `production-backup.sh` was also run once for
real against Production (`m2-16-validator-selftest-<timestamp>`) and
produced a fully valid backup + manifest end-to-end.

## Historical backup audit (M2.16)

See `docs/M2_16_BACKUP_INTEGRITY_AUDIT.md` for the full per-artifact matrix,
classification, and rollback-coverage analysis. Two invalid DB backups exist
historically (M2.12L5's first attempt, M2.13.1's first attempt) — both have
a valid backup within a few minutes of the same cutover, so there is no
actual rollback gap for either milestone. No historical backups were deleted
or modified as part of this audit.
