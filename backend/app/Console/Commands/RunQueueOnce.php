<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

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
 * A cache-lock guard (Cache::lock, backed by the existing `cache_locks` table -
 * CACHE_STORE=database already, no schema change) prevents two cron ticks from
 * overlapping if one invocation is still mid-job (e.g. a slow large-PDF
 * extraction) when the next minute's cron fires; the lock's own TTL is a safety
 * net that self-expires even if a run is killed by a host execution-time limit
 * before it can release the lock cleanly - the runner can never wedge itself
 * permanently stuck.
 */
class RunQueueOnce extends Command
{
    protected $signature = 'a3:run-queue
        {--max-time=50 : Maximum seconds this invocation may run before stopping, leaving headroom before the next cron tick}
        {--max-jobs=25 : Maximum number of jobs this invocation will process before stopping}';

    protected $description = 'Drain the database queue once, safely, for a Hostinger cron entry (no Supervisor, no long-running worker)';

    public function handle(): int
    {
        $maxTime = max(1, (int) $this->option('max-time'));
        $maxJobs = max(1, (int) $this->option('max-jobs'));

        $lock = Cache::lock('a3-queue-runner', $maxTime + 60);
        if (! $lock->get()) {
            $this->line('QUEUE_RUNNER_SKIPPED=already_running');

            return self::SUCCESS;
        }

        try {
            $exitCode = Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => $maxTime,
                '--max-jobs' => $maxJobs,
                '--sleep' => 1,
                '--tries' => 3,
            ]);
            $this->output->write(Artisan::output());
            $this->line('QUEUE_RUNNER_EXIT_CODE='.$exitCode);

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
