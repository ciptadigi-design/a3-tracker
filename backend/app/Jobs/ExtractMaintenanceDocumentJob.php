<?php

namespace App\Jobs;

use App\Models\MaintenanceDocumentExtraction;
use App\Services\DocumentExtractionService;
use App\Services\GovernanceAudit;
use App\Services\PdfExtraction\PdfExtractionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * V1.5 - PDF Knowledge Extraction Foundation. The first queue job in this app
 * (QUEUE_CONNECTION=database, already the configured default - see config/queue.php
 * and composer.json's `dev` script, which already runs `queue:listen` locally).
 *
 * V1.5.3: permanent-vs-transient failure handling. DocumentExtractionService (via
 * PdfTextExtractor) always throws a classified PdfExtractionException; any other
 * Throwable reaching this job is treated as an unclassified EXTRACTION_RUNTIME_FAILURE.
 * PdfExtractionErrorCode::isPermanent() decides what happens next:
 *  - permanent (e.g. UNSUPPORTED_PDF_SECURITY, INVALID_OR_CORRUPT_PDF, FILE_MISSING,
 *    RESOURCE_LIMIT): the extraction row is marked FAILED and $this->fail() tells
 *    Laravel's queue this attempt is terminal - `tries`/`backoff` below never get a
 *    chance to burn further attempts on a failure a retry cannot fix.
 *  - transient (EXTRACTION_RUNTIME_FAILURE only): the extraction row is still marked
 *    FAILED for this attempt (so the UI is never stuck on a stale PROCESSING/PENDING
 *    while a retry is pending), but the exception is re-thrown so Laravel's normal
 *    tries/backoff still applies - a following attempt can flip it back to
 *    PROCESSING/COMPLETED.
 *
 * Production note (not solved here - this is an implementation-only change, no
 * deployment): docs/hostinger/HOSTINGER_REQUIREMENTS.md records that Hostinger has
 * no persistent worker process and no cron currently configured for
 * `schedule:run`; its own guidance for an async candidate is "sync/cron/database-
 * queue-with-cron, never Supervisor-dependent". Until a cron entry running
 * `php artisan queue:work --stop-when-empty` (or `schedule:run` invoking it) is
 * added to the production deployment, jobs dispatched here will sit PENDING in
 * production until that cron entry exists.
 */
class ExtractMaintenanceDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 90, 300];

    public function __construct(public string $extractionId) {}

    public function handle(DocumentExtractionService $service): void
    {
        $extraction = MaintenanceDocumentExtraction::with('document')->find($this->extractionId);
        if (! $extraction) {
            // Extraction (or its document) was deleted before the job ran - nothing to do.
            return;
        }

        $audit = app(GovernanceAudit::class);
        $accountId = $extraction->document->account_id;

        $before = $audit->snapshot($extraction);
        // Clears any FAILED markers a prior (transient, retried) attempt left behind
        // - each attempt starts from a clean lifecycle state rather than showing
        // PROCESSING alongside a stale completed_at/error_code from the last attempt.
        $extraction->update(['status' => 'PROCESSING', 'started_at' => now(), 'completed_at' => null, 'error_code' => null, 'error_message' => null]);
        $audit->changed(null, 'maintenance_document_extraction.started', 'maintenance_document_extraction', $extraction->id, $accountId, $before, $audit->snapshot($extraction), array_keys($extraction->getChanges()));

        try {
            $service->process($extraction);
            $extraction->refresh();
            $before = $audit->snapshot($extraction);
            $extraction->update(['status' => 'COMPLETED', 'completed_at' => now()]);
            $audit->changed(null, 'maintenance_document_extraction.completed', 'maintenance_document_extraction', $extraction->id, $accountId, $before, $audit->snapshot($extraction), array_keys($extraction->getChanges()));
        } catch (Throwable $e) {
            $classified = $e instanceof PdfExtractionException ? $e : PdfExtractionException::runtimeFailure($e);

            $extraction->refresh();
            $before = $audit->snapshot($extraction);
            $extraction->update([
                'status' => 'FAILED',
                'error_code' => $classified->errorCode->value,
                'error_message' => $classified->errorCode->userMessage(),
                'completed_at' => now(),
            ]);
            $audit->changed(null, 'maintenance_document_extraction.failed', 'maintenance_document_extraction', $extraction->id, $accountId, $before, $audit->snapshot($extraction), array_merge(array_keys($extraction->getChanges()), ['error_message']));

            if ($classified->errorCode->isPermanent()) {
                $this->fail($classified);

                return;
            }

            // Re-thrown so Laravel's own retry/backoff still applies - the extraction
            // row already reflects this attempt's true outcome regardless of whether a
            // retry later succeeds and flips it back to PROCESSING/COMPLETED.
            throw $classified;
        }
    }

    /**
     * Safety net for failures the handle() try/catch never reached (e.g. the job
     * couldn't even be resolved/deserialized) - guarantees the extraction never
     * gets stuck showing PENDING/PROCESSING after retries are exhausted. Also the
     * path Laravel invokes after handle() calls $this->fail() for a permanent
     * classified failure, and after tries are exhausted on a transient one.
     */
    public function failed(Throwable $e): void
    {
        $extraction = MaintenanceDocumentExtraction::with('document')->find($this->extractionId);
        if (! $extraction || $extraction->status === 'FAILED') {
            return;
        }

        $classified = $e instanceof PdfExtractionException ? $e : PdfExtractionException::runtimeFailure($e);

        $audit = app(GovernanceAudit::class);
        $before = $audit->snapshot($extraction);
        $extraction->update([
            'status' => 'FAILED',
            'error_code' => $classified->errorCode->value,
            'error_message' => $classified->errorCode->userMessage(),
            'completed_at' => now(),
        ]);
        $audit->changed(null, 'maintenance_document_extraction.failed', 'maintenance_document_extraction', $extraction->id, $extraction->document->account_id, $before, $audit->snapshot($extraction), array_merge(array_keys($extraction->getChanges()), ['error_message']));
    }
}
