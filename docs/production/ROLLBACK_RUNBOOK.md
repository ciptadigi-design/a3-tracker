# Production Rollback Runbook

Authoritative for the current `releases/<sha>/` + `current` symlink + `shared/`
architecture in use since M2.12E (see
[RELEASE_PROCEDURE.md](RELEASE_PROCEDURE.md)). Closes M2.18 audit finding H8:
the previous version of this document only described the one-time M2.12
Supabase→Laravel cutover event and did not apply to the iterative release
cadence this system actually runs today - see the archived section at the
bottom. The rollback authority is `TBD_USER_APPROVAL_REQUIRED`.

## The rule

Rollback under this architecture has two completely different shapes, and
confusing them is the main risk:

- **Application-only rollback** (repoint `current` to a prior release +
  re-sync the frontend + reset OPcache) is fast, fully reversible, and never
  touches business data. It is safe whenever the database schema the prior
  release expects is a subset of what's currently applied.
- **DB restore** is slow, requires a validated backup, and is the correct
  choice whenever the above is not true, or Production data itself is
  corrupted.

`scripts/deployment/rollback-release.sh` automates only the first shape. It
never restores a database and never runs a migration, forward or down. It
will refuse to swap `current` if it cannot prove the target release's
migrations are a strict subset of what's already applied, unless the
operator explicitly passes `--confirm-db-compatible` after reviewing the
warning - the tool automates the deterministic filesystem work and leaves
the judgment call to a human, on purpose.

## Decision tree

```
INCIDENT
  |
  +-- Is Production data itself corrupted or wrong (not just a bad deploy)?
  |     YES -> ROLLBACK_REQUIRING_DB_RESTORE (skip everything else)
  |
  +-- Is the problem isolated to the frontend bundle (wrong build, broken
  |   JS, wrong backend contract) with the API otherwise healthy?
  |     YES -> ROLLBACK_FRONTEND
  |
  +-- Did the bad release include a forward schema change (new migration)?
  |     NO  -> ROLLBACK_APPLICATION_ONLY
  |     YES -> is that migration purely additive (nullable/defaulted columns,
  |            no drops/renames of anything already in use)?
  |              YES -> ROLLBACK_WITH_FORWARD_SCHEMA
  |              NO / UNSURE -> ROLLBACK_REQUIRING_DB_RESTORE
```

---

## ROLLBACK_APPLICATION_ONLY

Use when the live schema is unchanged from the target release, or the only
schema change since is additive and already covered by
`ROLLBACK_WITH_FORWARD_SCHEMA` below.

**PRECONDITIONS**
- The target release directory (`releases/<sha>/`) still exists on disk and
  was originally deployed via the current `RELEASE_PROCEDURE.md` (a release
  older than M2.17.5.6 predates shared `.env`/session linkage and the
  frontend backend contract - see "Releases that predate this contract"
  below).
- `BACKUP_GATE=PASS` has already been captured for the *current* (about to
  be rolled back) release, in case the decision needs to be reversed again.

**COMMAND/TOOL SEQUENCE**
```
scripts/deployment/rollback-release.sh <target_sha> <app_root> <public_html_dir> --dry-run
```
Review the `DATABASE_COMPATIBILITY=` line. If it reports
`TARGET_RELEASE_HAS_ALL_LIVE_MIGRATIONS`, proceed:
```
scripts/deployment/rollback-release.sh <target_sha> <app_root> <public_html_dir>
```
Then, exactly as a forward deploy: upload `reset-opcache.php`, `curl` it
once, confirm `OPCACHE_RESET_OK`, delete it immediately.

**VALIDATION**
- `GET /api/v1/version` reports the target SHA exactly.
- `PUBLIC_HTML_INODE_PRESERVED=YES` and `HTACCESS_UNCHANGED=YES` in the
  script's own output.
- Run the smoke tests from `RELEASE_PROCEDURE.md` step 17.
- A real browser login still resolves through Laravel (re-run the same
  network check from M2.17.5.6/M2.18's acceptance phase).

**STOP CONDITIONS**
- `rollback-release.sh` exits non-zero for any reason - nothing was changed;
  do not force it, diagnose the printed failure first.
- The target release fails `verify-release.sh` (surfaced by
  `rollback-release.sh` itself) - it likely predates the current deployment
  contract. Do not retrofit/mutate the old release just to make it pass.

**ROLL-FORWARD OPTION**
The previously-live release directory still exists untouched on disk (this
script never deletes a release). Re-running `rollback-release.sh` with the
newer SHA reverses the rollback exactly like any other application-only
rollback.

---

## ROLLBACK_FRONTEND

Use when the API/backend is healthy but the deployed frontend bundle is
wrong (e.g. a bad build, or - the M2.17.5.6 scenario - the wrong
`VITE_DATA_BACKEND` baked in).

**PRECONDITIONS**
- A known-good prior release's `dist/` still exists on disk (either in its
  own `releases/<sha>/dist/` or as a locally-rebuilt artifact that has
  passed `verify-frontend-backend.sh`).

