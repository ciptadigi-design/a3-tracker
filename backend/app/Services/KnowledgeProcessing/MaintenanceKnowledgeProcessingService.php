<?php

namespace App\Services\KnowledgeProcessing;

use App\Jobs\ProcessMaintenanceKnowledgeJob;
use App\Models\MachineErrorCode;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentExtraction;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentPage;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use App\Services\GovernanceAudit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * V1.6 - Extracted Knowledge Processing orchestration. Turns a COMPLETED
 * extraction's pages into draft maintenance_knowledge_entries candidates via
 * PdfKnowledgeCandidateDetector, in bounded page chunks dispatched as
 * separate queue jobs (see ProcessMaintenanceKnowledgeJob) - never inside an
 * HTTP request, never loading all pages into memory at once.
 *
 * The processing lifecycle reuses maintenance_document_imports/
 * maintenance_knowledge_entries wholesale (V1.3) rather than introducing a
 * parallel table - a PDF_EXTRACTION import IS one deterministic-detector
 * processing run over one extraction, distinguished from a MANUAL_ENTRY
 * import only by having an extraction_id and processing_* metadata.
 */
class MaintenanceKnowledgeProcessingService
{
    public const CURRENT_PROCESSING_VERSION = 1;

    public const DEFAULT_CHUNK_SIZE = 100;

    /**
     * Idempotent by (document_id, extraction_id, processing_version): calling
     * this twice for the same completed extraction returns the SAME import
     * row (and, because chunk processing itself is idempotent - see
     * ProcessMaintenanceKnowledgeJob - reprocessing never creates duplicate
     * candidates) rather than starting a second, competing processing run.
     * A later extraction (new extraction_id) always gets its own import row,
     * distinguishable from any prior run.
     */
    public function startProcessing(MaintenanceDocument $document, User $actor, ?string $machineModelId = null): MaintenanceDocumentImport
    {
        $extraction = MaintenanceDocumentExtraction::where('document_id', $document->id)
            ->where('status', 'COMPLETED')
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at')
            ->first();
        if (! $extraction) {
            throw new ConflictHttpException('[NO_COMPLETED_EXTRACTION] This document has no completed extraction to process.');
        }

        return DB::transaction(function () use ($document, $extraction, $actor, $machineModelId) {
            $import = MaintenanceDocumentImport::where('document_id', $document->id)
                ->where('extraction_id', $extraction->id)
                ->where('processing_version', self::CURRENT_PROCESSING_VERSION)
                ->lockForUpdate()
                ->first();

            if ($import) {
                if (in_array($import->status, ['PROCESSING'], true)) {
                    // Already running - return the existing run rather than dispatching a
                    // second overlapping chain of chunk jobs for the same extraction.
                    return $import;
                }

                // A prior run for this exact (document, extraction, version) exists but
                // finished (REVIEW/PUBLISHED) or failed - re-dispatching from page 1 is
                // safe: firstOrCreate() in the chunk job never touches already-created
                // entries, human-reviewed or not.
                $audit = app(GovernanceAudit::class);
                $before = $audit->snapshot($import);
                $import->update([
                    'status' => 'PROCESSING',
                    'processing_started_at' => now(),
                    'processing_completed_at' => null,
                ]);
                $audit->changed($actor, 'knowledge_processing_started', 'maintenance_document_import', $import->id, $document->account_id, $before, $audit->snapshot($import), array_keys($import->getChanges()));
            } else {
                $import = MaintenanceDocumentImport::create([
                    'document_id' => $document->id,
                    'extraction_id' => $extraction->id,
                    'machine_model_id' => $machineModelId ?? $document->machine_model_id,
                    'import_type' => 'PDF_EXTRACTION',
                    'status' => 'PROCESSING',
                    'created_by' => $actor->id,
                    'processing_started_at' => now(),
                    'processing_version' => self::CURRENT_PROCESSING_VERSION,
                ]);
                app(GovernanceAudit::class)->changed($actor, 'knowledge_processing_started', 'maintenance_document_import', $import->id, $document->account_id, [], app(GovernanceAudit::class)->snapshot($import));
            }

            ProcessMaintenanceKnowledgeJob::dispatch($import->id, 1, self::DEFAULT_CHUNK_SIZE);

            return $import;
        });
    }

