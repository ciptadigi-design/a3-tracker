<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentReference;
use App\Models\Manufacturer;
use App\Services\AccountAccessResolver;
use App\Services\DocumentStorageService;
use App\Services\GovernanceAudit;
use App\Services\ScopedReference;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Machine document repository - manufacturer/tenant reference documents (service
 * manuals, user manuals, part catalogs) linkable to a machine model and to
 * machine_error_codes via maintenance_document_references.
 *
 * Foundation only: metadata + an external file_path/file_name reference, no upload
 * handler and no storage subsystem. Catalog-shaped exactly like MachineErrorCode/
 * MaintenanceDocument already were in V1 - global (account_id null, Superuser-only)
 * or tenant-owned (canManageOperational / machines.manage), reusing
 * AccountAccessResolver::canManageCatalogScope() rather than a new capability.
 */
class MaintenanceDocumentController extends Controller
{
    private function authorizeCatalogScope(Request $r, ?string $accountId): void
    {
        abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $accountId), 403);
    }

    // status is the authoritative lifecycle field; is_active/archived_at are kept in
    // sync purely so the pre-existing ScopedReference::activeGlobalOrOwned() check
    // (used when a document is selected as an error code's source_document_id)
    // continues to behave correctly without being rewritten.
    private function syncLegacyActiveFlags(array $d): array
    {
        if (! array_key_exists('status', $d)) {
            return $d;
        }
        $d['is_active'] = $d['status'] !== 'ARCHIVED';
        $d['archived_at'] = $d['status'] === 'ARCHIVED' ? now() : null;

        return $d;
    }

    public function index(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        $q = MaintenanceDocument::where(fn ($q) => $q->whereNull('account_id')->orWhereIn('account_id', $ids))
            ->where('status', $r->filled('status') ? $r->string('status') : 'PUBLISHED');
        if ($r->filled('machine_model_id')) {
            $q->where('machine_model_id', $r->string('machine_model_id'));
        }
        if ($r->filled('manufacturer_id')) {
            $q->where('manufacturer_id', $r->string('manufacturer_id'));
        }
        if ($r->filled('document_type')) {
            $q->where('document_type', $r->string('document_type'));
        }
        if ($r->filled('search')) {
            $q->where('title', 'like', '%'.$r->string('search')->trim().'%');
        }

        return response()->json(['data' => $q->orderByDesc('created_at')->get()]);
    }

    public function show(Request $r, string $id)
    {
        $doc = MaintenanceDocument::with(['references.errorCode', 'manufacturer', 'machineModel'])->findOrFail($id);
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        abort_unless($doc->account_id === null || $ids->contains($doc->account_id), 404);

        return response()->json(['data' => $doc]);
    }

    private function validated(Request $r, bool $requireTitle = true): array
    {
        return $r->validate([
            'account_id' => 'nullable|uuid',
            'manufacturer_id' => 'nullable|uuid|exists:manufacturers,id',
            'machine_model_id' => 'nullable|uuid',
            'title' => ($requireTitle ? 'required' : 'sometimes').'|string|max:200',
            'description' => 'nullable|string',
            'document_type' => 'nullable|string|max:40',
            'version' => 'nullable|string|max:40',
            'file_path' => 'nullable|string|max:500',
            'file_name' => 'nullable|string|max:255',
            'file_size' => 'nullable|integer|min:0|max:'.DocumentStorageService::MAX_FILE_SIZE_BYTES,
            'mime_type' => 'nullable|in:application/pdf',
            'status' => 'nullable|in:DRAFT,PUBLISHED,ARCHIVED',
        ]);
    }

    public function store(Request $r)
    {
        $d = $this->validated($r);
        $this->authorizeCatalogScope($r, $d['account_id'] ?? null);
        ScopedReference::activeGlobalOrOwned(Manufacturer::class, $d['manufacturer_id'] ?? null, $d['account_id'] ?? null, 'manufacturer_id');
        ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'] ?? null, $d['account_id'] ?? null, 'machine_model_id');
        $d['status'] = $d['status'] ?? 'DRAFT';
        $d = $this->syncLegacyActiveFlags($d);
        $d['uploaded_by'] = $r->user()->id;
        // file_reference is V1's legacy required column, superseded by file_path/
        // file_name here but still NOT NULL at the DB level - see the V1.2 migration note.
        $d['file_reference'] = $d['file_path'] ?? '';
        // Explicit, not left to the DB default - a column absent from create()'s array
        // never appears on the in-memory model afterwards even though the DB applies a
        // default, so the immediate JSON response would silently omit these keys.
        $d['storage_disk'] = null;
        $d['uploaded_at'] = null;
        $doc = MaintenanceDocument::create($d);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document.created', 'maintenance_document', $doc->id, $d['account_id'] ?? null, [], app(GovernanceAudit::class)->snapshot($doc));

        return response()->json(['data' => $doc], 201);
    }

    public function update(Request $r, string $id)
    {
        $doc = MaintenanceDocument::findOrFail($id);
        $this->authorizeCatalogScope($r, $doc->account_id);
        $d = $this->validated($r, requireTitle: false);
        // A document with a real stored upload must go through upload() (replace) or
        // deleteFile() to change its file - never this generic metadata endpoint, which
        // would otherwise silently orphan the physical file on disk (the row would stop
        // pointing at it, but nothing would ever delete it).
        if (app(DocumentStorageService::class)->hasStoredFile($doc) && (array_key_exists('file_path', $d) || array_key_exists('file_name', $d))) {
            throw new ConflictHttpException('[FILE_MANAGED_ELSEWHERE] This document has an uploaded file. Use the upload or delete-file endpoint to change it.');
        }
        if (array_key_exists('manufacturer_id', $d)) {
            ScopedReference::activeGlobalOrOwned(Manufacturer::class, $d['manufacturer_id'], $doc->account_id, 'manufacturer_id');
        }
        if (array_key_exists('machine_model_id', $d)) {
            ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'], $doc->account_id, 'machine_model_id');
        }
        $d = $this->syncLegacyActiveFlags($d);
        $before = app(GovernanceAudit::class)->snapshot($doc);
        $doc->update($d);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document.updated', 'maintenance_document', $doc->id, $doc->account_id, $before, app(GovernanceAudit::class)->snapshot($doc), array_keys($doc->getChanges()));

        return response()->json(['data' => $doc]);
    }

    // Every FK that currently uses restrictOnDelete against maintenance_documents.id must be
    // checked here before $doc->delete() - otherwise the delete falls through to a raw DB FK
    // QueryException instead of a clean, domain-safe 409. (cascadeOnDelete children -
    // maintenance_document_extractions/maintenance_document_pages - never block deletion and
    // are intentionally not checked here; machine_error_codes.source_document_id is
    // nullOnDelete, also never blocking.) A document that was ever run through legacy
    // "Process Knowledge" now has a maintenance_document_imports row and, before this fix,
    // could not be deleted at all - see docs/M2 Maintenance Clean Slate audit.
    private function blockingDependencyReason(MaintenanceDocument $doc): ?string
    {
        if (MaintenanceDocumentReference::where('document_id', $doc->id)->exists()) {
            return '[DOCUMENT_REFERENCED] This document is linked to error codes or knowledge references and cannot be deleted. Archive it instead.';
        }
        if (MachineErrorCode::where('source_document_id', $doc->id)->exists()) {
            return '[DOCUMENT_REFERENCED] This document is linked to error codes or knowledge references and cannot be deleted. Archive it instead.';
        }
        if (MaintenanceDocumentImport::where('document_id', $doc->id)->exists()) {
            return '[DOCUMENT_HAS_LEGACY_IMPORTS] This document has legacy knowledge-processing history and cannot be deleted. Archive it instead.';
        }

        return null;
    }

    public function destroy(Request $r, string $id)
    {
        $doc = MaintenanceDocument::findOrFail($id);
        $this->authorizeCatalogScope($r, $doc->account_id);
        if (($reason = $this->blockingDependencyReason($doc)) !== null) {
            throw new ConflictHttpException($reason);
        }
        $before = app(GovernanceAudit::class)->snapshot($doc);
        app(DocumentStorageService::class)->deleteFile($doc);
        $doc->delete();

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document.deleted', 'maintenance_document', $id, $doc->account_id, $before, []);

        return response()->noContent();
    }

    // References have no scope of their own - authorization resolves through the
    // parent document's own account_id, exactly like machine_error_codes' solution steps.
    public function storeReference(Request $r, string $documentId)
    {
        $doc = MaintenanceDocument::findOrFail($documentId);
        $this->authorizeCatalogScope($r, $doc->account_id);
        $d = $r->validate([
            'machine_error_code_id' => 'nullable|uuid',
            'reference_type' => 'nullable|string|max:40',
            'page_number' => 'nullable|integer|min:1',
            'section_title' => 'nullable|string|max:200',
            'notes' => 'nullable|string',
        ]);
        if (! empty($d['machine_error_code_id'])) {
            $code = MachineErrorCode::findOrFail($d['machine_error_code_id']);
            // A global (account_id null) document may only reference global error codes -
            // otherwise a tenant-private code would leak into every other tenant's view of
            // that shared document. A tenant-owned document may reference its own codes or
            // any global code.
            if ($code->account_id !== null && $code->account_id !== $doc->account_id) {
                throw ValidationException::withMessages(['machine_error_code_id' => 'Error code is not available in this document\'s scope.']);
            }
        }
        $d['reference_type'] = $d['reference_type'] ?? 'error_code';
        $reference = MaintenanceDocumentReference::create($d + ['document_id' => $doc->id, 'created_by' => $r->user()->id]);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document_reference.created', 'maintenance_document_reference', $reference->id, $doc->account_id, [], app(GovernanceAudit::class)->snapshot($reference));

        return response()->json(['data' => $reference], 201);
    }

    public function deleteReference(Request $r, string $documentId, string $referenceId)
    {
        $doc = MaintenanceDocument::findOrFail($documentId);
        $this->authorizeCatalogScope($r, $doc->account_id);
        $reference = MaintenanceDocumentReference::where('document_id', $doc->id)->findOrFail($referenceId);
        $before = app(GovernanceAudit::class)->snapshot($reference);
        $reference->delete();

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document_reference.deleted', 'maintenance_document_reference', $referenceId, $doc->account_id, $before, []);

        return response()->noContent();
    }

    // Upload/replace/delete-file/download are mutation-gated (upload/delete-file via
    // canManageCatalogScope, same as every other document mutation) or read-gated
    // (download - any active tenant member, matching show()'s visibility), never a
    // new capability.
    public function upload(Request $r, string $id)
    {
        $doc = MaintenanceDocument::findOrFail($id);
        $this->authorizeCatalogScope($r, $doc->account_id);
        $r->validate(['file' => 'required|file|mimes:pdf|max:'.(DocumentStorageService::MAX_FILE_SIZE_BYTES / 1024)]);

        $storage = app(DocumentStorageService::class);
        $isReplacement = $storage->hasStoredFile($doc);
        $audit = app(GovernanceAudit::class);
        $before = $audit->snapshot($doc);
        $metadata = $storage->store($doc, $r->file('file'));
        $doc->update($metadata + ['file_reference' => $metadata['file_path']]);

        // record(), not changed() - an upload/replace is a mutation regardless of
        // whether the tracked metadata happens to be identical (e.g. re-uploading a
        // same-named file within the same second-precision uploaded_at tick); the new
        // file content itself is the audit-worthy event, not a metadata delta.
        $audit->record($r->user(), $isReplacement ? 'maintenance_document.replaced' : 'maintenance_document.uploaded', 'maintenance_document', $doc->id, $doc->account_id, ['changes' => $audit->changes($before, $audit->snapshot($doc))]);

        return response()->json(['data' => $doc->fresh()], 201);
    }

    public function download(Request $r, string $id)
    {
        $doc = MaintenanceDocument::findOrFail($id);
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        abort_unless($doc->account_id === null || $ids->contains($doc->account_id), 404);

        return app(DocumentStorageService::class)->download($doc, $r->boolean('inline'));
    }

    public function deleteFile(Request $r, string $id)
    {
        $doc = MaintenanceDocument::findOrFail($id);
        $this->authorizeCatalogScope($r, $doc->account_id);
        $storage = app(DocumentStorageService::class);
        abort_unless($storage->hasStoredFile($doc), 404);

        $before = app(GovernanceAudit::class)->snapshot($doc);
        $storage->deleteFile($doc);
        $doc->update($storage->clearedMetadata() + ['file_reference' => '']);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document.file_deleted', 'maintenance_document', $doc->id, $doc->account_id, $before, app(GovernanceAudit::class)->snapshot($doc), array_keys($doc->getChanges()));

        return response()->json(['data' => $doc->fresh()]);
    }
}
