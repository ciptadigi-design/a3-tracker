<?php

namespace App\Services\KnowledgeReview;

use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeReviewSession;
use Illuminate\Support\Collection;

/**
 * V1.11 - compact benchmark aggregation over maintenance_knowledge_review_sessions for ONE
 * import. Medians, not averages (time metrics are skewed by the rare very-long session), and
 * an explicit "limited sample" flag rather than implying statistical certainty on a handful
 * of rows. No reviewer identity is ever included in the output - see the controller/UI note
 * that this is a workflow-friction tool, never a per-reviewer ranking.
 */
class ReviewBenchmarkSummary
{
    private const LIMITED_SAMPLE_THRESHOLD = 10;

    /**
     * @param  string  $cohort  'all' | 'self_contained_high' | 'context_recommended_high'
     * @return array<string, mixed>
     */
    public function summarize(MaintenanceDocumentImport $import, string $cohort = 'all'): array
    {
        // One query for every session in this import - the benchmark's only unbounded-ish
        // read, and it is bounded by session count, which is small by construction (this
        // milestone's sample is a handful of groups, never the whole catalog).
        $sessions = MaintenanceKnowledgeReviewSession::query()
            ->where('document_import_id', $import->id)
            ->orderBy('started_at')
            ->get();

        if ($cohort !== 'all') {
            $wantedHint = $cohort === 'context_recommended_high' ? 'CONTEXT_RECOMMENDED' : 'SELF_CONTAINED';
            $codes = $sessions->pluck('code')->unique();
            $query = app(KnowledgeCodeGroupQuery::class);
            // One detail() lookup per DISTINCT code in the sample - bounded by how many groups
            // were actually reviewed, never by catalog size. See KnowledgeCodeGroupQuery::detail().
            $classification = $codes->mapWithKeys(function ($code) use ($query, $import) {
                $detail = $query->detail($import->id, $code, $import->document_id);

                return [$code => $detail === null ? null : ['evidence' => $detail['best_evidence'] ?? null, 'context_hint' => $detail['context_hint'] ?? 'SELF_CONTAINED']];
            });
            $sessions = $sessions->filter(function ($s) use ($classification, $wantedHint) {
                $c = $classification[$s->code] ?? null;

                return $c !== null && $c['evidence'] === 'HIGH' && $c['context_hint'] === $wantedHint;
            })->values();
        }

        $started = $sessions->count();
        $published = $sessions->where('outcome', 'PUBLISHED')->count();
        $incomplete = $sessions->whereNull('completed_at')->count();
        $publishedSessions = $sessions->where('outcome', 'PUBLISHED')->values();

        $validationFailureRate = $started > 0 ? round($sessions->sum('validation_failures') / $started, 2) : null;

        return [
            'cohort' => $cohort,
            'sessions_started' => $started,
            'sessions_published' => $published,
            'incomplete_session_count' => $incomplete,
            'median_active_time_to_publish_seconds' => $this->median($publishedSessions->map(fn ($s) => $s->active_seconds)),
            'median_active_authoring_seconds' => $this->median($publishedSessions->map(fn ($s) => $this->authoringSeconds($s))->filter(fn ($v) => $v !== null)),
            'median_source_interactions' => $this->median($sessions->map(fn ($s) => $s->source_page_views)),
            'median_authoring_edits' => $this->median($sessions->map(fn ($s) => $s->authoring_edits)),
            'validation_failure_rate' => $validationFailureRate,
            'limited_sample' => $started < self::LIMITED_SAMPLE_THRESHOLD,
        ];
    }

    private function authoringSeconds(MaintenanceKnowledgeReviewSession $s): ?int
    {
        if ($s->authoring_started_at === null || $s->authoring_saved_at === null) {
            return null;
        }
        $seconds = $s->authoring_saved_at->diffInSeconds($s->authoring_started_at);

        // A wall-clock diff, not active_seconds, but bounded to the session's own recorded
        // active time so an idle gap between authoring start and save never inflates this figure.
        return max(0, min($seconds, $s->active_seconds));
    }

    /** @param  Collection<int, int|float>  $values */
    private function median(Collection $values): ?float
    {
        $sorted = $values->filter(fn ($v) => $v !== null)->sort()->values();
        $count = $sorted->count();
        if ($count === 0) {
            return null;
        }
        $mid = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $sorted[$mid] : (float) (($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }
}
