<?php

namespace Tests\Feature;

use App\Console\Commands\RunQueueOnce;
use App\Jobs\ExtractMaintenanceDocumentJob;
use App\Models\Account;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentExtraction;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Maintenance V1.5.1 - Hostinger Queue Runner Compatibility. `a3:run-queue` is
 * what a Hostinger cron entry actually calls - a single bounded `queue:work
 * --stop-when-empty` invocation (no Supervisor, no daemon), guarded by a
 * cache-lock so two overlapping cron ticks never run the drain concurrently.
 *
 * phpunit.xml sets QUEUE_CONNECTION=sync, so every other feature test's
 * dispatch() calls run inline rather than touching the `jobs` table at all -
 * these tests deliberately switch to the real `database` connection to prove
 * the command drains an actually-queued job end to end, success and failure,
 * through the real queue:work pipeline (not a direct ->handle() call, which is
 * what MaintenanceDocumentExtractionTest already covers for the job's own logic).
 *
 * V1.5.6: pins the new QUEUE_RUNNER_STATUS=... observability contract (see
 * RunQueueOnce's own docblock for why the old silent-exit behavior and the old
 * --timeout/retry_after values were unsafe for a real large-PDF extraction) and
 * the lock-TTL/queue-targeting/exception-safety guarantees that contract
 * depends on.
 */
class QueueRunnerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentStorageService::DISK);
        config(['queue.default' => 'database']);
    }

    private function document(): MaintenanceDocument
    {
        $account = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);

        return MaintenanceDocument::create(['account_id' => $account->id, 'title' => 'Konica C1070 Service Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
    }

    private function attachStoredPdf(MaintenanceDocument $doc, string $bytes): void
    {
        $path = DocumentStorageService::DIRECTORY.'/'.$doc->id.'.pdf';
        Storage::disk(DocumentStorageService::DISK)->put($path, $bytes);
        $doc->update(['storage_disk' => DocumentStorageService::DISK, 'file_path' => $path, 'file_name' => 'manual.pdf', 'file_size' => strlen($bytes), 'mime_type' => 'application/pdf', 'uploaded_at' => now()]);
    }

    /** A minimal but genuinely valid one-page PDF - same builder proven against Smalot in MaintenanceDocumentExtractionTest. */
    private function validPdf(): string
    {
        $stream = 'BT /F1 12 Tf 10 250 Td (Hello Queue Runner) Tj ET';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }
        $xrefStart = strlen($out);
        $out .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i < 6; $i++) {
            $out .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }
        $out .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xrefStart\n%%EOF";

        return $out;
    }

    // --- 1/3/4. no jobs -> deterministic, explicit successful output ---

    public function test_command_is_a_no_op_on_an_empty_queue(): void
    {
        $this->assertSame(0, DB::table('jobs')->count());

        // $this->artisan() (not Artisan::call() + Artisan::output()) - the command
        // internally makes its own nested Artisan::call('queue:work', ...), and
        // Artisan::output() reflects whichever command's buffer was used most
        // recently (the nested one), not necessarily this top-level command's own
        // output. $this->artisan()'s command-tester output stays scoped correctly.
        $this->artisan('a3:run-queue', ['--max-time' => 5, '--max-jobs' => 5, '--timeout' => 10])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_RUNNER_STATUS=STARTED')
            ->expectsOutputToContain('QUEUE_RUNNER_STATUS=COMPLETED')
            ->expectsOutputToContain('QUEUE_RUNNER_EXIT_CODE=0')
            ->expectsOutputToContain('QUEUE_RUNNER_JOBS_PROCESSED=0');
    }

    // --- 1/4. lock acquired -> worker invoked, successful runner status ---

    public function test_command_drains_a_queued_successful_extraction_job(): void
    {
        $doc = $this->document();
        $this->attachStoredPdf($doc, $this->validPdf());
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);
        ExtractMaintenanceDocumentJob::dispatch($extraction->id);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('a3:run-queue', ['--max-time' => 10, '--max-jobs' => 5, '--timeout' => 20])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_RUNNER_STATUS=STARTED')
            ->expectsOutputToContain('QUEUE_RUNNER_STATUS=COMPLETED')
            ->expectsOutputToContain('QUEUE_RUNNER_JOBS_PROCESSED=1')
            ->expectsOutputToContain('QUEUE_RUNNER_JOBS_FAILED=0');

        $this->assertSame(0, DB::table('jobs')->count());
        $extraction->refresh();
        $this->assertSame('COMPLETED', $extraction->status);
        $this->assertSame(1, $extraction->total_pages);
    }

    // --- 5. a permanently-failing job surfaces in the failed count, not a silent success ---

    public function test_command_marks_a_failing_extraction_job_as_failed_via_the_real_queue_pipeline(): void
    {
        $doc = $this->document();
        $this->attachStoredPdf($doc, "not a real pdf\nno valid xref here");
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);
        ExtractMaintenanceDocumentJob::dispatch($extraction->id);

        $this->artisan('a3:run-queue', ['--max-time' => 10, '--max-jobs' => 5, '--timeout' => 20])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_RUNNER_STATUS=COMPLETED')
            ->expectsOutputToContain('QUEUE_RUNNER_JOBS_FAILED=1');

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertSame('INVALID_OR_CORRUPT_PDF', $extraction->error_code);
        $this->assertNotNull($extraction->error_message);
        // V1.5.3: a corrupt/invalid PDF is a PERMANENT classification (see
        // PdfExtractionErrorCode::isPermanent()) - ExtractMaintenanceDocumentJob
        // calls $this->fail() instead of letting the job's own backoff ([30, 90,
        // 300]) re-release it, so it is removed from `jobs` and moved straight to
        // Laravel's own `failed_jobs` table by the framework's normal $job->fail()
        // handling, rather than sitting queued for a retry that cannot succeed.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    // --- 2. lock held -> explicit SKIPPED output, job never touched ---

    public function test_concurrent_invocation_is_skipped_via_the_lock(): void
    {
        $doc = $this->document();
        $this->attachStoredPdf($doc, $this->validPdf());
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);
        ExtractMaintenanceDocumentJob::dispatch($extraction->id);

        $lock = Cache::lock('a3-queue-runner', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('a3:run-queue', ['--max-time' => 10, '--max-jobs' => 5, '--timeout' => 20])
                ->assertExitCode(0)
                ->expectsOutputToContain('QUEUE_RUNNER_STATUS=SKIPPED')
                ->expectsOutputToContain('QUEUE_RUNNER_REASON=LOCK_HELD')
                ->doesntExpectOutputToContain('QUEUE_RUNNER_STATUS=STARTED');
            // The job was never touched - still queued, extraction still PENDING.
            $this->assertSame(1, DB::table('jobs')->count());
            $extraction->refresh();
            $this->assertSame('PENDING', $extraction->status);
        } finally {
            $lock->release();
        }
    }

    // --- 6. lock released after normal completion - a following invocation can acquire it ---

    public function test_lock_is_released_after_a_normal_completion_so_the_next_tick_can_acquire_it(): void
    {
        $this->artisan('a3:run-queue', ['--max-time' => 5, '--max-jobs' => 5, '--timeout' => 10])->assertExitCode(0);

        $lock = Cache::lock('a3-queue-runner', 5);
        $this->assertTrue($lock->get(), 'a fresh cron tick must be able to acquire the lock immediately after a clean run');
        $lock->release();
    }

    // --- 7. exception path does not permanently strand the lock ---

    public function test_lock_is_released_even_when_the_runner_invocation_itself_throws(): void
    {
        // Force queue:work's own connection resolution to blow up (not a per-job
        // business failure - those are handled internally by queue:work and never
        // reach RunQueueOnce's catch block, see test above) by pointing the
        // "database" queue connection at a driver Laravel does not recognize.
        config(['queue.connections.database.driver' => 'not-a-real-driver']);

        $this->artisan('a3:run-queue', ['--max-time' => 5, '--max-jobs' => 5, '--timeout' => 10])
            ->assertExitCode(1)
            ->expectsOutputToContain('QUEUE_RUNNER_STATUS=FAILED')
            ->expectsOutputToContain('QUEUE_RUNNER_EXIT_CODE=1');

        config(['queue.connections.database.driver' => 'database']);
        $lock = Cache::lock('a3-queue-runner', 5);
        $this->assertTrue($lock->get(), 'the lock must not be stranded after the runner invocation itself throws');
        $lock->release();
    }

    // --- 8. configured worker bounds are correct ---

    public function test_default_worker_bounds_match_the_documented_v1_5_6_calibration(): void
    {
        $definition = Artisan::all()['a3:run-queue']->getDefinition();

        $this->assertSame('50', $definition->getOption('max-time')->getDefault());
        $this->assertSame('25', $definition->getOption('max-jobs')->getDefault());
        // 300s: ~2.8x margin above the ~107s real large-PDF extraction observed in
        // V1.5.5 Production acceptance (see RunQueueOnce's own docblock).
        $this->assertSame('300', $definition->getOption('timeout')->getDefault());
    }

    public function test_lock_ttl_is_derived_from_max_time_plus_timeout_not_a_fixed_constant(): void
    {
        // With the defaults above, the lock TTL is 50 + 300 + 30 = 380s - long
        // enough that it cannot expire while a job is legitimately still running
        // up to the full --timeout ceiling, unlike the pre-V1.5.6 fixed
        // "--max-time + 60" formula (110s) which was only 3 seconds above the
        // real ~107s job it needed to survive.
        $this->artisan('a3:run-queue', ['--max-time' => 5, '--max-jobs' => 5, '--timeout' => 10])->assertExitCode(0);

        // Immediately after release, a lock requested with a TTL shorter than the
        // real one used (5+10+30=45s) must still be freely acquirable - proves the
        // real run did not leave anything artificially held.
        $lock = Cache::lock('a3-queue-runner', 1);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    // --- 9. command targets the database connection's default queue as intended ---

    public function test_extraction_job_dispatch_still_pushes_to_the_configured_queue_connection(): void
    {
        Queue::fake();
        $doc = $this->document();
        $this->attachStoredPdf($doc, $this->validPdf());
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);

        ExtractMaintenanceDocumentJob::dispatch($extraction->id);

        Queue::assertPushed(ExtractMaintenanceDocumentJob::class, fn ($job) => $job->extractionId === $extraction->id);
    }

    public function test_runner_only_drains_the_default_queue_not_an_unrelated_one(): void
    {
        $doc = $this->document();
        $this->attachStoredPdf($doc, $this->validPdf());
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);
        ExtractMaintenanceDocumentJob::dispatch($extraction->id)->onQueue('unrelated-queue');

        $this->artisan('a3:run-queue', ['--max-time' => 5, '--max-jobs' => 5, '--timeout' => 10])
            ->expectsOutputToContain('QUEUE_RUNNER_JOBS_PROCESSED=0');

        // Still queued - a3:run-queue (no --queue override) only drains the
        // connection's configured default queue ('default'), matching what a real
        // dispatch() (no ->onQueue() override) actually lands on.
        $this->assertSame(1, DB::table('jobs')->count());
        $extraction->refresh();
        $this->assertSame('PENDING', $extraction->status);
    }
}
