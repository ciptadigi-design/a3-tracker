<?php

namespace App\Services\KnowledgeReview;

use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeReviewSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * V1.11 - Review Workflow Benchmark instrumentation. Measures the EXISTING human
 * review->publish workflow; it never authors, approves, or publishes anything itself.
 *
 * Design constraints (see docs/M2 milestone brief for the full rationale):
 *  - one row per review session, coarse aggregation only - no per-interaction event rows.
 *  - every counter is a bounded, server-clamped delta; a client can never inflate active
 *    time or interaction counts past what a real review session could plausibly produce.
 *  - heartbeats are idempotent via a client-supplied monotonic `client_seq`: a duplicate/
 *    retried heartbeat (client_seq <= the last one applied) is accepted as a no-op.
 *  - reviewer identity and account/import scope are ALWAYS server-derived from the
 *    authenticated user and the resolved import - never taken from the request body.
 *  - failures here must never block the underlying review/publish workflow - every public
 *    method that is called from a non-telemetry code path (completeForPublish) swallows
 *    and logs its own errors rather than throwing.
 */
class ReviewSessionTracker
{
    public const STAGES = ['GROUP_OPENED', 'SOURCE_REVIEW_STARTED', 'AUTHORING_STARTED', 'AUTHORING_SAVED', 'READY_FOR_PUBLISH', 'PUBLISHED'];

    /** Per-heartbeat-call clamps. A real UI flushes at most every ~30-60s while active; these are generous, not tight, bounds against a misbehaving/malicious client. */
    private const MAX_ACTIVE_SECONDS_PER_CALL = 180;

    private const MAX_COUNTER_PER_CALL = 25;

    /**
     * Start (or resume) a session for this reviewer + group. Resuming means: the reviewer's
     * most recent INCOMPLETE session for this exact (import, code) is reused rather than
     * creating a duplicate every time the group detail dialog is reopened.
     */
    public function start(User $reviewer, MaintenanceDocumentImport $import, string $code): MaintenanceKnowledgeReviewSession
    {
        $existing = MaintenanceKnowledgeReviewSession::query()
            ->where('document_import_id', $import->id)
            ->where('code', $code)
            ->where('reviewer_user_id', $reviewer->id)
            ->whereNull('completed_at')
            ->orderByDesc('started_at')
            ->first();

        if ($existing !== null) {
            $existing->last_activity_at = now();
            $existing->save();

            return $existing;
        }

        return MaintenanceKnowledgeReviewSession::create([
            'account_id' => $import->document->account_id,
            'document_import_id' => $import->id,
            'code' => $code,
            'reviewer_user_id' => $reviewer->id,
            'started_at' => now(),
            'last_activity_at' => now(),
            'current_stage' => 'GROUP_OPENED',
        ]);
    }

    /**
     * @param  array{active_seconds?: int, source_page_views?: int, authoring_edits?: int, validation_failures?: int, stage?: string, client_seq?: int}  $delta
     */
    public function heartbeat(User $reviewer, string $sessionId, array $delta): MaintenanceKnowledgeReviewSession
    {
        return DB::transaction(function () use ($reviewer, $sessionId, $delta) {
            /** @var MaintenanceKnowledgeReviewSession $session */
            $session = MaintenanceKnowledgeReviewSession::query()->lockForUpdate()->findOrFail($sessionId);
            abort_unless($session->reviewer_user_id === $reviewer->id, 403);

            // A completed session no longer accumulates telemetry - publishing (or a prior
            // abandon) is the end of this session's story.
            if ($session->completed_at !== null) {
                return $session;
            }

            $seq = isset($delta['client_seq']) ? (int) $delta['client_seq'] : null;
            $isDuplicate = $seq !== null && $session->last_client_seq !== null && $seq <= $session->last_client_seq;

            if (! $isDuplicate) {
                $activeSeconds = max(0, min((int) ($delta['active_seconds'] ?? 0), self::MAX_ACTIVE_SECONDS_PER_CALL));
                $sourceViews = max(0, min((int) ($delta['source_page_views'] ?? 0), self::MAX_COUNTER_PER_CALL));
                $authoringEdits = max(0, min((int) ($delta['authoring_edits'] ?? 0), self::MAX_COUNTER_PER_CALL));
                $validationFailures = max(0, min((int) ($delta['validation_failures'] ?? 0), self::MAX_COUNTER_PER_CALL));

                $session->active_seconds += $activeSeconds;
                $session->source_page_views += $sourceViews;
                $session->authoring_edits += $authoringEdits;
                $session->validation_failures += $validationFailures;

                if ($sourceViews > 0 && $session->source_review_started_at === null) {
                    $session->source_review_started_at = now();
                }
                if ($seq !== null) {
                    $session->last_client_seq = $seq;
                }
            }

            $stage = $delta['stage'] ?? null;
            if ($stage !== null && in_array($stage, self::STAGES, true) && array_search($stage, self::STAGES, true) >= array_search($session->current_stage, self::STAGES, true)) {
                $session->current_stage = $stage;
                if ($stage === 'AUTHORING_STARTED' && $session->authoring_started_at === null) {
                    $session->authoring_started_at = now();
                } elseif ($stage === 'AUTHORING_SAVED' || $stage === 'READY_FOR_PUBLISH') {
                    $session->authoring_saved_at = now();
                }
            }

            $session->last_activity_at = now();
            $session->save();

            return $session;
        });
    }

    public function abandon(User $reviewer, string $sessionId): MaintenanceKnowledgeReviewSession
    {
        /** @var MaintenanceKnowledgeReviewSession $session */
        $session = MaintenanceKnowledgeReviewSession::query()->findOrFail($sessionId);
        abort_unless($session->reviewer_user_id === $reviewer->id, 403);
        if ($session->completed_at === null) {
            $session->completed_at = now();
            $session->outcome = 'ABANDONED';
            $session->save();
        }

        return $session;
    }

    /**
     * Called from KnowledgeGroupPublisher after a REAL governed publish succeeds. Best-effort
     * only: telemetry can never fail or slow down an actual publish, so every error is caught
     * and logged, never rethrown.
     */
    public function completeForPublish(User $actor, string $importId, string $code): void
    {
        try {
            MaintenanceKnowledgeReviewSession::query()
                ->where('document_import_id', $importId)
                ->where('code', $code)
                ->where('reviewer_user_id', $actor->id)
                ->whereNull('completed_at')
                ->orderByDesc('started_at')
                ->limit(1)
                ->update(['completed_at' => now(), 'outcome' => 'PUBLISHED', 'current_stage' => 'PUBLISHED', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('review_session.complete_for_publish_failed', ['import_id' => $importId, 'code' => $code, 'error' => $e->getMessage()]);
        }
    }
}