**COMMAND/TOOL SEQUENCE**
This is the frontend half of `rollback-release.sh` on its own, useful when
the backend does *not* need to change:
```
scripts/deployment/sync-public-html.sh <known_good_dist_dir> <public_html_dir>
```
followed by the same OPcache reset sequence as above (OPcache caches PHP
opcodes, not static assets, but resetting it after any `public_html` change
keeps the deploy sequence uniform and avoids a class of "did I actually
finish" mistakes).

**VALIDATION**
- `curl https://a3.ciptagrafika.com/ | grep -o 'assets/index-[^"]*\.js'`
  shows the expected (older) asset hash.
- `PUBLIC_HTML_INODE_PRESERVED=YES` and `HTACCESS_UNCHANGED=YES` (same
  pre/post inode and `.htaccess` sha256 check `sync-public-html.sh`'s own
  precondition already enforces).
- A real browser login resolves through the expected backend.

**STOP CONDITIONS**
- `sync-public-html.sh` refuses to run because `public_html_dir` has no
  `.htaccess` (wrong target) - do not point it at the wrong directory to
  force past this.

**ROLL-FORWARD OPTION**
Re-run `sync-public-html.sh` with the newer `dist/` once it's fixed.

---

## ROLLBACK_WITH_FORWARD_SCHEMA

Use when the target (older) release predates a migration that has already
run against the live database, but that migration is purely additive.

**Why an older release can safely run against a newer, additive-only
schema:** a nullable or defaulted new column is simply never referenced by
older application code - Eloquent only selects/inserts columns it knows
about. This breaks the moment a migration drops a column, renames one, adds
a non-nullable column with no default, or changes a type incompatibly -
none of which this project's migrations have done so far (spot-checked
during the M2.18 audit and M2.18.2: every recent migration adds
nullable/defaulted columns with safe-delete foreign keys).

**PRECONDITIONS**
- Same as `ROLLBACK_APPLICATION_ONLY`, plus: every migration file present in
  the live release but absent from the target release has been manually
  reviewed and confirmed additive-only (nullable/defaulted columns, no
  drops/renames of anything already in use).

**COMMAND/TOOL SEQUENCE**
```
scripts/deployment/rollback-release.sh <target_sha> <app_root> <public_html_dir> --dry-run
```
Confirm the warning lists exactly the migrations you already reviewed and
nothing else, then:
```
scripts/deployment/rollback-release.sh <target_sha> <app_root> <public_html_dir> --confirm-db-compatible
```
Then the same OPcache reset sequence as `ROLLBACK_APPLICATION_ONLY`.

**VALIDATION**
Same as `ROLLBACK_APPLICATION_ONLY`, plus: exercise the specific feature(s)
the reviewed migration(s) added columns for, and confirm the older
application code degrades sensibly (ignores the extra column) rather than
erroring.

**STOP CONDITIONS**
- Any reviewed migration is not obviously additive-only (a drop, rename, or
  non-nullable column with no default) - do not pass
  `--confirm-db-compatible`. Use `ROLLBACK_REQUIRING_DB_RESTORE` instead.
- `rollback-release.sh` exits `3` (compatibility not confirmed) - this is
  the tool correctly refusing to guess; review and either confirm or escalate
  to a DB restore.

**ROLL-FORWARD OPTION**
Same as `ROLLBACK_APPLICATION_ONLY` - roll forward by re-running the tool
with the newer SHA once the underlying issue is fixed.

---

## ROLLBACK_REQUIRING_DB_RESTORE

Use when Production data itself is corrupted or wrong, or a forward
migration was destructive/lossy, or schema compatibility cannot be proven
safe.

**PRECONDITIONS**
- A validated backup exists: `BACKUP_GATE=PASS` from
  `production-backup.sh`, which itself refuses to report PASS on an
  unvalidated dump (see
  [BACKUP_INTEGRITY_RUNBOOK.md](BACKUP_INTEGRITY_RUNBOOK.md) for the
  incident - a 20-byte gzip-valid-but-empty dump - that made this
  validation mandatory).
- Explicit authorization from the rollback decision owner - this is
  destructive to any writes made since the backup and must never be a
  unilateral call.

