# Hostinger queue runner setup (V1.5.1, env fix in V1.5.2, timing/observability hardening in V1.5.6)

Maintenance V1.5 introduced this app's first queue job
(`App\Jobs\ExtractMaintenanceDocumentJob`, PDF knowledge extraction). This
document is how that job actually gets processed on the Hostinger production
host, where the job runs, is queued (`QUEUE_CONNECTION=database`), but nothing
was ever running to drain that queue.

**V1.5.2 note:** V1.5.1 shipped the runner itself, but production's
`shared/.env` carried an explicit `QUEUE_CONNECTION=sync` left over from
before this app had any queue jobs - `config/queue.php`'s own default
(`env('QUEUE_CONNECTION', 'database')`) was always `database`, so this was
invisible in code review and in every local/CI run (neither reads production's
`shared/.env`). Under `sync`, `ExtractMaintenanceDocumentJob::dispatch()` ran
the job inline at dispatch time instead of writing a `jobs` row, so
`a3:run-queue` had nothing to ever drain - discovered during V1.5.1's
post-deploy `QUEUE_STATUS` check. V1.5.2 changed production's `shared/.env` to
`QUEUE_CONNECTION=database` (matching the code default and every environment
below) and refreshed the live release's config cache; no code, migration, or
runner-command change was needed.

## Why Supervisor is not used

`docs/hostinger/HOSTINGER_REQUIREMENTS.md` already recorded the constraint this
setup has to respect: "No persistent queue worker or WebSocket/realtime
service is justified by the current repository; any async candidate is
sync/cron/database-queue-with-cron, never Supervisor-dependent without
explicit plan support."

Concretely, on this Hostinger shared-hosting plan:

- There is no Supervisor (or any process manager) available to the account.
- The shell is jailed; a long-running `php artisan queue:work` daemon has
  nothing keeping it alive across requests/logins, and would very likely be
  killed by the host's own process/CPU-time limits even if started manually.
- The only scheduling primitive Hostinger actually gives this account is
  **cron**, which runs a fixed command on a fixed interval and always
  terminates - there is no "keep this running" primitive at all.

So the only compatible pattern is: a short-lived script, invoked by cron,
that does a bounded amount of work and then exits on its own - never a
daemon.

## The runner: `php artisan a3:run-queue`

`app/Console/Commands/RunQueueOnce.php` wraps Laravel's own
`queue:work --stop-when-empty` (the officially-supported "no daemon" queue
pattern) with additional safety properties a raw `queue:work` cron entry
would not have on a shared host:

1. **Bounded execution.** `--max-time=50` (default) and `--max-jobs=25`
   (default) mean a single invocation can never run past a safe budget or
   process an unbounded burst of jobs - it always returns control to cron.
   Note `--max-time` only stops the loop from picking up *new* jobs after that
   many seconds have elapsed; it does not interrupt a job already in progress
   (see next point).
2. **Per-job timeout.** `--timeout=300` (5 minutes) is queue:work's own
   pcntl-based per-job kill switch. V1.5.5's real 121.8MB Konica extraction
   took ~107 seconds - this leaves ~2.8x margin. **V1.5.6 finding:** the prior
   version of this runner never passed `--timeout` at all, silently defaulting
   to queue:work's own CLI default of 60 seconds; the real ~107s job still
   completed successfully despite that, even with both `pcntl` and `posix`
   loaded on Production - proving the alarm-based kill did not fire in that
   run, for a reason not fully root-caused. Do not rely on that non-enforcement
   continuing to hold; the explicit `--timeout=300` closes the ambiguity.
3. **Overlap protection.** A `Cache::lock('a3-queue-runner', ...)` (backed by
   the `cache_locks` table - `CACHE_STORE=database` already, no schema change
   needed) means that if one cron tick is still mid-job (e.g. a slow
   large-PDF extraction) when the next tick fires a minute later, the second
   invocation sees the lock held, prints `QUEUE_RUNNER_STATUS=SKIPPED` /
   `QUEUE_RUNNER_REASON=LOCK_HELD`, and exits immediately instead of starting a
   second overlapping `queue:work` process. **V1.5.6 change:** the lock's TTL
   is now `--max-time + --timeout + 30s buffer` (380s with the defaults above),
   derived from the same values actually passed to queue:work - not the old
   fixed `--max-time + 60s` (110s), which left only a 3-second margin over the
   real ~107s job it needed to survive. It still self-expires even if a run is
   killed by a host execution-time limit before it can release the lock
   cleanly - the runner can never wedge itself permanently.
4. **`retry_after` (config/queue.php, `DB_QUEUE_RETRY_AFTER`) raised to 420s.**
   This is the actual correctness-critical value, not just cron-tick overlap
   protection: it is enforced unconditionally by the database queue driver's
   own `reserved_at < now() - retry_after` query on every pop, regardless of
   pcntl/signals. The old default (90s) was below the real ~107s job -
   a legitimately-still-running job could have had its reservation treated as
   abandoned and become poppable by a second worker mid-extraction. 420s gives
   120s of margin above the 300s `--timeout` ceiling itself.

`queue:work --stop-when-empty` itself is what keeps this from ever becoming a
long-running process: it processes whatever is currently on the queue and
then **stops on its own** the moment the queue is empty, rather than
blocking/polling forever like a normal worker would.

## Cron configuration

Add one cron entry in Hostinger's control panel (hPanel → Advanced → Cron
Jobs), running every minute, targeting the **`current`** symlink so it always
executes against whichever release is actually live - no cron reconfiguration
is ever needed on a normal deploy:

```
* * * * * cd /home/u777904340/a3-production-app/current/backend && php artisan a3:run-queue >> /dev/null 2>&1
```

