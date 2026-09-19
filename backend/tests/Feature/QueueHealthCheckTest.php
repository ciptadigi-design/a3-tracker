<?php

namespace Tests\Feature;

use App\Jobs\QueueHealthCheckJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V1.5.6 - the small controlled acceptance mechanism used to prove Production
 * cron automation actually drains a real queued job, without re-running the
 * real Konica extraction. Covers both the job's own no-mutation completion
 * marker and the dispatch/check command around it.
 */
class QueueHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_dispatch_prints_a_token_and_queues_a_real_database_job(): void
    {
        $this->assertSame(0, DB::table('jobs')->count());

        $this->artisan('a3:queue-health-check', ['--dispatch' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_HEALTH_CHECK_DISPATCHED=1');

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_check_reports_pending_before_the_job_runs_and_completed_after(): void
    {
        $token = 'test-token-123';

        $this->artisan('a3:queue-health-check', ['--check' => $token])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_HEALTH_CHECK_STATUS=PENDING');

        (new QueueHealthCheckJob($token))->handle();

        $this->artisan('a3:queue-health-check', ['--check' => $token])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_HEALTH_CHECK_STATUS=COMPLETED');
    }

    public function test_job_writes_only_a_timestamp_marker_no_business_mutation(): void
    {
        $token = 'no-mutation-token';
        (new QueueHealthCheckJob($token))->handle();

        $value = Cache::get(QueueHealthCheckJob::cacheKey($token));
        $this->assertNotNull($value);
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $value), 'marker must be a plain ISO-8601 timestamp, nothing else');
    }

    public function test_command_requires_either_dispatch_or_check(): void
    {
        $this->artisan('a3:queue-health-check')
            ->assertExitCode(1);
    }

    public function test_end_to_end_through_the_real_run_queue_command(): void
    {
        // Dispatch directly (rather than via --dispatch) so the token used for
        // the --check assertion below is known, not just printed to output.
        $token = 'e2e-token';
        QueueHealthCheckJob::dispatch($token);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('a3:run-queue', ['--max-time' => 10, '--max-jobs' => 5, '--timeout' => 20])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_RUNNER_STATUS=COMPLETED');

        $this->artisan('a3:queue-health-check', ['--check' => $token])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_HEALTH_CHECK_STATUS=COMPLETED');

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
