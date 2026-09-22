<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceDocumentImport;
use App\Services\AccountAccessResolver;
use App\Services\KnowledgeReview\KnowledgeCandidateFilter;
use App\Services\KnowledgeReview\KnowledgeCodeGroupQuery;
use App\Services\KnowledgeReview\KnowledgeFilterBulkReview;
use App\Services\KnowledgeReview\KnowledgeGroupPublisher;
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
        $normalized = $this->groupCode($code);

        // V1.9: source_pages carries full extracted text of this group's own supporting pages,
        // scoped to this import's document - see KnowledgeCodeGroupQuery::detail().
        $group = app(KnowledgeCodeGroupQuery::class)->detail($import->id, $normalized, $import->document_id);
        abort_if($group === null, 404);
        // V1.8: publication facts (published?, suggested canonical, supporting pages, server-side collision) for the review UI.
        $group['publication'] = app(KnowledgeGroupPublisher::class)->summary($import, $normalized);

        return response()->json(['data' => $group]);
    }

    /**
     * V1.9 - one ADJACENT context page beyond a group's own source pages (e.g. the page just
     * before/after a procedure that continues across a page break). Read-only, no mutation.
     * Deliberately not a generic page reader: KnowledgeCodeGroupQuery::adjacentPage() refuses
     * (404, same as "does not exist") any page outside a small bound of the group's own
     * evidence, and every lookup is scoped to this import's own document - never another
     * account's or another document's pages.
     */
    public function codeGroupPage(Request $r, string $importId, string $code, string $pageNumber)
    {
        $import = $this->readableImport($r, $importId);
        $normalized = $this->groupCode($code);
        abort_unless(preg_match('/^[1-9][0-9]{0,6}$/', $pageNumber) === 1, 404);

        $page = app(KnowledgeCodeGroupQuery::class)->adjacentPage($import->id, $normalized, $import->document_id, (int) $pageNumber);
        abort_if($page === null, 404);

        return response()->json(['data' => $page]);
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

    /** V1.8 - authoritative, read-only preview of publishing ONE code group with a reviewer-chosen canonical occurrence. */
    public function publishPreview(Request $r, string $importId, string $code)
    {
        $import = $this->manageableImport($r, $importId);
        $normalized = $this->groupCode($code);

        return response()->json(['data' => app(KnowledgeGroupPublisher::class)->preview($r->user(), $import, $normalized, $this->validatedPublishInput($r))]);
    }

    /** V1.8 - the single-code publish. One group, one transaction; needs a valid preview token. No bulk variant exists. */
    public function publishGroup(Request $r, string $importId, string $code)
    {
        $import = $this->manageableImport($r, $importId);
        $normalized = $this->groupCode($code);
        $input = $this->validatedPublishInput($r, true);

        return response()->json(['data' => app(KnowledgeGroupPublisher::class)->publish(
            $r->user(), $import, $normalized, $input, (string) $input['confirmation_token'], (bool) ($input['confirm_update'] ?? false),
        )]);
    }

    private function groupCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        abort_unless(preg_match('/^[A-Z0-9\-]{1,64}$/', $normalized) === 1, 404);

        return $normalized;
    }

    /**
     * V1.8.1 - accepts either the legacy single `technician_solution` string or the new
     * `technician_solutions[]` array (each item optionally `applicability_label`d) - see
     * KnowledgeGroupPublisher::solutionProposals() for how the two are reconciled. Both are
     * validated here so a malformed array element never reaches the service layer.
     *
     * @return array<string, mixed>
     */
    private function validatedPublishInput(Request $r, bool $requireToken = false): array
    {
        $rules = [
            'canonical_candidate_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'operator_guidance' => ['nullable', 'string', 'max:10000'],
            'technician_solution' => ['nullable', 'string', 'max:10000'],
            'technician_solutions' => ['nullable', 'array', 'max:'.KnowledgeGroupPublisher::MAX_SOLUTIONS],
            'technician_solutions.*.applicability_label' => ['nullable', 'string', 'max:160'],
            'technician_solutions.*.instruction' => ['nullable', 'string', 'max:10000'],
        ];
        if ($requireToken) {
            $rules['confirmation_token'] = ['required', 'string', 'max:2000'];
            $rules['confirm_update'] = ['sometimes', 'boolean'];
        }

        return $r->validate($rules);
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
