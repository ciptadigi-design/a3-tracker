<?php

namespace App\Console\Commands;

use App\Jobs\QueueHealthCheckJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * V1.5.6 - dispatch and check a minimal, side-effect-free queue automation
 * acceptance job (QueueHealthCheckJob) - internal/operator-only surface (no
 * public HTTP route) so cron/automation can be proven end to end without
 * re-running a real business job (e.g. the 2639-page Konica extraction) or
 * needing Tinker/raw SQL to dispatch and read back a marker.
 *
 * Usage:
 *   php artisan a3:queue-health-check --dispatch
 *     -> prints QUEUE_HEALTH_CHECK_TOKEN=<token>, dispatches the job
 *   php artisan a3:queue-health-check --check=<token>
 *     -> prints QUEUE_HEALTH_CHECK_STATUS=COMPLETED|PENDING
 */
class QueueHealthCheck extends Command
{
    protected $signature = 'a3:queue-health-check
        {--dispatch : Dispatch a new health-check job and print its token}
        {--check= : Check whether the job for the given token has completed}';

    protected $description = 'Dispatch or check a minimal side-effect-free job for proving automatic queue processing (V1.5.6 cron acceptance)';

    public function handle(): int
    {
        $dispatch = (bool) $this->option('dispatch');
        $check = $this->option('check');

        if (! $dispatch && ! $check) {
            $this->error('Pass either --dispatch or --check=<token>.');

            return self::FAILURE;
        }

        if ($dispatch) {
            $token = (string) Str::uuid();
            QueueHealthCheckJob::dispatch($token);
            $this->line('QUEUE_HEALTH_CHECK_TOKEN='.$token);
            $this->line('QUEUE_HEALTH_CHECK_DISPATCHED=1');

            return self::SUCCESS;
        }

        $completedAt = Cache::get(QueueHealthCheckJob::cacheKey($check));
        if ($completedAt !== null) {
            $this->line('QUEUE_HEALTH_CHECK_STATUS=COMPLETED');
            $this->line('QUEUE_HEALTH_CHECK_COMPLETED_AT='.$completedAt);
        } else {
            $this->line('QUEUE_HEALTH_CHECK_STATUS=PENDING');
        }

        return self::SUCCESS;
    }
}
