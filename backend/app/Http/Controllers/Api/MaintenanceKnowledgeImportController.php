<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeEntry;
use App\Services\AccountAccessResolver;
use App\Services\GovernanceAudit;
use App\Services\KnowledgeProcessing\MaintenanceKnowledgeProcessingService;
use App\Services\MaintenanceKnowledgePublishService;
use App\Services\ScopedReference;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * PDF Knowledge Import Foundation (V1.3): a controlled, manual workflow turning an
 * official machine document into structured maintenance_knowledge_entries, then
 * publishing approved entries into machine_error_codes / maintenance_error_solutions
 * / maintenance_document_references (see MaintenanceKnowledgePublishService). No
 * OCR/AI extraction here - entries are entered by hand; that pipeline is a future
 * phase built on top of this foundation.
 *
 * Import sessions and their entries are catalog-shaped exactly like documents/error
 * codes - scope is inherited from the parent document's account_id, authorized via
 * AccountAccessResolver::canManageCatalogScope() (machines.manage for owned scope,
 * Superuser for global), never a new capability.
 */
class MaintenanceKnowledgeImportController extends Controller
{
    private const ENTRY_TRANSITIONS = [
        'DRAFT' => ['APPROVED', 'REJECTED'],
        'APPROVED' => ['REJECTED'],
        'REJECTED' => [],
    ];

