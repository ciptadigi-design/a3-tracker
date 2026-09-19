<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * V1.5.1 - Hostinger Queue Runner Compatibility. Hostinger's shared/jailed shell
 * has no Supervisor and no persistent process is allowed to run (confirmed in
 * docs/hostinger/HOSTINGER_REQUIREMENTS.md: "any async candidate is sync/cron/
 * database-queue-with-cron, never Supervisor-dependent"). This command is what a
 * Hostinger cron entry actually calls - a single bounded invocation that drains
 * whatever is currently queued (queue:work --stop-when-empty, which exits on its
 * own the moment the queue is empty - no daemon, no infinite loop) and then
 * returns control to cron, never left running.
 *
 * V1.5.6 - made every outcome an explicit, machine-readable line
 * (QUEUE_RUNNER_STATUS=...) instead of a silent clean exit a cron/operator has to
 * infer meaning from, and corrected two timing values that were only ever checked
 * against fast synthetic-fixture jobs, not a real large-PDF extraction:
 *
 *  - `--timeout` (per-job kill switch) was never explicitly passed, so it silently
 *    used queue:work's own CLI default of 60 seconds. The real 121.8MB Konica
 *    document's V1.5.5 Production acceptance run took ~107 seconds and still
 *    completed successfully (graceful "DONE", not a killed process) despite that
 *    default and despite both `pcntl` and `posix` being loaded on Production -
 *    proving the alarm-based timeout did NOT fire in that run, for a reason this
 *    milestone did not need to fully root-cause to fix the actual risk: relying on
 *    that non-enforcement was never something to depend on going forward. An
 *    explicit, generous `--timeout` closes the ambiguity either way.
 *  - `retry_after` (config/queue.php, DB_QUEUE_RETRY_AFTER) defaulted to 90 seconds
 *    - below the observed 107-second real job. This one is NOT signal/pcntl-
 *    dependent: it is enforced by the database queue driver's own
 *    `reserved_at < now() - retry_after` query, unconditionally, on every pop. A
 *    legitimately-still-running 107s job with a 90s retry_after could have its
 *    reservation treated as abandoned and become poppable by a second worker
 *    while the first was still genuinely processing it - the real correctness bug
 *    "overlapping workers" protection has to guard against, not merely lock
 *    contention between cron ticks. Raised to 420s (7 min) - see .env.example.
 *
 * The lock's own TTL is no longer tied only to --max-time (which bounds "stop
 * accepting NEW jobs", not a single already-started job's duration - a slow job
 * that has already begun is never interrupted by --max-time). It is now
 * `maxTime + timeout + 30s buffer`, so the lock cannot expire while a legitimately
 * slow job (up to the full --timeout ceiling) is still being processed - directly
 * derived from the same options actually passed to queue:work, not a separate
 * magic number that could silently drift out of sync with them.
 */
class RunQueueOnce extends Command
{
    protected $signature = 'a3:run-queue
        {--max-time=50 : Maximum seconds this invocation may run before stopping, leaving headroom before the next cron tick}
        {--max-jobs=25 : Maximum number of jobs this invocation will process before stopping}
        {--timeout=300 : Maximum seconds a single job may run before queue:work kills it (pcntl-dependent; see class docblock) - comfortably above the ~107s real large-PDF extraction observed in V1.5.5 Production acceptance}';

    protected $description = 'Drain the database queue once, safely, for a Hostinger cron entry (no Supervisor, no long-running worker)';

    /** Fixed slack added on top of maxTime+timeout for the lock TTL - covers signal/process cleanup latency, not another job. */
    private const LOCK_TTL_BUFFER_SECONDS = 30;

    public function handle(): int
    {
        $maxTime = max(1, (int) $this->option('max-time'));
        $maxJobs = max(1, (int) $this->option('max-jobs'));
        $timeout = max(1, (int) $this->option('timeout'));
        $lockTtl = $maxTime + $timeout + self::LOCK_TTL_BUFFER_SECONDS;

        $lock = Cache::lock('a3-queue-runner', $lockTtl);
        if (! $lock->get()) {
            $this->line('QUEUE_RUNNER_STATUS=SKIPPED');
            $this->line('QUEUE_RUNNER_REASON=LOCK_HELD');

            return self::SUCCESS;
        }

        $this->line('QUEUE_RUNNER_STATUS=STARTED');

        $processed = 0;
        $failed = 0;
        $timedOut = 0;
        $onProcessed = function () use (&$processed) {
            $processed++;
        };
        $onFailed = function () use (&$failed) {
            $failed++;
        };
        $onTimedOut = function () use (&$timedOut) {
            $timedOut++;
        };
        Event::listen(JobProcessed::class, $onProcessed);
        Event::listen(JobFailed::class, $onFailed);
        Event::listen(JobTimedOut::class, $onTimedOut);

        try {
            $exitCode = Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => $maxTime,
                '--max-jobs' => $maxJobs,
                '--timeout' => $timeout,
                '--sleep' => 1,
                '--tries' => 3,
            ]);

            $this->line('QUEUE_RUNNER_STATUS=COMPLETED');
            $this->line('QUEUE_RUNNER_EXIT_CODE='.$exitCode);
            $this->line('QUEUE_RUNNER_JOBS_PROCESSED='.$processed);
            $this->line('QUEUE_RUNNER_JOBS_FAILED='.$failed);
            $this->line('QUEUE_RUNNER_JOBS_TIMED_OUT='.$timedOut);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // queue:work itself classifies/contains job-level failures internally
            // (they surface via JobFailed above, never an exception here) - reaching
            // this catch means the runner invocation itself broke (e.g. a queue
            // connection/config problem), not a single job's business failure.
            $this->line('QUEUE_RUNNER_STATUS=FAILED');
            $this->line('QUEUE_RUNNER_EXIT_CODE=1');
            $this->line('QUEUE_RUNNER_ERROR='.$e::class);

            return self::FAILURE;
        } finally {
            // No Event::forget() here: this is a one-shot CLI process that exits
            // immediately after handle() returns, so the closures registered above
            // are never invoked again regardless - Event::forget() would remove
            // ALL listeners for these event names, including any unrelated ones
            // a future feature might register, not just the ones added here.
            $lock->release();
        }
    }
}