**COMMAND/TOOL SEQUENCE**
This project has no scripted DB restore (deliberately - "do not create a
magical one-command destructive rollback that can silently restore
databases," per the M2.18.2 mission). Restore is a manual, provider-approved
procedure:
1. Freeze writes (application maintenance mode or equivalent).
2. Restore the validated `full-db.sql.gz` from the chosen backup directory
   using the standard `mysql` import procedure appropriate for the hosting
   provider - never a partial/manual `DELETE`+re-insert reconstruction.
3. Run `rollback-release.sh` (or a forward deploy, if data corruption is
   what's being fixed rather than a bad release) to bring the application
   release in sync with the restored schema/data.
4. Unfreeze writes only after the validation below passes.

**VALIDATION**
- Row counts, fingerprints, and the core tables (Account/Branch/Machine,
  counters, lifecycles, purchases, incidents, inventory movements) match the
  expected pre-incident state.
- `GET /api/v1/version` reports the correct release SHA for whatever
  application code is now paired with the restored data.
- Full smoke test + a real browser login.

**STOP CONDITIONS**
- No `BACKUP_GATE=PASS` exists for the chosen restore point - do not restore
  from an unvalidated dump.
- Any doubt about which backup directory is the correct restore point -
  stop and confirm with the decision owner rather than guessing.

**ROLL-FORWARD OPTION**
Re-deploy forward once the root cause is fixed, following the normal
`RELEASE_PROCEDURE.md` sequence from a fresh backup.

---

## Session behavior across any rollback

File-based sessions live in `shared/storage/framework/sessions/`, shared
across every release (see `link-shared-storage.sh`). A rollback that only
repoints `current` (any of the three application-rollback shapes above)
does **not** invalidate existing sessions - a user already logged in stays
logged in, exactly as with a forward deploy, because the session store never
moves. A DB restore to a point *before* a session was created will orphan
that session (the user's `users` row may not exist yet at that point in
time, or their password may differ) - expect affected users to be signed
out and need to log in again after a DB restore, and mention this in the
incident communication.

## OPcache

Every rollback that changes which release `current` points to requires the
same one-shot `reset-opcache.php` sequence as a forward deploy, for the
identical reason: `opcache.revalidate_path` is Off and not overridable on
this host, so already-warmed LiteSpeed/LSPHP workers keep serving the
previous release's code even after the symlink repoints. This is not
optional and is not different for a rollback versus a forward deploy.

## Releases that predate this contract

Releases created before M2.17.5.6 (shared `.env`/session linkage,
`build-manifest.json`, `verify-release.sh`) will fail `verify-release.sh`
and therefore `rollback-release.sh`. This is not a bug in the tooling - it
is the tooling correctly refusing to promote a release it cannot prove is
safe. Do not retrofit or mutate an old release directory just to force it
to pass; if rolling back that far is genuinely necessary, treat it as
`ROLLBACK_REQUIRING_DB_RESTORE` territory and rebuild that release fresh
from its exact source SHA following the current `RELEASE_PROCEDURE.md`.

---

## Archived: M2.12 one-time cutover rollback (historical reference only)

The content below describes the one-time Supabase→Laravel Production cutover
event (M2.12) and its legacy-tracker/RLS-specific concepts. It does not
describe the current `releases/<sha>` architecture and should not be
followed for an ordinary release rollback - use the sections above instead.
Kept for historical reference only.

Prefer restoring the verified known-good backup; never hand-delete immutable
migration evidence.

### A. Failure before commit

Stop the command, retain its logs, confirm the transaction rolled back,
recompute the target fingerprint, and compare it to preflight. If identical,
keep Production unavailable while the cause is reviewed. The M2.11 injected
database failure proved this path.

### B. Commit succeeds but reconciliation fails

Block frontend GO and all writes. Capture redacted diagnostics and the
unexpected fingerprint. Restore the known-good Production recovery point
into Production using the provider-approved restore procedure, verify
schema ledger/counts/fingerprints and Account/Branch/Machine records, then
rerun the pre-cutover smoke. Do not issue compensating DELETE statements.

### C. Data reconciles but application acceptance fails

If a safe code-only rollback is explicitly proven compatible with the
migrated schema/data, the rollback owner may approve it. Otherwise keep
writes stopped and restore the known-good database plus prior frontend
deployment as one coordinated recovery. Confirm the legacy tracker remains
read-only or deliberately unfreeze it only after rollback completion.

### Restore verification

Verify ledger; schema objects; RLS; Account/Branches/Machine; 28
assignments; counters; lifecycles; purchases; incidents; inventory
movements/lots; source and target fingerprints; zero Graha leakage;
Auth/login; and signed-in pages. Record operator, timestamps, backup
identifier/hash, restored fingerprint, and decision. M2.11 restored its
disposable baseline exactly from private logical artifacts without touching
hosted systems.
