<?php

namespace App\Services\KnowledgeReview;

use Illuminate\Support\Facades\DB;

/**
 * V1.7.2 - Knowledge review consolidation READ MODEL.
 *
 * A "code group" is a projection over the existing
 * maintenance_knowledge_entries rows, grouped by normalized_code within one
 * import. Nothing is stored for a group: every number below is aggregated
 * from the candidate rows on each request, so a group can never drift from
 * the rows it summarises, and every underlying candidate stays individually
 * traceable (see detail()).
 *
 * GROUP REVIEW STATE - derived deterministically from the rows' own status,
 * first matching rule wins. "Published" is status=APPROVED AND published_at IS
 * NOT NULL (there is no separate PUBLISHED status value in this domain):
 *
 *   PUBLISHED           >= 1 occurrence has been published
 *   APPROVED            no published occurrence, >= 1 occurrence APPROVED
 *   UNREVIEWED          every occurrence is DRAFT
 *   REJECTED            every occurrence is REJECTED
 *   PARTIALLY_REVIEWED  anything else (a mix of DRAFT and REJECTED)
 *
 * BEST EVIDENCE - the strongest evidence among the group's rows
 * (HIGH > MEDIUM > LOW). It is detector evidence, not ground truth.
 *
 * All aggregation is one grouped SQL query per page (plus two small lookups
 * scoped to that page's codes) - never PHP-side grouping of the whole import,
 * never a query per group. Only portable SQL is used (CASE/SUM/COUNT), so it
 * behaves identically on MySQL 8 (CI + Production) and SQLite (local tests).
 */
