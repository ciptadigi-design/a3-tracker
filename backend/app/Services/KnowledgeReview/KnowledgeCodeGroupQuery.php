<?php

namespace App\Services\KnowledgeReview;

use App\Models\MaintenanceDocumentPage;
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

    /**
     * V1.9 - Source Page Context. A single occurrence's source_page_start..source_page_end is
     * real provenance (e.g. a procedure that spans two printed pages), but malformed/extreme
     * data must never turn into an unbounded page load. Matches the precedent already set by
     * KnowledgeGroupPublisher::MAX_RANGE_SPAN for the same "one candidate, one range" shape.
     * Real Production data (2639 pages, 2680 candidates) never exceeds a span of 1.
     */
    private const MAX_OCCURRENCE_PAGE_SPAN = 20;

    /**
     * V1.9 - total distinct pages fetched for one group's Source Page Context, across every
     * occurrence's range, after dedup. The worst case actually observed across all 705 real
     * code groups is 8 distinct pages; this stays generous without ever loading anywhere near
     * the full 2639-page extraction for one review action.
     */
    private const SOURCE_PAGE_LIMIT = 40;

    /**
     * V1.9 - how far a reviewer may step beyond a group's own source pages via the adjacent-page
     * endpoint. This is NOT a generic page reader: a requested page must be within this many
     * pages of one of the group's own source pages, or it is refused. Chosen from the real C-1127
     * review itself, which needed page 1459 (-1 from 1460) and pages 1462-1463 (+1/+2 from 1461)
     * to correctly attribute a procedure to the right code section.
     */
    private const ADJACENT_PAGE_MAX_DISTANCE = 3;

    /**
     * V1.10 - Knowledge Review Context Triage. A cheap, deterministic, PURELY STRUCTURAL signal:
     * where a group's representative candidate's code text falls within its own source page, as
     * a proportion of that page's length. This document's own layout puts a code's content
     * (Code/Classification/Cause/Measures/Solution/...) AFTER the code heading, so a match very
     * near the page's END means that content likely continues onto the NEXT page; very near the
     * START suggests the opposite - the group's context likely began on the PREVIOUS page. This
     * says NOTHING about whether a candidate is correct, good, or safe - only that a reviewer may
     * want to also read a neighbouring page (the V1.9 Source Page Context / adjacent-page tool)
     * before deciding.
     *
     * Thresholds are justified from real Production data, not guessed: across all 534 real
     * HIGH-evidence groups (representative-candidate selection identical to pageProvenance()'s
     * own), 79 (14.8%) sit >= NEAR_END, 143 (26.8%) sit <= NEAR_START, 312 (58.4%) fall
     * comfortably mid-page - a genuinely useful, non-degenerate split, not "nearly everything
     * flagged". Independently confirmed against two real codes read directly during V1.9's
     * acceptance: C-1124 and C-1125 sit on the SAME source page (1459) - C-1124 classifies
     * mid-page (self-contained, matching its confirmed complete Code..DIPSW section) while C-1125
     * classifies >= NEAR_END (matching its confirmed continuation onto page 1460).
     */
    private const CONTEXT_NEAR_END_RATIO = 0.75;

    private const CONTEXT_NEAR_START_RATIO = 0.10;

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
    public function groups(string $importId, array $filters, int $perPage, int $page, string $sort = 'best_evidence', string $direction = 'desc', ?string $documentId = null): array
    {
        $q = $this->groupedQuery($importId, $filters, $documentId);

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
        // V1.10 - one bulk lookup for the whole page of groups, never one per group.
        $contextHints = $documentId === null ? [] : $this->contextHints($documentId, $provenance);

        $groups = $rows->map(fn ($row) => $this->shape($row, $provenance[$row->normalized_code] ?? null, $matching[$row->normalized_code] ?? null, $filters, $contextHints[$row->normalized_code] ?? null))->values()->all();

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
     * V1.9 - also returns `source_pages`: the full extracted text of the group's own
     * supporting pages (see sourcePages()), scoped to $documentId so a page can never be
     * read across a document/account boundary. Pass null to omit page text entirely (e.g.
     * an existing caller that has no document context yet); the rest of the contract is
     * unchanged either way.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $importId, string $normalizedCode, ?string $documentId = null): ?array
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

        $detailProvenance = $this->provenanceFromRows($occurrences);
        // V1.10 - same structural hint shown in the list, for consistency when a reviewer opens
        // the group directly (e.g. from a bookmark) without having seen the list row.
        $contextHint = $documentId === null ? null : ($this->contextHints($documentId, $detailProvenance)[$normalizedCode] ?? null);
        $group = $this->shape($row, $detailProvenance, null, [], $contextHint);
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

        $pageSet = self::occurrencePageNumbers($occurrences);
        $primaryPageNumbers = $occurrences->pluck('source_page_start')->filter(fn ($p) => $p !== null)->map(fn ($p) => (int) $p)->unique()->values()->all();
        $group['source_pages'] = $documentId === null ? [] : $this->sourcePages($documentId, $pageSet['pages'], $primaryPageNumbers);
        $group['source_pages_truncated'] = $pageSet['truncated'];

        return $group;
    }

    /**
     * V1.9 - the deduplicated, bounded, ascending set of real page numbers a group's own
     * occurrences provide evidence for, expanding each occurrence's own
     * source_page_start..source_page_end (bounded per-occurrence by MAX_OCCURRENCE_PAGE_SPAN so
     * one malformed range can never explode the set). Pure computation over rows already in
     * memory - no extra query.
     *
     * @return array{pages: list<int>, truncated: bool}
     */
    private static function occurrencePageNumbers($occurrences): array
    {
        $pages = [];
        foreach ($occurrences as $occurrence) {
            if ($occurrence->source_page_start === null) {
                continue;
            }
            $start = (int) $occurrence->source_page_start;
            $end = min(max($start, (int) ($occurrence->source_page_end ?? $start)), $start + self::MAX_OCCURRENCE_PAGE_SPAN);
            for ($p = $start; $p <= $end; $p++) {
                $pages[$p] = true;
            }
        }
        $pages = array_keys($pages);
        sort($pages);

        return ['pages' => array_slice($pages, 0, self::SOURCE_PAGE_LIMIT), 'truncated' => count($pages) > self::SOURCE_PAGE_LIMIT];
    }

    /**
     * V1.9 - fetches full extracted text for exactly the given page numbers of ONE document, in
     * a single bulk query (no N+1), never any other document's pages. `is_primary` marks a page
     * that is some occurrence's actual detected source_page_start, as opposed to a page only
     * included because it falls inside another occurrence's start..end span.
     *
     * @param  list<int>  $pageNumbers
     * @return list<array<string, mixed>>
     */
    private function sourcePages(string $documentId, array $pageNumbers, array $primaryPageNumbers = []): array
    {
        if ($pageNumbers === []) {
            return [];
        }
        $rows = MaintenanceDocumentPage::where('document_id', $documentId)
            ->whereIn('page_number', $pageNumbers)
            ->get(['page_number', 'raw_text'])
            ->keyBy('page_number');

        $primary = array_flip($primaryPageNumbers);

        return array_values(array_filter(array_map(fn ($p) => $rows->has($p) ? [
            'page_number' => $p,
            'raw_text' => (string) $rows->get($p)->raw_text,
            'is_primary' => $primaryPageNumbers === [] ? true : isset($primary[$p]),
        ] : null, $pageNumbers)));
    }

    /**
     * V1.9 - the adjacent-page endpoint's read model: ONE page's full text, only if it is
     * within ADJACENT_PAGE_MAX_DISTANCE of one of the group's own source pages. This is
     * deliberately not a generic page reader - a page far from any of this group's own
     * evidence is refused (null), exactly like a page not found at all, so the caller cannot
     * distinguish "too far" from "does not exist" and cannot use this to browse the document.
     *
     * @return array{page_number: int, raw_text: string, is_direct_source: bool}|null
     */
    public function adjacentPage(string $importId, string $normalizedCode, ?string $documentId, int $pageNumber): ?array
    {
        if ($documentId === null) {
            return null;
        }
        $anchors = $this->rawSourcePageNumbers($importId, $normalizedCode);
        if ($anchors === []) {
            return null;
        }
        $withinReach = false;
        foreach ($anchors as $anchor) {
            if (abs($anchor - $pageNumber) <= self::ADJACENT_PAGE_MAX_DISTANCE) {
                $withinReach = true;
                break;
            }
        }
        if (! $withinReach) {
            return null;
        }

        $page = MaintenanceDocumentPage::where('document_id', $documentId)->where('page_number', $pageNumber)->first(['page_number', 'raw_text']);
        if ($page === null) {
            return null;
        }

        return ['page_number' => (int) $page->page_number, 'raw_text' => (string) $page->raw_text, 'is_direct_source' => in_array($pageNumber, $anchors, true)];
    }

    /**
     * V1.9 - the group's own anchor page numbers, computed the same way as detail()'s
     * source_pages but with a fresh, lightweight query (no candidate text) - used only to
     * validate an adjacent-page request's distance bound.
     *
     * @return list<int>
     */
    private function rawSourcePageNumbers(string $importId, string $normalizedCode): array
    {
        $rows = KnowledgeCandidateFilter::candidates($importId)
            ->where('normalized_code', $normalizedCode)
            ->selectRaw('source_page_start, source_page_end')
            ->limit(self::DETAIL_LIMIT)
            ->get();

        return self::occurrencePageNumbers($rows)['pages'];
    }

    /**
     * V1.10 - one bulk page-text fetch for every group on the current page/detail call, keyed by
     * page number (never by code, since two groups could share a representative page) - exactly
     * one additional query regardless of how many groups are being classified. The fetched text
     * is used only to compute a position ratio in PHP and is discarded immediately after;
     * `classifyContext()`'s return value (never the text) is what reaches shape() and the API.
     *
     * @param  array<string, array{representative_page?: int|null}>  $provenance  keyed by normalized_code
     * @return array<string, array{context_hint: string, context_reason: string|null}>
     */
    private function contextHints(string $documentId, array $provenance): array
    {
        $pageNumbers = collect($provenance)->pluck('representative_page')->filter(fn ($p) => $p !== null)->unique()->values()->all();
        if ($pageNumbers === []) {
            return [];
        }
        $pages = MaintenanceDocumentPage::where('document_id', $documentId)
            ->whereIn('page_number', $pageNumbers)
            ->pluck('raw_text', 'page_number');

        $hints = [];
        foreach ($provenance as $code => $p) {
            $pageNumber = $p['representative_page'] ?? null;
            $hints[$code] = self::classifyContext($code, $pageNumber === null ? null : $pages->get($pageNumber));
        }

        return $hints;
    }

    /**
     * V1.10 - which codes in this import match one context_hint, across the WHOLE import, not
     * just one list page - so the `context` filter composes correctly with pagination (a filtered
     * request must know which codes qualify BEFORE deciding what page 2 even contains). This is
     * deliberately more expensive than the per-page hint groups()/detail() compute on every
     * ordinary request: it fetches every group's representative candidate (entries table only,
     * cheap) plus a bulk page-text fetch bounded by the import's own distinct representative
     * pages (352 pages / ~1.1MB for the real 705-group Konica import - not the full 2639-page
     * extraction). It runs ONLY when a reviewer explicitly applies the context filter.
     *
     * @return list<string>
     */
    private function codesMatchingContext(string $importId, string $documentId, string $wantedHint): array
    {
        $entries = KnowledgeCandidateFilter::candidates($importId)
            ->whereNotNull('normalized_code')
            ->selectRaw('id, normalized_code, source_page_start, '.KnowledgeCandidateFilter::RANK_SQL.' AS rank_value')
            ->orderBy('normalized_code')
            ->orderByRaw('rank_value DESC')
            ->orderBy('source_page_start')
            ->orderBy('id')
            ->get();

        $provenance = $this->provenanceFromRows($entries);
        $hints = $this->contextHints($documentId, $provenance);

        return collect($hints)->filter(fn ($h) => $h['context_hint'] === $wantedHint)->keys()->values()->all();
    }

    /**
     * V1.10 - the classification itself. Fails safe to SELF_CONTAINED (the neutral, no-hint
     * default) whenever the position cannot be determined - a missing page, an empty page, or the
     * code literally not found on its own recorded page (never observed in real data, but a
     * malformed/legacy row must never produce a false "check the adjacent page" alarm).
     *
     * @return array{context_hint: string, context_reason: string|null}
     */
    private static function classifyContext(string $code, ?string $rawText): array
    {
        $selfContained = ['context_hint' => 'SELF_CONTAINED', 'context_reason' => null];
        $length = $rawText === null ? 0 : mb_strlen($rawText);
        if ($length === 0) {
            return $selfContained;
        }
        $position = mb_strpos($rawText, $code);
        if ($position === false) {
            return $selfContained;
        }
        $ratio = $position / $length;
        if ($ratio >= self::CONTEXT_NEAR_END_RATIO) {
            return ['context_hint' => 'CONTEXT_RECOMMENDED', 'context_reason' => 'PAGE_END_CONTINUATION'];
        }
        if ($ratio <= self::CONTEXT_NEAR_START_RATIO) {
            return ['context_hint' => 'CONTEXT_RECOMMENDED', 'context_reason' => 'PAGE_START_CONTINUATION'];
        }

        return $selfContained;
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

    private function groupedQuery(string $importId, array $filters, ?string $documentId = null)
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

        // V1.10 - group-level, like review_state below, but the classification itself needs page
        // text: resolved once, across the WHOLE import, before pagination (see
        // codesMatchingContext()) so filtering never disturbs which groups land on which page.
        if (isset($filters['context']) && $documentId !== null) {
            $matchingCodes = $this->codesMatchingContext($importId, $documentId, $filters['context']);
            $q->whereIn('normalized_code', $matchingCodes === [] ? ['__none__'] : $matchingCodes);
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
            $representative = $set->first();
            $out[$code] = [
                'pages' => $pages->take(self::PAGES_PREVIEW)->all(),
                'truncated' => $pages->count() > self::PAGES_PREVIEW,
                // Rows arrive best-evidence first, then earliest page (list) or in the detail order (detail).
                'representative' => (string) $representative->id,
                // V1.10 - the representative's own page, reused by contextHints() below at zero extra
                // query cost (the row is already in memory here).
                'representative_page' => $representative->source_page_start === null ? null : (int) $representative->source_page_start,
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
    private function shape($row, ?array $provenance, ?int $matching, array $filters, ?array $contextHint = null): array
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
            // V1.10 - structural review hint only; never implies correctness. See classifyContext().
            'context_hint' => $contextHint['context_hint'] ?? 'SELF_CONTAINED',
            'context_reason' => $contextHint['context_reason'] ?? null,
        ];
    }
}
