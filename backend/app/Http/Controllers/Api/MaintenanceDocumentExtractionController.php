<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractMaintenanceDocumentJob;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentExtraction;
use App\Models\MaintenanceDocumentPage;
use App\Services\AccountAccessResolver;
use App\Services\DocumentStorageService;
use App\Services\GovernanceAudit;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * PDF Knowledge Extraction Foundation (V1.5): turns an uploaded document's stored
 * PDF (DocumentStorageService, V1.4) into structured per-page text
 * (maintenance_document_pages), tracked through a lifecycle record
 * (maintenance_document_extractions). No AI/knowledge processing here - this is
 * strictly the extraction step; turning extracted pages into
 * maintenance_knowledge_entries stays a future phase, the same way V1.3's manual
 * entry workflow was a deliberate foundation before this one.
 *
 * A dedicated controller, not new methods on MaintenanceDocumentController -
 * matching the same "separate controller per sub-feature" precedent
 * MaintenanceKnowledgeImportController already set for document-imports.
 *
 * Authorization mirrors every other document endpoint exactly: starting an
 * extraction is a mutation (AccountAccessResolver::canManageCatalogScope, the same
 * machines.manage/Superuser gate as upload/delete-file), reading extraction status
 * or pages is open to any active member of the document's account (or anyone, for
 * a global document) - the same visibility rule as show()/download(). No new
 * capability.
 */
class MaintenanceDocumentExtractionController extends Controller
{
    private function authorizeCatalogScope(Request $r, ?string $accountId): void
    {
        abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $accountId), 403);
    }

    private function findVisibleDocument(Request $r, string $id): MaintenanceDocument
    {
        $doc = MaintenanceDocument::findOrFail($id);
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        abort_unless($doc->account_id === null || $ids->contains($doc->account_id), 404);

        return $doc;
    }

    public function store(Request $r, string $document)
    {
        $doc = MaintenanceDocument::findOrFail($document);
        $this->authorizeCatalogScope($r, $doc->account_id);
        if (! app(DocumentStorageService::class)->hasStoredFile($doc)) {
            throw new ConflictHttpException('[NO_STORED_FILE] This document has no uploaded PDF to extract.');
        }
        if (MaintenanceDocumentExtraction::where('document_id', $doc->id)->whereIn('status', ['PENDING', 'PROCESSING'])->exists()) {
            throw new ConflictHttpException('[EXTRACTION_IN_PROGRESS] An extraction is already running for this document.');
        }

        // processed_pages/total_pages explicit, not left to the DB default - a column
        // absent from create()'s array never appears on the in-memory model afterwards
        // even though the DB applies a default, so the immediate JSON response would
        // silently omit it (same class of bug fixed in DocumentStorageService::store()).
        $extraction = MaintenanceDocumentExtraction::create([
            'document_id' => $doc->id,
            'status' => 'PENDING',
            'processed_pages' => 0,
            'created_by' => $r->user()->id,
        ]);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document_extraction.requested', 'maintenance_document_extraction', $extraction->id, $doc->account_id, [], app(GovernanceAudit::class)->snapshot($extraction));

        ExtractMaintenanceDocumentJob::dispatch($extraction->id);

        return response()->json(['data' => $extraction], 201);
    }

    public function show(Request $r, string $document)
    {
        $doc = $this->findVisibleDocument($r, $document);
        $extraction = MaintenanceDocumentExtraction::where('document_id', $doc->id)->orderByDesc('created_at')->first();

        // Extraction is optional (V1.4 documents predate this V1.5 feature and never
        // get one manually created for them - see class docblock). `data: null` here
        // is a real, legitimate response, but src/lib/api/apiClient.js's unwrapData()
        // uses `payload?.data ?? payload`, and `??` treats a genuinely-null `data` the
        // same as a missing one, so it falls back to returning the whole `{data:
        // null}` envelope instead of `null` - the frontend then destructures a
        // `status` that was never there. An explicit NONE state (not a persisted row)
        // sidesteps that ambiguity entirely rather than changing unwrapData's
        // fallback behavior for every other endpoint that relies on it.
        return response()->json(['data' => $extraction ?? ['status' => 'NONE']]);
    }

    public function pages(Request $r, string $document)
    {
        $doc = $this->findVisibleDocument($r, $document);
        $perPage = min((int) $r->integer('per_page', 20), 50);
        $pages = MaintenanceDocumentPage::where('document_id', $doc->id)->orderBy('page_number')->paginate($perPage);

        return response()->json(['data' => $pages]);
    }
}
