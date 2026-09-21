<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceDocumentImport;
use App\Services\AccountAccessResolver;
use App\Services\KnowledgeReview\KnowledgeCandidateFilter;
use App\Services\KnowledgeReview\KnowledgeCodeGroupQuery;
use App\Services\KnowledgeReview\KnowledgeFilterBulkReview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * V1.7.2 - Knowledge Review Consolidation. A consolidated, code-centred view
 * over the existing maintenance_knowledge_entries rows plus a filter-based
 * bulk REJECT/RESTORE triage flow. Read views follow listEntries()'s
 * visibility rule (a member of the owning account, or global scope); every
 * mutation-shaped call (including the preview, which reveals what a mutation
 * would touch) requires canManageCatalogScope exactly like the V1.7 bulk
 * endpoint. None of this publishes, approves, or touches the published
 * knowledge tables.
 */
class MaintenanceKnowledgeReviewController extends Controller
{
    private function membershipAccountIds(Request $r)
    {
        return $r->user()->memberships()->where('status', 'active')->pluck('account_id');
    }

    /** Read access: same rule as MaintenanceKnowledgeImportController::listEntries(). */
    private function readableImport(Request $r, string $importId): MaintenanceDocumentImport
    {
        $import = MaintenanceDocumentImport::with('document')->findOrFail($importId);
        $ids = $this->membershipAccountIds($r);
        abort_unless($import->document->account_id === null || $ids->contains($import->document->account_id), 404);

        return $import;
    }

    /** Mutation-shaped access: same gate as MaintenanceKnowledgeImportController::bulkReview(). */
    private function manageableImport(Request $r, string $importId): MaintenanceDocumentImport
    {
        $import = MaintenanceDocumentImport::with('document')->findOrFail($importId);
        abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $import->document->account_id), 403);

        return $import;
    }

    public function codeGroups(Request $r, string $importId)
    {
        $import = $this->readableImport($r, $importId);
        $d = $r->validate(KnowledgeCandidateFilter::readRules() + [
            'per_page' => ['nullable', 'integer', Rule::in(KnowledgeCodeGroupQuery::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['best_evidence', 'occurrences', 'code', 'page'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        if ($errors = KnowledgeCandidateFilter::rangeErrors($d)) {
            throw ValidationException::withMessages($errors);
        }
        $filters = KnowledgeCandidateFilter::canonicalize($d, ['code', 'evidence', 'collision_status', 'status', 'source_page_from', 'source_page_to', 'reference_like', 'best_evidence', 'review_state', 'min_occurrences', 'max_occurrences']);

        return response()->json(['data' => app(KnowledgeCodeGroupQuery::class)->groups(
            $import->id,
            $filters,
            (int) ($d['per_page'] ?? KnowledgeCodeGroupQuery::DEFAULT_PER_PAGE),
            (int) ($d['page'] ?? 1),
            $d['sort'] ?? 'best_evidence',
            $d['direction'] ?? 'desc',
        )]);
    }

    public function codeGroup(Request $r, string $importId, string $code)
    {
        $import = $this->readableImport($r, $importId);
        $normalized = strtoupper(trim($code));
        abort_unless(preg_match('/^[A-Z0-9\-]{1,64}$/', $normalized) === 1, 404);

        $group = app(KnowledgeCodeGroupQuery::class)->detail($import->id, $normalized);
        abort_if($group === null, 404);

        return response()->json(['data' => $group]);
    }

    public function bulkFilterPreview(Request $r, string $importId)
    {
        $import = $this->manageableImport($r, $importId);
        [$action, $filters] = $this->validatedBulkRequest($r);

        return response()->json(['data' => app(KnowledgeFilterBulkReview::class)->preview($r->user(), $import, $action, $filters)]);
    }

    public function bulkFilterApply(Request $r, string $importId)
    {
        $import = $this->manageableImport($r, $importId);
        [$action, $filters] = $this->validatedBulkRequest($r, true);

        return response()->json(['data' => app(KnowledgeFilterBulkReview::class)->apply(
            $r->user(), $import, $action, $filters, (string) $r->input('confirmation_token'), $import->document->account_id,
        )]);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function validatedBulkRequest(Request $r, bool $requireToken = false): array
    {
        $rules = KnowledgeCandidateFilter::bulkRules() + ['action' => ['required', 'string', Rule::in(array_keys(KnowledgeFilterBulkReview::ACTIONS))]];
        if ($requireToken) {
            $rules['confirmation_token'] = ['required', 'string', 'max:2000'];
        }
        $d = $r->validate($rules);

        // Unknown criteria are rejected rather than silently ignored: a typo must never widen or narrow a mutation.
        // validate() returns only rule-covered keys, so an unknown key must be looked for in the raw input.
        $unknown = array_diff(array_keys((array) $r->input('filters', [])), KnowledgeCandidateFilter::BULK_KEYS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['filters' => 'Unsupported bulk filter: '.implode(', ', array_map(fn ($k) => (string) $k, $unknown))]);
        }

        if ($errors = KnowledgeCandidateFilter::rangeErrors($d['filters'])) {
            throw ValidationException::withMessages(array_combine(array_map(fn ($k) => 'filters.'.$k, array_keys($errors)), $errors));
        }

        return [$d['action'], KnowledgeCandidateFilter::canonicalize($d['filters'], KnowledgeCandidateFilter::BULK_KEYS)];
    }
}