final class KnowledgeCodeGroupQuery
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    public const DEFAULT_PER_PAGE = 25;

    /** Distinct pages listed per group in a list row; the group detail has the complete list. */
    private const PAGES_PREVIEW = 12;

    /** Safety cap on occurrences returned by one group detail. */
    private const DETAIL_LIMIT = 200;

    private const BEST_RANK_SQL = 'MAX('.KnowledgeCandidateFilter::RANK_SQL.')';

    private const STATE_SQL = "CASE
        WHEN SUM(CASE WHEN published_at IS NOT NULL THEN 1 ELSE 0 END) > 0 THEN 'PUBLISHED'
        WHEN SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) > 0 THEN 'APPROVED'
        WHEN SUM(CASE WHEN status = 'DRAFT' THEN 1 ELSE 0 END) = COUNT(*) THEN 'UNREVIEWED'
        WHEN SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) = COUNT(*) THEN 'REJECTED'
        ELSE 'PARTIALLY_REVIEWED' END";

    private const SELECT_SQL = "normalized_code,
        COUNT(*) AS occurrence_count,
        SUM(CASE WHEN evidence = 'HIGH' THEN 1 ELSE 0 END) AS high_count,
        SUM(CASE WHEN evidence = 'MEDIUM' THEN 1 ELSE 0 END) AS medium_count,
        SUM(CASE WHEN evidence = 'LOW' THEN 1 ELSE 0 END) AS low_count,
        SUM(CASE WHEN status = 'DRAFT' THEN 1 ELSE 0 END) AS draft_count,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN status = 'APPROVED' AND published_at IS NULL THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN published_at IS NOT NULL THEN 1 ELSE 0 END) AS published_count,
        SUM(CASE WHEN collision_status = 'NEW' THEN 1 ELSE 0 END) AS collision_new,
        SUM(CASE WHEN collision_status = 'EXISTING' THEN 1 ELSE 0 END) AS collision_existing,
        SUM(CASE WHEN collision_status = 'POTENTIAL_UPDATE' THEN 1 ELSE 0 END) AS collision_potential_update,
        MIN(source_page_start) AS source_page_min,
        MAX(COALESCE(source_page_end, source_page_start)) AS source_page_max,
        COUNT(DISTINCT source_page_start) AS distinct_page_count,
        SUM(CASE WHEN source_page_end > source_page_start THEN 1 ELSE 0 END) AS multi_page_count,
        SUM(CASE WHEN description LIKE '%.....%' THEN 1 ELSE 0 END) AS reference_like_count,
        ".self::BEST_RANK_SQL.' AS best_rank,
        '.self::STATE_SQL.' AS review_state';

    private const SORTS = [
        'best_evidence' => 'best_rank',
        'occurrences' => 'occurrence_count',
        'code' => 'normalized_code',
        'page' => 'source_page_min',
    ];

    /**
     * @param  array<string, mixed>  $filters  validated criteria (see KnowledgeCandidateFilter)
     * @return array<string, mixed> a Laravel paginator array plus an import-level `summary`
     */
    public function groups(string $importId, array $filters, int $perPage, int $page, string $sort = 'best_evidence', string $direction = 'desc'): array
    {
        $q = $this->groupedQuery($importId, $filters);

        $sortKey = self::SORTS[$sort] ?? self::SORTS['best_evidence'];
        $dir = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        // Deterministic tie-breaks so pagination never reorders between pages.
        $q->orderBy($sortKey, $dir);
        if ($sortKey !== 'source_page_min') {
            $q->orderBy('source_page_min');
        }
        $q->orderBy('normalized_code');

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);
        $rows = collect($paginator->items());
        $codes = $rows->pluck('normalized_code')->all();

        $provenance = $this->pageProvenance($importId, $codes);
        $matching = $this->matchingCounts($importId, $filters, $codes);

        $groups = $rows->map(fn ($row) => $this->shape($row, $provenance[$row->normalized_code] ?? null, $matching[$row->normalized_code] ?? null, $filters))->values()->all();

        $out = $paginator->toArray();
        $out['data'] = $groups;
        $out['summary'] = $this->summary($importId);

        return $out;
    }

    /**
     * One group's aggregates plus EVERY occurrence, individually. Evidence
     * order: HIGH, then MEDIUM, then LOW, then source page ascending; contradictory
     * evidence inside a group is shown, never collapsed.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $importId, string $normalizedCode): ?array
    {
        $row = KnowledgeCandidateFilter::candidates($importId)
            ->where('normalized_code', $normalizedCode)
            ->groupBy('normalized_code')
            ->selectRaw(self::SELECT_SQL)
            ->first();
        if (! $row) {
            return null;
        }

        $occurrences = KnowledgeCandidateFilter::candidates($importId)
            ->where('normalized_code', $normalizedCode)
            ->selectRaw('id, normalized_code, knowledge_type, code, title, description, evidence, collision_status, status, published_at, page_reference, source_page_start, source_page_end, extraction_id, created_at, CASE WHEN description LIKE \'%.....%\' THEN 1 ELSE 0 END AS reference_like')
            ->orderByRaw(KnowledgeCandidateFilter::RANK_SQL.' DESC')
            ->orderBy('source_page_start')
            ->orderBy('source_page_end')
            ->orderBy('id')
            ->limit(self::DETAIL_LIMIT)
            ->get();

        $group = $this->shape($row, $this->provenanceFromRows($occurrences), null, []);
        $group['occurrences'] = $occurrences->map(fn ($o) => [
            'id' => $o->id,
            'knowledge_type' => $o->knowledge_type,
            'code' => $o->code,
            'normalized_code' => $o->normalized_code,
            'title' => $o->title,
            // The stored bounded excerpt, exactly as the candidate row holds it - the same
            // text a reviewer already sees and edits in the candidate list. No page text.
            'description' => $o->description,
            'evidence' => $o->evidence,
            'collision_status' => $o->collision_status,
            'status' => $o->status,
            'published_at' => $o->published_at,
            'page_reference' => $o->page_reference,
            'source_page_start' => $o->source_page_start === null ? null : (int) $o->source_page_start,
            'source_page_end' => $o->source_page_end === null ? null : (int) $o->source_page_end,
            'extraction_id' => $o->extraction_id,
            'created_at' => $o->created_at,
            'reference_like' => (bool) $o->reference_like,
            // The detector's generic "Error Code C-XXXX" fallback title - never publishable as final knowledge.
            'title_is_placeholder' => PlaceholderTitle::isPlaceholder($o->title, (string) $o->normalized_code),
        ])->values()->all();
        $group['occurrences_truncated'] = (int) $row->occurrence_count > self::DETAIL_LIMIT;

        return $group;
    }

    /** Import-level, filter-independent overview: every figure is an aggregate of the same rows. */
    public function summary(string $importId): array
    {
        $totals = KnowledgeCandidateFilter::candidates($importId)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN normalized_code IS NULL THEN 1 ELSE 0 END) AS ungrouped')
            ->first();

        $perCode = KnowledgeCandidateFilter::candidates($importId)
            ->whereNotNull('normalized_code')
            ->groupBy('normalized_code')
            ->selectRaw('normalized_code, '.self::BEST_RANK_SQL.' AS best_rank, '.self::STATE_SQL.' AS review_state');

        $cells = DB::query()->fromSub($perCode, 'g')
            ->selectRaw('best_rank, review_state, COUNT(*) AS codes')
            ->groupBy('best_rank', 'review_state')
            ->get();

        $best = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
        $states = array_fill_keys(KnowledgeCandidateFilter::REVIEW_STATES, 0);
        $distinct = 0;
        foreach ($cells as $cell) {
            $n = (int) $cell->codes;
            $distinct += $n;
            $label = match ((int) $cell->best_rank) {
                3 => 'HIGH', 2 => 'MEDIUM', default => 'LOW',
            };
            $best[$label] += $n;
            $states[$cell->review_state] = ($states[$cell->review_state] ?? 0) + $n;
        }

        return [
            'total_candidates' => (int) ($totals->total ?? 0),
            'ungrouped_candidates' => (int) ($totals->ungrouped ?? 0),
            'distinct_codes' => $distinct,
            'best_evidence_codes' => $best,
            'review_state_codes' => $states,
            'codes_remaining' => $states['UNREVIEWED'] + $states['PARTIALLY_REVIEWED'],
            'codes_reviewed' => $states['REJECTED'] + $states['APPROVED'] + $states['PUBLISHED'],
        ];
    }

    private function groupedQuery(string $importId, array $filters)
    {
        $q = KnowledgeCandidateFilter::candidates($importId)->whereNotNull('normalized_code');

        // Same-row semantics: a group is listed when at least one of its rows satisfies ALL
        // row-level criteria together. The group's own aggregates still cover ALL its rows,
        // so the reviewer always sees the whole picture for a code, never a filtered fragment.
        $rowFilters = array_intersect_key($filters, array_flip(['code', 'evidence', 'collision_status', 'status', 'source_page_from', 'source_page_to', 'reference_like']));
        if ($rowFilters !== []) {
            $q->whereIn('normalized_code', function ($sub) use ($importId, $rowFilters) {
                $sub->from('maintenance_knowledge_entries')->select('normalized_code')->where('import_id', $importId)->whereNotNull('normalized_code');
                KnowledgeCandidateFilter::applyRowCriteria($sub, $rowFilters);
            });
        }

        $q->groupBy('normalized_code')->selectRaw(self::SELECT_SQL);

        $best = $filters['best_evidence'] ?? null;
        if ($best !== null) {
            [$op, $rank] = match ($best) {
                'HIGH' => ['=', 3], 'MEDIUM' => ['=', 2], 'LOW' => ['=', 1], default => ['>=', 2],
            };
            $q->havingRaw(self::BEST_RANK_SQL.' '.$op.' ?', [$rank]);
        }
        if (isset($filters['review_state'])) {
            $q->havingRaw('('.self::STATE_SQL.') = ?', [$filters['review_state']]);
        }
        if (isset($filters['min_occurrences'])) {
            $q->havingRaw('COUNT(*) >= ?', [$filters['min_occurrences']]);
        }
        if (isset($filters['max_occurrences'])) {
            $q->havingRaw('COUNT(*) <= ?', [$filters['max_occurrences']]);
        }

        return $q;
    }

    /**
     * Lightweight per-occurrence facts (no text) for only the page's codes, in ONE query,
     * used for the bounded page list and the representative candidate.
     *
     * @param  list<string>  $codes
     * @return array<string, array{pages: list<int>, truncated: bool, representative: string}>
     */
    private function pageProvenance(string $importId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $rows = KnowledgeCandidateFilter::candidates($importId)
            ->whereIn('normalized_code', $codes)
            ->selectRaw('id, normalized_code, source_page_start, source_page_end, '.KnowledgeCandidateFilter::RANK_SQL.' AS rank_value')
            ->orderBy('normalized_code')
            ->orderByRaw('rank_value DESC')
            ->orderBy('source_page_start')
            ->orderBy('id')
            ->limit(5000)
            ->get();

        return $this->provenanceFromRows($rows);
    }

    private function provenanceFromRows($rows): array
    {
        $out = [];
        foreach ($rows->groupBy('normalized_code') as $code => $set) {
            $pages = $set->pluck('source_page_start')->filter(fn ($p) => $p !== null)->map(fn ($p) => (int) $p)->unique()->sort()->values();
            $out[$code] = [
                'pages' => $pages->take(self::PAGES_PREVIEW)->all(),
                'truncated' => $pages->count() > self::PAGES_PREVIEW,
                // Rows arrive best-evidence first, then earliest page (list) or in the detail order (detail).
                'representative' => (string) $set->first()->id,
            ];
        }

        return $out;
    }

    /**
     * How many of each listed group's rows match the row-level criteria (== occurrence_count when none apply).
     *
     * @param  list<string>  $codes
     * @return array<string, int>
     */
    private function matchingCounts(string $importId, array $filters, array $codes): array
    {
        $rowFilters = array_intersect_key($filters, array_flip(['code', 'evidence', 'collision_status', 'status', 'source_page_from', 'source_page_to', 'reference_like']));
        if ($rowFilters === [] || $codes === []) {
            return [];
        }
        $q = KnowledgeCandidateFilter::candidates($importId)->whereIn('normalized_code', $codes);
        KnowledgeCandidateFilter::applyRowCriteria($q, $rowFilters);

        return $q->groupBy('normalized_code')->selectRaw('normalized_code, COUNT(*) AS n')->pluck('n', 'normalized_code')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<string, mixed> */
    private function shape($row, ?array $provenance, ?int $matching, array $filters): array
    {
        $best = match ((int) $row->best_rank) {
            3 => 'HIGH', 2 => 'MEDIUM', default => 'LOW',
        };
        $count = (int) $row->occurrence_count;

        return [
            'normalized_code' => $row->normalized_code,
            'occurrence_count' => $count,
            // Rows of this group that satisfy the active row-level criteria (all of them when none are active).
            'matching_occurrence_count' => $filters === [] || $matching === null ? $count : $matching,
            'best_evidence' => $best,
            'evidence_counts' => ['HIGH' => (int) $row->high_count, 'MEDIUM' => (int) $row->medium_count, 'LOW' => (int) $row->low_count],
            // APPROVED here means approved and NOT yet published; PUBLISHED is counted separately, so the four sum to occurrence_count.
            'candidate_status_counts' => ['DRAFT' => (int) $row->draft_count, 'REJECTED' => (int) $row->rejected_count, 'APPROVED' => (int) $row->approved_count, 'PUBLISHED' => (int) $row->published_count],
            'review_state' => $row->review_state,
            'collision_counts' => ['NEW' => (int) $row->collision_new, 'EXISTING' => (int) $row->collision_existing, 'POTENTIAL_UPDATE' => (int) $row->collision_potential_update],
            'source_page_min' => $row->source_page_min === null ? null : (int) $row->source_page_min,
            'source_page_max' => $row->source_page_max === null ? null : (int) $row->source_page_max,
            'distinct_page_count' => (int) $row->distinct_page_count,
            'source_pages' => $provenance['pages'] ?? [],
            'source_pages_truncated' => $provenance['truncated'] ?? false,
            'has_multi_page_candidate' => (int) $row->multi_page_count > 0,
            // Derived from the detector's own stored dotted-leader signal; "all" means every occurrence looks like a TOC/index reference.
            'reference_like_count' => (int) $row->reference_like_count,
            'reference_like_all' => $count > 0 && (int) $row->reference_like_count === $count,
            'representative_candidate_id' => $provenance['representative'] ?? null,
        ];
    }
}
