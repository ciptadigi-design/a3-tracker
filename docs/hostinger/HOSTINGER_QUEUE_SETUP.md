# Hostinger queue runner setup (V1.5.1)

Maintenance V1.5 introduced this app's first queue job
(`App\Jobs\ExtractMaintenanceDocumentJob`, PDF knowledge extraction). This
document is how that job actually gets processed on the Hostinger production
host, where the job runs, is queued (`QUEUE_CONNECTION=database`), but nothing
was ever running to drain that queue.

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
pattern) with two additional safety properties a raw `queue:work` cron entry
would not have on a shared host:

1. **Bounded execution.** `--max-time=50` (default) and `--max-jobs=25`
   (default) mean a single invocation can never run past a safe budget or
   process an unbounded burst of jobs - it always returns control to cron.
2. **Overlap protection.** A `Cache::lock('a3-queue-runner', ...)` (backed by
   the `cache_locks` table - `CACHE_STORE=database` already, no schema change
   needed) means that if one cron tick is still mid-job (e.g. a slow
   large-PDF extraction) when the next tick fires a minute later, the second
   invocation sees the lock held, prints `QUEUE_RUNNER_SKIPPED=already_running`,
   and exits immediately instead of starting a second overlapping
   `queue:work` process. The lock's own TTL (`--max-time` + 60s) self-expires
   even if a run is killed by a host execution-time limit before it can
   release the lock cleanly - the runner can never wedge itself permanently.

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

No new environment variables and no `.env` changes are required:

- `QUEUE_CONNECTION=database` is already the configured default
  (`config/queue.php`, `.env.example`) - the `jobs`, `job_batches`, and
  `failed_jobs` tables already exist (Laravel's default migration), and
  `CACHE_STORE=database` already provides the `cache_locks` table the lock
  needs. **No database migration was added for this change.**
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

```bash
# 1. Confirm something is actually queued (optional - the command is a safe
#    no-op on an empty queue either way):
php artisan tinker --execute="DB::table('jobs')->count()"

# 2. Run one bounded drain, exactly as cron would:
php artisan a3:run-queue

# Expected output on success:
#   QUEUE_RUNNER_EXIT_CODE=0
# Expected output if a previous invocation is still running:
#   QUEUE_RUNNER_SKIPPED=already_running
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
- **`QUEUE_RUNNER_SKIPPED=already_running` on every tick.** The lock never
  self-expired in the interval you checked, or a run is genuinely still
  processing a very large PDF. The lock TTL is `--max-time` (default 50s)
  + 60s, so this should clear itself within roughly two minutes at most; if
  it doesn't, check whether a stuck PHP process is visible in the host's
  process list.
