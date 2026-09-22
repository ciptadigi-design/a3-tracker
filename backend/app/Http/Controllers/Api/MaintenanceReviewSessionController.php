<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeReviewSession;
use App\Services\AccountAccessResolver;
use App\Services\KnowledgeReview\ReviewBenchmarkSummary;
use App\Services\KnowledgeReview\ReviewSessionTracker;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * V1.11 - Review Workflow Benchmark. Purely operational analytics over the EXISTING human
 * review/publish workflow (see MaintenanceKnowledgeReviewController for the actual review and
 * publish endpoints). This controller cannot approve, reject, restore or publish anything -
 * it has no route to any of those actions, and ReviewSessionTracker never touches the
 * knowledge/catalog tables.
 */
class MaintenanceReviewSessionController extends Controller
{
    /** Same read-access rule as MaintenanceKnowledgeReviewController::readableImport() - any active member of the owning account, or global scope. */
    private function readableImport(Request $r, string $importId): MaintenanceDocumentImport
    {
        $import = MaintenanceDocumentImport::with('document')->findOrFail($importId);
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        abort_unless($import->document->account_id === null || $ids->contains($import->document->account_id), 404);

        return $import;
    }

    private function manageableImport(Request $r, string $importId): MaintenanceDocumentImport
    {
        $import = MaintenanceDocumentImport::with('document')->findOrFail($importId);
        abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $import->document->account_id), 403);

        return $import;
    }

    private function groupCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        abort_unless(preg_match('/^[A-Z0-9\-]{1,64}$/', $normalized) === 1, 404);

        return $normalized;
    }

    public function start(Request $r, string $importId, string $code)
    {
        $import = $this->readableImport($r, $importId);
        $normalized = $this->groupCode($code);

        $session = app(ReviewSessionTracker::class)->start($r->user(), $import, $normalized);

        return response()->json(['data' => $this->present($session)]);
    }

    public function heartbeat(Request $r, string $sessionId)
    {
        $d = $r->validate([
            'active_seconds' => ['nullable', 'integer', 'min:0'],
            'source_page_views' => ['nullable', 'integer', 'min:0'],
            'authoring_edits' => ['nullable', 'integer', 'min:0'],
            'validation_failures' => ['nullable', 'integer', 'min:0'],
            'stage' => ['nullable', 'string', Rule::in(ReviewSessionTracker::STAGES)],
            'client_seq' => ['nullable', 'integer', 'min:1'],
        ]);

        // Ownership is enforced inside the service (reviewer_user_id must match the
        // authenticated user) - a session id alone, guessed or otherwise, cannot be used to
        // write into someone else's session.
        $session = app(ReviewSessionTracker::class)->heartbeat($r->user(), $sessionId, $d);

        return response()->json(['data' => $this->present($session)]);
    }

    public function abandon(Request $r, string $sessionId)
    {
        $session = app(ReviewSessionTracker::class)->abandon($r->user(), $sessionId);

        return response()->json(['data' => $this->present($session)]);
    }

    public function benchmark(Request $r, string $importId)
    {
        $import = $this->manageableImport($r, $importId);
        $d = $r->validate(['cohort' => ['nullable', 'string', Rule::in(['all', 'self_contained_high', 'context_recommended_high'])]]);

        return response()->json(['data' => app(ReviewBenchmarkSummary::class)->summarize($import, $d['cohort'] ?? 'all')]);
    }

    private function present(MaintenanceKnowledgeReviewSession $s): array
    {
        return [
            'id' => $s->id,
            'document_import_id' => $s->document_import_id,
            'code' => $s->code,
            'current_stage' => $s->current_stage,
            'outcome' => $s->outcome,
            'active_seconds' => $s->active_seconds,
            'source_page_views' => $s->source_page_views,
            'authoring_edits' => $s->authoring_edits,
            'validation_failures' => $s->validation_failures,
            'completed_at' => $s->completed_at?->toIso8601String(),
        ];
    }
}
