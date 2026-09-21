<?php

namespace App\Services\KnowledgeReview;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * V1.7.2 - the single definition of "which candidates does this filter mean",
 * shared by the code-group read model, the group detail view, and the
 * filter-based bulk triage preview/apply so all four can never drift apart.
 *
 * Two kinds of criteria, deliberately kept separate:
 *
 *  ROW-LEVEL (a property of one candidate row - these are what bulk triage
 *  can act on): code, evidence, collision_status, status (read views only),
 *  source_page_from/to, reference_like. Within one request they are ANDed on
 *  the SAME row: "evidence=LOW and pages 30-38" means rows that are both LOW
 *  and inside 30-38, never "a LOW row somewhere and an unrelated row there".
 *
 *  GROUP-LEVEL (a property of a whole normalized-code group - read views
 *  only): review_state, min/max_occurrences. best_evidence is group-level in
 *  the read views and is also supported as a bulk criterion by translating it
 *  into an "IN (codes whose best evidence is ...)" subquery.
 *
 * reference_like reuses the detector's own stored signal: PdfKnowledge-
 * CandidateDetector down-ranks a match to LOW when its bounded context (which
 * IS the stored `description`) contains a run of 5+ dots (a TOC dotted
 * leader). Nothing new is stored - this only re-reads that same stored text
 * with a LIKE, and never returns the text itself.
 */
final class KnowledgeCandidateFilter
{
    public const EVIDENCE = ['HIGH', 'MEDIUM', 'LOW'];

    public const COLLISION = ['NEW', 'EXISTING', 'POTENTIAL_UPDATE'];

    public const STATUS = ['DRAFT', 'APPROVED', 'REJECTED'];

    /** STRONG = HIGH or MEDIUM. A transparent preset, not a claim that the candidate is correct. */
    public const BEST_EVIDENCE = ['HIGH', 'MEDIUM', 'LOW', 'STRONG'];

    public const REVIEW_STATES = ['UNREVIEWED', 'PARTIALLY_REVIEWED', 'REJECTED', 'APPROVED', 'PUBLISHED'];

    public const REFERENCE_LIKE = ['yes', 'no'];

    /** The five dots the detector itself treats as a table-of-contents leader. */
    private const DOT_LEADER_LIKE = '%.....%';

    public const RANK_SQL = "CASE evidence WHEN 'HIGH' THEN 3 WHEN 'MEDIUM' THEN 2 WHEN 'LOW' THEN 1 ELSE 0 END";

    /** Filters a bulk triage request may carry (status is decided by the action, never by the request). */
    public const BULK_KEYS = ['code', 'evidence', 'collision_status', 'source_page_from', 'source_page_to', 'reference_like', 'best_evidence'];

