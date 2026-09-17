<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceErrorSolution;
use App\Services\AccountAccessResolver;
use App\Services\GovernanceAudit;
use App\Services\ScopedReference;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

    // Document CRUD (list/show/create/update/delete) lives in MaintenanceDocumentController -
    // Documents grew into a first-class resource (metadata, lifecycle, machine-model
    // linkage, error-code references) rather than a minor appendage of this controller.
    // MaintenanceDocument itself is still referenced here for ScopedReference checks below.

    public function errorCodes(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        $q = MachineErrorCode::where('is_active', true)->where(fn ($q) => $q->whereNull('account_id')->orWhereIn('account_id', $ids))->with(['solutions', 'documentReferences.document']);
        if ($r->filled('machine_model_id')) {
            $q->where('machine_model_id', $r->string('machine_model_id'));
        }
        if ($r->filled('search')) {
            $term = '%'.$r->string('search')->trim().'%';
            $q->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('title', 'like', $term));
        }

        return response()->json(['data' => $q->orderBy('code')->get()]);
    }

    public function storeErrorCode(Request $r)
    {
        $d = $r->validate(['account_id' => 'nullable|uuid', 'machine_model_id' => 'nullable|uuid', 'code' => 'required|string|max:64', 'title' => 'required|string|max:200', 'category' => 'nullable|string|max:80', 'severity' => 'nullable|in:info,warning,critical', 'manufacturer_description' => 'nullable|string', 'operator_description' => 'nullable|string', 'official_solution' => 'nullable|string', 'solution_summary' => 'nullable|string', 'source_document_id' => 'nullable|uuid']);
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
        $d = $r->validate(['title' => 'required|string|max:200', 'category' => 'nullable|string|max:80', 'severity' => 'nullable|in:info,warning,critical', 'manufacturer_description' => 'nullable|string', 'operator_description' => 'nullable|string', 'official_solution' => 'nullable|string', 'solution_summary' => 'nullable|string', 'source_document_id' => 'nullable|uuid']);
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

    // Solution steps have no scope of their own - authorization always resolves
    // through the parent error code's own account_id, exactly like model_profile_slots
    // resolves through its parent model_profile rather than carrying its own account_id.
    public function storeSolution(Request $r, string $errorCodeId)
    {
        $code = MachineErrorCode::findOrFail($errorCodeId);
        $this->authorizeCatalogScope($r, $code->account_id);
        $d = $r->validate(['step_number' => 'required|integer|min:1', 'instruction' => 'required|string', 'requires_technician' => 'nullable|boolean']);
        if (MaintenanceErrorSolution::where('machine_error_code_id', $code->id)->where('step_number', $d['step_number'])->exists()) {
            throw new ConflictHttpException('[DUPLICATE_STEP_NUMBER] A solution step with this step number already exists for this error code.');
        }
        $solution = MaintenanceErrorSolution::create($d + ['machine_error_code_id' => $code->id, 'created_by' => $r->user()->id]);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_error_solution.created', 'maintenance_error_solution', $solution->id, $code->account_id, [], app(GovernanceAudit::class)->snapshot($solution));

        return response()->json(['data' => $solution], 201);
    }

    public function updateSolution(Request $r, string $errorCodeId, string $solutionId)
    {
        $code = MachineErrorCode::findOrFail($errorCodeId);
        $this->authorizeCatalogScope($r, $code->account_id);
        $solution = MaintenanceErrorSolution::where('machine_error_code_id', $code->id)->findOrFail($solutionId);
        $d = $r->validate(['step_number' => 'required|integer|min:1', 'instruction' => 'required|string', 'requires_technician' => 'nullable|boolean']);
        if ($d['step_number'] !== $solution->step_number && MaintenanceErrorSolution::where('machine_error_code_id', $code->id)->where('step_number', $d['step_number'])->where('id', '!=', $solution->id)->exists()) {
            throw new ConflictHttpException('[DUPLICATE_STEP_NUMBER] A solution step with this step number already exists for this error code.');
        }
        $before = app(GovernanceAudit::class)->snapshot($solution);
        $solution->update($d);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_error_solution.updated', 'maintenance_error_solution', $solution->id, $code->account_id, $before, app(GovernanceAudit::class)->snapshot($solution), array_keys($solution->getChanges()));

        return response()->json(['data' => $solution]);
    }

    public function deleteSolution(Request $r, string $errorCodeId, string $solutionId)
    {
        $code = MachineErrorCode::findOrFail($errorCodeId);
        $this->authorizeCatalogScope($r, $code->account_id);
        $solution = MaintenanceErrorSolution::where('machine_error_code_id', $code->id)->findOrFail($solutionId);
        $before = app(GovernanceAudit::class)->snapshot($solution);
        $solution->delete();

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_error_solution.deleted', 'maintenance_error_solution', $solutionId, $code->account_id, $before, []);

        return response()->noContent();
    }
}