Every-minute cadence plus the lock guard above means: at most one drain runs
at a time, and a queued extraction is picked up within, at worst, about a
minute of being requested.

## Required environment configuration

- `QUEUE_CONNECTION=database` is the configured default
  (`config/queue.php`, `.env.example`) - the `jobs`, `job_batches`, and
  `failed_jobs` tables already exist (Laravel's default migration), and
  `CACHE_STORE=database` already provides the `cache_locks` table the lock
  needs. **No database migration was ever added for this change.**
- **Verify production's `shared/.env` does not override this.** V1.5.1
  shipped with production carrying a stale `QUEUE_CONNECTION=sync` in
  `shared/.env` (predating this app's first queue job), which silently made
  every dispatched job run inline instead of queuing - fixed in V1.5.2 by
  setting `shared/.env`'s `QUEUE_CONNECTION` to `database` and running
  `php artisan config:clear && php artisan config:cache` in the *live*
  release's `backend/` (config is cached per-release, so this must be re-run
  against whichever release `current` points at, not just the source
  release). Confirm with `php artisan config:show queue.default` -> `database`
  after any release or env change.
- `storage/app/private/maintenance-documents` is already symlinked into
  `shared/` by `scripts/deployment/link-shared-storage.sh` (V1.4) - the queue
  runner reads through that exact same path via `DocumentStorageService`, so
  it works identically regardless of which release is currently live.
- A job dispatched from one release and processed by a cron tick after a
  later deploy still works correctly: `ExtractMaintenanceDocumentJob` only
  serializes a plain extraction UUID, not a release-specific path or object -
  the job re-queries the database and re-resolves storage paths itself when
  it actually runs, so it is release-independent by construction. No conflict
  with the release/`current`-symlink system.

## How to test queue execution

Locally or against a staging copy:

Note: Hostinger's PsySH/Tinker is not usable in this environment - use
`php artisan queue:monitor database:default` or a direct read-only DB query
for inspection instead of `php artisan tinker`.

```bash
# 1. Confirm something is actually queued (optional - the command is a safe
#    no-op on an empty queue either way):
php artisan queue:monitor database:default

# 2. Run one bounded drain, exactly as cron would:
php artisan a3:run-queue

# Expected output on success (whether or not any jobs were actually queued):
#   QUEUE_RUNNER_STATUS=STARTED
#   QUEUE_RUNNER_STATUS=COMPLETED
#   QUEUE_RUNNER_EXIT_CODE=0
#   QUEUE_RUNNER_JOBS_PROCESSED=<n>
#   QUEUE_RUNNER_JOBS_FAILED=<n>
#   QUEUE_RUNNER_JOBS_TIMED_OUT=<n>
# Expected output if a previous invocation is still running:
#   QUEUE_RUNNER_STATUS=SKIPPED
#   QUEUE_RUNNER_REASON=LOCK_HELD
# Expected output if the runner invocation itself breaks (e.g. queue
# connection/config problem - never a single job's own business failure,
# which is what QUEUE_RUNNER_JOBS_FAILED counts):
#   QUEUE_RUNNER_STATUS=FAILED
#   QUEUE_RUNNER_EXIT_CODE=1
```

To exercise a real extraction end-to-end: upload a PDF to a document, call
`POST /api/v1/maintenance/documents/{id}/extract` (which dispatches the job),
then run `php artisan a3:run-queue` and confirm via
`GET /api/v1/maintenance/documents/{id}/extraction` that the status moved to
`COMPLETED`.

On production, after the cron entry above is installed, the same check can be
done through the API alone (upload → extract → wait up to ~1 minute → poll
extraction status) without shell access.

## Troubleshooting failed jobs

- **Extraction stuck at `PENDING` for more than a couple of minutes.**
  Confirm the cron entry exists and is enabled in hPanel, and that its path
  points at `current/backend` (not a specific release directory that may no
  longer be `current`). Manually run `php artisan a3:run-queue` over SSH to
  rule out a cron-specific problem (wrong PHP binary, wrong working
  directory).
- **Extraction moved to `FAILED` with an `error_message`.** This is the
  intended, already-tested behavior of `ExtractMaintenanceDocumentJob` - the
  `error_message` column on `maintenance_document_extractions` (fetch it via
  the extraction-status endpoint, or `governance_audit_logs` for the
  `maintenance_document_extraction.failed` audit row) explains why. Common
  causes: the stored PDF is missing from disk, or the PDF failed to parse.
  The job retries automatically (3 attempts, backoff 30s / 90s / 300s) before
  Laravel moves it to `failed_jobs`.
- **A job sits in Laravel's own `failed_jobs` table after all retries.**
  Inspect it with `php artisan queue:failed`. This is standard Laravel queue
  behavior, unrelated to the Hostinger-specific runner - `php artisan
  queue:retry <id>` re-queues it for the next `a3:run-queue` cron tick to pick
  up.
- **`QUEUE_RUNNER_STATUS=SKIPPED` / `QUEUE_RUNNER_REASON=LOCK_HELD` on every
  tick.** The lock never self-expired in the interval you checked, or a run is
  genuinely still processing a very large PDF. The lock TTL is `--max-time` +
  `--timeout` + 30s (380s with the V1.5.6 defaults: 50 + 300 + 30), so this
  should clear itself within roughly 6-7 minutes at most; if it doesn't, check
  whether a stuck PHP process is visible in the host's process list.
- **`QUEUE_RUNNER_STATUS=FAILED`.** The runner invocation itself broke - not a
  single job's business failure (that shows up as a nonzero
  `QUEUE_RUNNER_JOBS_FAILED` alongside `QUEUE_RUNNER_STATUS=COMPLETED`
  instead). Check `QUEUE_RUNNER_ERROR=<exception class>` for the cause (e.g. a
  queue connection/config problem after a bad deploy).