    /**
     * Process one bounded page window [$fromPage, $toPage] of the import's
     * extraction. Returns the next page to process, or null if this was the
     * last chunk (caller/job decides whether to dispatch another chunk or
     * finalize). Never loads the whole document into memory: one bounded
     * query for the chunk's pages, plus exactly one extra page (for
     * continuation context on the chunk's last page).
     */
    public function processChunk(MaintenanceDocumentImport $import, int $fromPage, int $chunkSize): array
    {
        $document = $import->document;
        $totalPages = (int) MaintenanceDocumentPage::where('document_id', $document->id)->max('page_number');
        $toPage = min($fromPage + $chunkSize - 1, $totalPages);

        // Bounded window: the chunk's own pages plus one lookahead page for
        // continuation evidence on the last page in the chunk - never more.
        $pages = MaintenanceDocumentPage::where('document_id', $document->id)
            ->whereBetween('page_number', [$fromPage, $toPage + 1])
            ->orderBy('page_number')
            ->get(['page_number', 'raw_text'])
            ->keyBy('page_number');

        $accountId = $document->account_id;
        $machineModelId = $import->machine_model_id;

        for ($pageNumber = $fromPage; $pageNumber <= $toPage; $pageNumber++) {
            $page = $pages->get($pageNumber);
            if (! $page) {
                continue; // a gap should not happen, but never fatal - just skip
            }
            $nextPageText = $pages->get($pageNumber + 1)?->raw_text;

            foreach (PdfKnowledgeCandidateDetector::detectOnPage($page->raw_text, $nextPageText) as $candidate) {
                $sourcePageEnd = $candidate['spans_next_page'] ? $pageNumber + 1 : $pageNumber;
                $this->persistCandidateIfNew($import, $accountId, $machineModelId, $candidate, $pageNumber, $sourcePageEnd);
            }
        }

        // Absolute values (not increments) so a retried/duplicate chunk run is
        // always safe - never double-counts progress.
        $import->update([
            'pages_processed' => $toPage,
            'candidate_count' => $import->entries()->count(),
        ]);

        return ['to_page' => $toPage, 'total_pages' => $totalPages, 'done' => $toPage >= $totalPages];
    }

    private function persistCandidateIfNew(MaintenanceDocumentImport $import, ?string $accountId, ?string $machineModelId, array $candidate, int $pageStart, int $pageEnd): void
    {
        $existing = MachineErrorCode::where('account_id', $accountId)
            ->where('machine_model_id', $machineModelId)
            ->get()
            ->first(fn ($e) => ErrorCodeNormalizer::normalize($e->code) === $candidate['normalized_code']);

        $collisionStatus = $this->classifyCollision($existing, $candidate['evidence']);

        MaintenanceKnowledgeEntry::firstOrCreate(
            [
                'import_id' => $import->id,
                'normalized_code' => $candidate['normalized_code'],
                'source_page_start' => $pageStart,
                'source_page_end' => $pageEnd,
            ],
            [
                'extraction_id' => $import->extraction_id,
                'knowledge_type' => 'ERROR_CODE',
                'code' => $candidate['normalized_code'],
                'title' => $candidate['title'],
                'description' => $candidate['description'],
                'evidence' => $candidate['evidence'],
                'collision_status' => $collisionStatus,
                'page_reference' => $pageStart === $pageEnd ? (string) $pageStart : "{$pageStart}-{$pageEnd}",
                'status' => 'DRAFT',
            ]
        );
    }

    /**
     * NEW: no existing machine_error_code matches this normalized code at all.
     * POTENTIAL_UPDATE: a match exists but is missing manufacturer description
     * or an official solution, and this candidate's evidence is HIGH (i.e. was
     * found near real Cause/Action text) - a deterministic signal that this
     * candidate may carry more complete information than what is published.
     * EXISTING: a match exists and already carries description/solution
     * content - nothing this candidate found is presumed to improve it.
     */
    private function classifyCollision(?MachineErrorCode $existing, string $evidence): string
    {
        if (! $existing) {
            return 'NEW';
        }
        $existingHasContent = ! empty($existing->manufacturer_description) || ! empty($existing->official_solution);
        if (! $existingHasContent && $evidence === 'HIGH') {
            return 'POTENTIAL_UPDATE';
        }

        return 'EXISTING';
    }
}