    /** @return array<string, mixed> Laravel validation rules for the read views' query string. */
    public static function readRules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'evidence' => ['nullable', Rule::in(self::EVIDENCE)],
            'collision_status' => ['nullable', Rule::in(self::COLLISION)],
            'status' => ['nullable', Rule::in(self::STATUS)],
            'source_page_from' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'source_page_to' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'reference_like' => ['nullable', Rule::in(self::REFERENCE_LIKE)],
            'best_evidence' => ['nullable', Rule::in(self::BEST_EVIDENCE)],
            'review_state' => ['nullable', Rule::in(self::REVIEW_STATES)],
            'min_occurrences' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_occurrences' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Ordering of a range's two bounds - checked only when BOTH are present (Laravel's `gte:` rule
     * fails when the other field is absent, which would wrongly reject "to only" / "max only").
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, string> validation messages keyed by field (empty when valid)
     */
    public static function rangeErrors(array $criteria): array
    {
        $errors = [];
        foreach ([['source_page_from', 'source_page_to'], ['min_occurrences', 'max_occurrences']] as [$low, $high]) {
            if (isset($criteria[$low], $criteria[$high]) && (int) $criteria[$high] < (int) $criteria[$low]) {
                $errors[$high] = "The {$high} must not be lower than {$low}.";
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> The same criteria, nested under `filters`, for the bulk request bodies. */
    public static function bulkRules(): array
    {
        $rules = ['filters' => ['required', 'array']];
        foreach (self::readRules() as $key => $rule) {
            if (in_array($key, self::BULK_KEYS, true)) {
                $rules['filters.'.$key] = $rule;
            }
        }
        // A bulk request may never carry read-view-only criteria: unknown keys are rejected, not ignored.
        $rules['filters.status'] = ['prohibited'];
        $rules['filters.review_state'] = ['prohibited'];
        $rules['filters.min_occurrences'] = ['prohibited'];
        $rules['filters.max_occurrences'] = ['prohibited'];

        return $rules;
    }

    /**
     * Canonical, order-independent form of the validated criteria: only known
     * non-empty keys, normalised casing, integers as integers, sorted by key.
     * This is what gets hashed into a preview token, so the same intent always
     * hashes the same way and a changed criterion always hashes differently.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    public static function canonicalize(array $raw, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $raw) || $raw[$key] === null || $raw[$key] === '') {
                continue;
            }
            $value = $raw[$key];
            $out[$key] = match ($key) {
                'source_page_from', 'source_page_to', 'min_occurrences', 'max_occurrences' => (int) $value,
                'code' => strtoupper(trim((string) $value)),
                default => is_string($value) ? strtoupper(trim($value)) : $value,
            };
            if ($key === 'reference_like') {
                $out[$key] = strtolower((string) $out[$key]);
            }
        }
        ksort($out);

        return $out;
    }

    /** True when at least one ROW-LEVEL criterion (the ones that narrow a candidate set) is present. */
    public static function hasRowCriteria(array $filters): bool
    {
        foreach (['code', 'evidence', 'collision_status', 'status', 'source_page_from', 'source_page_to', 'reference_like'] as $key) {
            if (isset($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /** Short human-readable, bounded label of the criteria, for audit metadata (values are all validated). */
    public static function summarize(array $filters): string
    {
        $parts = [];
        foreach ($filters as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return mb_substr(implode(';', $parts), 0, 200);
    }

    /** Apply the ROW-LEVEL criteria to a candidate query (any alias-free query over maintenance_knowledge_entries). */
    public static function applyRowCriteria(Builder $q, array $filters): Builder
    {
        if (isset($filters['code'])) {
            $q->where('normalized_code', 'like', '%'.$filters['code'].'%');
        }
        if (isset($filters['evidence'])) {
            $q->where('evidence', $filters['evidence']);
        }
        if (isset($filters['collision_status'])) {
            $q->where('collision_status', $filters['collision_status']);
        }
        if (isset($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        // A candidate covers [start, end]; it matches a page window when the two ranges overlap.
        if (isset($filters['source_page_from'])) {
            $q->whereRaw('COALESCE(source_page_end, source_page_start) >= ?', [$filters['source_page_from']]);
        }
        if (isset($filters['source_page_to'])) {
            $q->where('source_page_start', '<=', $filters['source_page_to']);
        }
        if (($filters['reference_like'] ?? null) === 'yes') {
            $q->where('description', 'like', self::DOT_LEADER_LIKE);
        } elseif (($filters['reference_like'] ?? null) === 'no') {
            $q->where(fn ($w) => $w->whereNull('description')->orWhere('description', 'not like', self::DOT_LEADER_LIKE));
        }

        return $q;
    }

    /** "best evidence" criterion as a subquery restriction: only codes whose strongest occurrence matches. */
    public static function applyBestEvidenceCriterion(Builder $q, string $importId, ?string $best): Builder
    {
        if ($best === null) {
            return $q;
        }
        [$op, $rank] = match ($best) {
            'HIGH' => ['=', 3],
            'MEDIUM' => ['=', 2],
            'LOW' => ['=', 1],
            default => ['>=', 2], // STRONG
        };

        return $q->whereIn('normalized_code', function ($sub) use ($importId, $op, $rank) {
            $sub->from('maintenance_knowledge_entries')
                ->select('normalized_code')
                ->where('import_id', $importId)
                ->whereNotNull('normalized_code')
                ->groupBy('normalized_code')
                ->havingRaw('MAX('.self::RANK_SQL.') '.$op.' ?', [$rank]);
        });
    }

    /** Fresh base query for one import's candidates. */
    public static function candidates(string $importId): Builder
    {
        return DB::table('maintenance_knowledge_entries')->where('import_id', $importId);
    }
}