    private function authorizeCatalogScope(Request $r, ?string $accountId): void
    {
        abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $accountId), 403);
    }

    private function membershipAccountIds(Request $r)
    {
        return $r->user()->memberships()->where('status', 'active')->pluck('account_id');
    }

    public function index(Request $r)
    {
        $ids = $this->membershipAccountIds($r);
        $q = MaintenanceDocumentImport::whereHas('document', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('account_id')->orWhereIn('account_id', $ids)))
            ->with(['document', 'machineModel', 'extraction']);
        if ($r->filled('document_id')) {
            $q->where('document_id', $r->string('document_id'));
        }
        if ($r->filled('status')) {
            $q->where('status', $r->string('status'));
        }

        return response()->json(['data' => $q->orderByDesc('created_at')->get()]);
    }

    public function show(Request $r, string $id)
    {
        $import = MaintenanceDocumentImport::with(['document', 'machineModel', 'extraction', 'entries' => fn ($q) => $q->orderBy('created_at')])->findOrFail($id);
        $ids = $this->membershipAccountIds($r);
        abort_unless($import->document->account_id === null || $ids->contains($import->document->account_id), 404);

        return response()->json(['data' => $import]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'document_id' => 'required|uuid',
            'machine_model_id' => 'nullable|uuid',
            'import_type' => 'nullable|in:MANUAL_ENTRY,BULK_IMPORT',
        ]);
        $doc = MaintenanceDocument::findOrFail($d['document_id']);
        $this->authorizeCatalogScope($r, $doc->account_id);
        ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'] ?? null, $doc->account_id, 'machine_model_id');

        $import = MaintenanceDocumentImport::create([
            'document_id' => $doc->id,
            'machine_model_id' => $d['machine_model_id'] ?? null,
            'import_type' => $d['import_type'] ?? 'MANUAL_ENTRY',
            'status' => 'DRAFT',
            'created_by' => $r->user()->id,
        ]);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document_import.created', 'maintenance_document_import', $import->id, $doc->account_id, [], app(GovernanceAudit::class)->snapshot($import));

        return response()->json(['data' => $import], 201);
    }

    /**
     * V1.6 - Extracted Knowledge Processing. Starts (or, if a run already
     * finished/failed for this exact extraction, restarts) deterministic
     * candidate detection over the document's latest COMPLETED extraction.
     * Idempotent per (document, extraction, processing version) - see
     * MaintenanceKnowledgeProcessingService::startProcessing().
     */
    public function processKnowledge(Request $r, string $document)
    {
        $doc = MaintenanceDocument::findOrFail($document);
        $this->authorizeCatalogScope($r, $doc->account_id);
        $d = $r->validate(['machine_model_id' => 'nullable|uuid']);
        if (! empty($d['machine_model_id'])) {
            ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'], $doc->account_id, 'machine_model_id');
        }

        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($doc, $r->user(), $d['machine_model_id'] ?? null);

        return response()->json(['data' => $import], 201);
    }

    public function storeEntry(Request $r, string $importId)
    {
        $import = MaintenanceDocumentImport::with('document')->findOrFail($importId);
        $this->authorizeCatalogScope($r, $import->document->account_id);
        $d = $r->validate([
            'knowledge_type' => 'required|in:ERROR_CODE,JAM_CODE,WARNING,PM_SCHEDULE',
            'code' => 'nullable|string|max:64',
            'title' => 'required|string|max:200',
            'category' => 'nullable|string|max:80',
            'severity' => 'nullable|in:info,warning,critical',
            'description' => 'nullable|string',
            'operator_solution' => 'nullable|string',
            'technician_solution' => 'nullable|string',
            'page_reference' => 'nullable|string|max:40',
        ]);
        $entry = MaintenanceKnowledgeEntry::create($d + ['import_id' => $import->id, 'status' => 'DRAFT', 'created_by' => $r->user()->id]);

        if ($import->status === 'DRAFT') {
            $import->update(['status' => 'PROCESSING']);
        }

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_knowledge_entry.created', 'maintenance_knowledge_entry', $entry->id, $import->document->account_id, [], app(GovernanceAudit::class)->snapshot($entry));

        return response()->json(['data' => $entry], 201);
    }

    public function updateEntry(Request $r, string $entryId)
    {
        $entry = MaintenanceKnowledgeEntry::with('import.document')->findOrFail($entryId);
        $accountId = $entry->import->document->account_id;
        $this->authorizeCatalogScope($r, $accountId);
        $d = $r->validate([
            'knowledge_type' => 'sometimes|in:ERROR_CODE,JAM_CODE,WARNING,PM_SCHEDULE',
            'code' => 'nullable|string|max:64',
            'title' => 'sometimes|string|max:200',
            'category' => 'nullable|string|max:80',
            'severity' => 'nullable|in:info,warning,critical',
            'description' => 'nullable|string',
            'operator_solution' => 'nullable|string',
            'technician_solution' => 'nullable|string',
            'page_reference' => 'nullable|string|max:40',
            'status' => 'sometimes|in:DRAFT,APPROVED,REJECTED',
        ]);

        $contentFields = array_diff_key($d, ['status' => true]);
        if ($contentFields && $entry->status !== 'DRAFT') {
            throw new ConflictHttpException('only a draft knowledge entry can be edited');
        }
        if (array_key_exists('status', $d)) {
            $allowed = self::ENTRY_TRANSITIONS[$entry->status] ?? [];
            if (! in_array($d['status'], $allowed, true)) {
                throw new ConflictHttpException("cannot transition knowledge entry from {$entry->status} to {$d['status']}");
            }
            if ($d['status'] === 'APPROVED') {
                $d['approved_by'] = $r->user()->id;
            }
        }

        $before = app(GovernanceAudit::class)->snapshot($entry);
        $entry->update($d);

        // V1.6: an approve/reject transition gets its own named lifecycle event
        // (Section T) rather than the generic "updated" - a plain content edit
        // (still possible while DRAFT) keeps the original, pre-existing event name.
        $action = match ($d['status'] ?? null) {
            'APPROVED' => 'knowledge_candidate_reviewed',
            'REJECTED' => 'knowledge_candidate_rejected',
            default => 'maintenance_knowledge_entry.updated',
        };
        app(GovernanceAudit::class)->changed($r->user(), $action, 'maintenance_knowledge_entry', $entry->id, $accountId, $before, app(GovernanceAudit::class)->snapshot($entry), array_keys($entry->getChanges()));

        return response()->json(['data' => $entry]);
    }

    public function publishEntry(Request $r, string $entryId)
    {
        $entry = MaintenanceKnowledgeEntry::with('import.document')->findOrFail($entryId);
        $this->authorizeCatalogScope($r, $entry->import->document->account_id);

        $result = app(MaintenanceKnowledgePublishService::class)->publish($entry, $r->user());

        return response()->json(['data' => [
            'entry' => $result['entry']->fresh(),
            'error_code' => $result['errorCode'],
            'solution' => $result['solution'],
            'reference' => $result['reference'],
        ]]);
    }
}
