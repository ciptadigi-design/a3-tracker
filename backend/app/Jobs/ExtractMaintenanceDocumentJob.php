<?php

namespace App\Jobs;

use App\Models\MaintenanceDocumentExtraction;
use App\Services\DocumentExtractionService;
use App\Services\GovernanceAudit;
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
 * Production note (not solved here - this is an implementation-only change, no
 * deployment): docs/hostinger/HOSTINGER_REQUIREMENTS.md records that Hostinger has
 * no persistent worker process and no cron currently configured for
 * `schedule:run`; its own guidance for an async candidate is "sync/cron/database-
 * queue-with-cron, never Supervisor-dependent". Until a cron entry running
 * `php artisan queue:work --stop-when-empty` (or `schedule:run` invoking it) is
 * added to the production deployment, jobs dispatched here will sit PENDING in
 * production until that cron entry exists - a deployment-time task for whenever
 * V1.5 actually ships, tracked here rather than silently assumed away.
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
        $extraction->update(['status' => 'PROCESSING', 'started_at' => now()]);
        $audit->changed(null, 'maintenance_document_extraction.started', 'maintenance_document_extraction', $extraction->id, $accountId, $before, $audit->snapshot($extraction), array_keys($extraction->getChanges()));

        try {
            $service->process($extraction);
            $extraction->refresh();
            $before = $audit->snapshot($extraction);
            $extraction->update(['status' => 'COMPLETED', 'completed_at' => now()]);
            $audit->changed(null, 'maintenance_document_extraction.completed', 'maintenance_document_extraction', $extraction->id, $accountId, $before, $audit->snapshot($extraction), array_keys($extraction->getChanges()));
        } catch (Throwable $e) {
            $extraction->refresh();
            $before = $audit->snapshot($extraction);
            $extraction->update(['status' => 'FAILED', 'error_message' => $e->getMessage()]);
            $audit->changed(null, 'maintenance_document_extraction.failed', 'maintenance_document_extraction', $extraction->id, $accountId, $before, $audit->snapshot($extraction), array_merge(array_keys($extraction->getChanges()), ['error_message']));

            // Re-thrown so Laravel's own retry/backoff still applies - the extraction
            // row already reflects this attempt's true outcome regardless of whether a
            // retry later succeeds and flips it back to PROCESSING/COMPLETED.
            throw $e;
        }
    }

    /**
     * Safety net for failures the handle() try/catch never reached (e.g. the job
     * couldn't even be resolved/deserialized) - guarantees the extraction never
     * gets stuck showing PENDING/PROCESSING after retries are exhausted.
     */
    public function failed(Throwable $e): void
    {
        $extraction = MaintenanceDocumentExtraction::with('document')->find($this->extractionId);
        if (! $extraction || $extraction->status === 'FAILED') {
            return;
        }

        $audit = app(GovernanceAudit::class);
        $before = $audit->snapshot($extraction);
        $extraction->update(['status' => 'FAILED', 'error_message' => $e->getMessage()]);
        $audit->changed(null, 'maintenance_document_extraction.failed', 'maintenance_document_extraction', $extraction->id, $extraction->document->account_id, $before, $audit->snapshot($extraction), array_merge(array_keys($extraction->getChanges()), ['error_message']));
    }
}
