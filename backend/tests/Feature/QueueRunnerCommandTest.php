<?php

namespace Tests\Feature;

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

    public function test_command_is_a_no_op_on_an_empty_queue(): void
    {
        $this->assertSame(0, DB::table('jobs')->count());

        // $this->artisan() (not Artisan::call() + Artisan::output()) - the command
        // internally makes its own nested Artisan::call('queue:work', ...), and
        // Artisan::output() reflects whichever command's buffer was used most
        // recently (the nested one), not necessarily this top-level command's own
        // output. $this->artisan()'s command-tester output stays scoped correctly.
        $this->artisan('a3:run-queue', ['--max-time' => 5, '--max-jobs' => 5])
            ->assertExitCode(0)
            ->expectsOutputToContain('QUEUE_RUNNER_EXIT_CODE=0');
    }

    public function test_command_drains_a_queued_successful_extraction_job(): void
    {
        $doc = $this->document();
        $this->attachStoredPdf($doc, $this->validPdf());
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);
        ExtractMaintenanceDocumentJob::dispatch($extraction->id);
        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('a3:run-queue', ['--max-time' => 10, '--max-jobs' => 5]);

        $this->assertSame(0, DB::table('jobs')->count());
        $extraction->refresh();
        $this->assertSame('COMPLETED', $extraction->status);
        $this->assertSame(1, $extraction->total_pages);
    }

    public function test_command_marks_a_failing_extraction_job_as_failed_via_the_real_queue_pipeline(): void
    {
        $doc = $this->document();
        $this->attachStoredPdf($doc, "not a real pdf\nno valid xref here");
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);
        ExtractMaintenanceDocumentJob::dispatch($extraction->id);

        Artisan::call('a3:run-queue', ['--max-time' => 10, '--max-jobs' => 5]);

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertNotNull($extraction->error_message);
        // The job's own backoff ([30, 90, 300]) re-releases it with a future
        // available_at rather than exhausting retries within this one bounded
        // invocation - it is correctly still sitting in `jobs` awaiting its next
        // scheduled attempt, not lost, and not yet in Laravel's own failed_jobs
        // table (that only happens once every try is exhausted - a Laravel
        // queue-internals guarantee, not something this command changes).
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_concurrent_invocation_is_skipped_via_the_lock(): void
    {
        $doc = $this->document();
        $this->attachStoredPdf($doc, $this->validPdf());
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);
        ExtractMaintenanceDocumentJob::dispatch($extraction->id);

        $lock = Cache::lock('a3-queue-runner', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('a3:run-queue', ['--max-time' => 10, '--max-jobs' => 5])
                ->assertExitCode(0)
                ->expectsOutputToContain('QUEUE_RUNNER_SKIPPED=already_running');
            // The job was never touched - still queued, extraction still PENDING.
            $this->assertSame(1, DB::table('jobs')->count());
            $extraction->refresh();
            $this->assertSame('PENDING', $extraction->status);
        } finally {
            $lock->release();
        }
    }

    public function test_extraction_job_dispatch_still_pushes_to_the_configured_queue_connection(): void
    {
        Queue::fake();
        $doc = $this->document();
        $this->attachStoredPdf($doc, $this->validPdf());
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $doc->id, 'status' => 'PENDING', 'processed_pages' => 0]);

        ExtractMaintenanceDocumentJob::dispatch($extraction->id);

        Queue::assertPushed(ExtractMaintenanceDocumentJob::class, fn ($job) => $job->extractionId === $extraction->id);
    }
}
