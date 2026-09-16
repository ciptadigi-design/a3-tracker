<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\Manufacturer;
use App\Services\AccountAccessResolver;
use App\Services\GovernanceAudit;
use App\Services\ScopedReference;
use Illuminate\Http\Request;

/**
 * Documents and error codes are catalog-shaped (either platform-global,
 * account_id null, or tenant-owned) exactly like ComponentCatalog / MachineModel,
 * so they reuse AccountAccessResolver::canManageCatalogScope() rather than a new
 * capability - global rows are Superuser-only, owned rows require
 * canManageOperational() (machines.manage) in that account, matching
 * ComponentsController's catalog endpoints.
 */
class MaintenanceKnowledgeBaseController extends Controller
{
    private function authorizeCatalogScope(Request $r, ?string $accountId): void
    {
        abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $accountId), 403);
    }

    public function documents(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        $q = MaintenanceDocument::where('is_active', true)->where(fn ($q) => $q->whereNull('account_id')->orWhereIn('account_id', $ids));
        if ($r->filled('machine_model_id')) {
            $q->where('machine_model_id', $r->string('machine_model_id'));
        }

        return response()->json(['data' => $q->orderByDesc('created_at')->get()]);
    }

    public function storeDocument(Request $r)
    {
        $d = $r->validate(['account_id' => 'nullable|uuid', 'manufacturer_id' => 'nullable|uuid|exists:manufacturers,id', 'machine_model_id' => 'nullable|uuid', 'title' => 'required|string|max:200', 'document_type' => 'nullable|string|max:40', 'file_reference' => 'required|string|max:500', 'version' => 'nullable|string|max:40']);
        $this->authorizeCatalogScope($r, $d['account_id'] ?? null);
        ScopedReference::activeGlobalOrOwned(Manufacturer::class, $d['manufacturer_id'] ?? null, $d['account_id'] ?? null, 'manufacturer_id');
        ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'] ?? null, $d['account_id'] ?? null, 'machine_model_id');
        $d['uploaded_by'] = $r->user()->id;
        $doc = MaintenanceDocument::create($d);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document.created', 'maintenance_document', $doc->id, $d['account_id'] ?? null, [], app(GovernanceAudit::class)->snapshot($doc));

        return response()->json(['data' => $doc], 201);
    }

    public function setDocumentStatus(Request $r, string $id)
    {
        $doc = MaintenanceDocument::findOrFail($id);
        $this->authorizeCatalogScope($r, $doc->account_id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        $before = app(GovernanceAudit::class)->snapshot($doc);
        $doc->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_document.status_changed', 'maintenance_document', $doc->id, $doc->account_id, $before, app(GovernanceAudit::class)->snapshot($doc));

        return response()->json(['data' => $doc]);
    }

    public function errorCodes(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        $q = MachineErrorCode::where('is_active', true)->where(fn ($q) => $q->whereNull('account_id')->orWhereIn('account_id', $ids));
        if ($r->filled('machine_model_id')) {
            $q->where('machine_model_id', $r->string('machine_model_id'));
        }

        return response()->json(['data' => $q->orderBy('code')->get()]);
    }

    public function storeErrorCode(Request $r)
    {
        $d = $r->validate(['account_id' => 'nullable|uuid', 'machine_model_id' => 'nullable|uuid', 'code' => 'required|string|max:64', 'title' => 'required|string|max:200', 'category' => 'nullable|string|max:80', 'severity' => 'nullable|in:info,warning,critical', 'manufacturer_description' => 'nullable|string', 'operator_description' => 'nullable|string', 'official_solution' => 'nullable|string', 'source_document_id' => 'nullable|uuid']);
        $this->authorizeCatalogScope($r, $d['account_id'] ?? null);
        ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'] ?? null, $d['account_id'] ?? null, 'machine_model_id');
        if (! empty($d['source_document_id'])) {
            ScopedReference::activeGlobalOrOwned(MaintenanceDocument::class, $d['source_document_id'], $d['account_id'] ?? null, 'source_document_id');
        }
        $code = MachineErrorCode::create($d);

        app(GovernanceAudit::class)->changed($r->user(), 'machine_error_code.created', 'machine_error_code', $code->id, $d['account_id'] ?? null, [], app(GovernanceAudit::class)->snapshot($code));

        return response()->json(['data' => $code], 201);
    }

    public function updateErrorCode(Request $r, string $id)
    {
        $code = MachineErrorCode::findOrFail($id);
        $this->authorizeCatalogScope($r, $code->account_id);
        $d = $r->validate(['title' => 'required|string|max:200', 'category' => 'nullable|string|max:80', 'severity' => 'nullable|in:info,warning,critical', 'manufacturer_description' => 'nullable|string', 'operator_description' => 'nullable|string', 'official_solution' => 'nullable|string', 'source_document_id' => 'nullable|uuid']);
        if (! empty($d['source_document_id'])) {
            ScopedReference::activeGlobalOrOwned(MaintenanceDocument::class, $d['source_document_id'], $code->account_id, 'source_document_id');
        }
        $before = app(GovernanceAudit::class)->snapshot($code);
        $code->update($d);

        app(GovernanceAudit::class)->changed($r->user(), 'machine_error_code.updated', 'machine_error_code', $code->id, $code->account_id, $before, app(GovernanceAudit::class)->snapshot($code), array_keys($code->getChanges()));

        return response()->json(['data' => $code]);
    }

    public function setErrorCodeStatus(Request $r, string $id)
    {
        $code = MachineErrorCode::findOrFail($id);
        $this->authorizeCatalogScope($r, $code->account_id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        $before = app(GovernanceAudit::class)->snapshot($code);
        $code->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        app(GovernanceAudit::class)->changed($r->user(), 'machine_error_code.status_changed', 'machine_error_code', $code->id, $code->account_id, $before, app(GovernanceAudit::class)->snapshot($code));

        return response()->json(['data' => $code]);
    }
}
