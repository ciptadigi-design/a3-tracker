<?php

namespace App\Jobs;

use App\Models\MaintenanceDocumentImport;
use App\Services\GovernanceAudit;
use App\Services\KnowledgeProcessing\MaintenanceKnowledgeProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * V1.6 - one bounded page-range chunk of PDF knowledge processing. Never
 * processes an entire document in one job: dispatches the next chunk itself
 * when more pages remain (Section N), on the same database/default queue
 * V1.5.6 already proved is drained automatically by Hostinger cron - no new
 * queue infrastructure, no second cron entry.
 *
 * Resumable/idempotent by construction: MaintenanceKnowledgeProcessingService::
 * processChunk() only ever creates entries that do not already exist for this
 * import (firstOrCreate on the identity key) and writes pages_processed/
 * candidate_count as absolute values, not increments - a retried chunk (e.g.
 * after this job's own attempt was killed mid-run) is always safe to just
 * run again from the same $fromPage.
 */
class ProcessMaintenanceKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 90, 300];

    public function __construct(public string $importId, public int $fromPage, public int $chunkSize) {}

    public function handle(MaintenanceKnowledgeProcessingService $service): void
    {
        $import = MaintenanceDocumentImport::with('document')->find($this->importId);
        if (! $import || $import->status !== 'PROCESSING') {
            // Deleted, or already finalized/restarted through another path - a stale
            // chunk job (e.g. a slow retry racing a fresh restart) must never resurrect
            // or duplicate work outside its own run.
            return;
        }

        $result = $service->processChunk($import, $this->fromPage, $this->chunkSize);

        if (! $result['done']) {
            self::dispatch($this->importId, $result['to_page'] + 1, $this->chunkSize);

            return;
        }

        $accountId = $import->document->account_id;
        $audit = app(GovernanceAudit::class);
        $before = $audit->snapshot($import);
        $import->update(['status' => 'REVIEW', 'processing_completed_at' => now()]);
        $audit->changed(null, 'knowledge_processing_completed', 'maintenance_document_import', $import->id, $accountId, $before, $audit->snapshot($import), array_keys($import->getChanges()));
    }

    /**
     * Safety net matching ExtractMaintenanceDocumentJob's own precedent: guarantees
     * the import never gets stuck showing PROCESSING forever after retries are
     * exhausted (or the job could not even be resolved/deserialized).
     */
    public function failed(Throwable $e): void
    {
        $import = MaintenanceDocumentImport::with('document')->find($this->importId);
        if (! $import || $import->status !== 'PROCESSING') {
            return;
        }

        $audit = app(GovernanceAudit::class);
        $before = $audit->snapshot($import);
        $import->update(['status' => 'FAILED', 'processing_completed_at' => now()]);
        $audit->changed(null, 'knowledge_processing_failed', 'maintenance_document_import', $import->id, $import->document->account_id, $before, $audit->snapshot($import), array_keys($import->getChanges()));
    }
}
