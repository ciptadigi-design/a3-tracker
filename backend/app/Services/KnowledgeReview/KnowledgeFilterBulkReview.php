<?php

namespace App\Services\KnowledgeReview;

use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use App\Services\GovernanceAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * V1.7.2 - filter-based bulk REJECT / RESTORE for review triage.
 *
 * The V1.7 ID-based endpoint (100 IDs) is untouched. This adds a two-step,
 * server-driven flow for clusters too large to hand-select (the real import's
 * 1,187 table-of-contents / index candidates):
 *
 *   preview  - counts what a filter would touch, mutates NOTHING, returns a
 *              bounded sample plus a signed, short-lived confirmation token.
 *   apply    - re-derives the eligible set INSIDE a transaction with row locks
 *              and refuses (409 STALE_PREVIEW) unless it is exactly the set the
 *              token was issued for.
 *
 * The token is stateless (no table): an HMAC over {import, action, canonical
 * filters, acting user, expiry, eligible count, and a digest of the sorted
 * eligible candidate IDs}. Tampering with the filters/action/count, replaying a
 * token for another import or user, or any change to the eligible set between
 * preview and apply (a candidate reviewed, added, or published) all fail closed.
 * The client never supplies an affected count; the server reports what it did.
 *
 * Only DRAFT -> REJECTED (reject) and REJECTED -> DRAFT (restore) exist. There is
 * no approve, no publish, no delete, and a row with published_at set is never
 * eligible for anything. Nothing here can reach machine_error_codes,
 * maintenance_error_solutions or maintenance_document_references.
 */
final class KnowledgeFilterBulkReview
{
    public const ACTIONS = [
        'reject' => ['from' => 'DRAFT', 'to' => 'REJECTED', 'event' => 'knowledge_candidates_filter_bulk_rejected'],
        'restore' => ['from' => 'REJECTED', 'to' => 'DRAFT', 'event' => 'knowledge_candidates_filter_bulk_restored'],
    ];

    /** Real imports hold ~2.7k candidates; this bounds one transaction, never a routine limit. */
    public const MAX_ELIGIBLE = 5000;

    public const SAMPLE_SIZE = 10;

    public const TOKEN_TTL_SECONDS = 900;

    private const UPDATE_CHUNK = 500;

    /**
     * @param  array<string, mixed>  $filters  validated, canonical criteria
     * @return array<string, mixed>
     */
    public function preview(User $actor, MaintenanceDocumentImport $import, string $action, array $filters): array
    {
        $this->assertFiltersNarrow($filters);
        $spec = self::ACTIONS[$action];

        $matching = $this->matchingQuery($import->id, $filters);
        $breakdown = (clone $matching)->selectRaw('status, CASE WHEN published_at IS NULL THEN 0 ELSE 1 END AS is_published, COUNT(*) AS n')
            ->groupBy('status', 'is_published')->get();

        $matchingCount = 0;
        $eligibleCount = 0;
        $excluded = ['DRAFT' => 0, 'REJECTED' => 0, 'APPROVED' => 0, 'PUBLISHED' => 0];
        foreach ($breakdown as $cell) {
            $n = (int) $cell->n;
            $matchingCount += $n;
            if ((int) $cell->is_published === 1) {
                $excluded['PUBLISHED'] += $n;
            } elseif ($cell->status === $spec['from']) {
                $eligibleCount += $n;
            } else {
                $excluded[$cell->status] = ($excluded[$cell->status] ?? 0) + $n;
            }
        }

        $base = [
            'action' => $action,
            'filters' => $filters,
            'matching_count' => $matchingCount,
            'eligible_count' => $eligibleCount,
            'excluded_count' => $matchingCount - $eligibleCount,
            'excluded_breakdown' => $excluded,
            'max_eligible' => self::MAX_ELIGIBLE,
        ];

        if ($eligibleCount === 0) {
            return $base + ['sample' => [], 'can_apply' => false, 'blocked_reason' => 'NOTHING_ELIGIBLE', 'confirmation_token' => null, 'expires_at' => null];
        }
        if ($eligibleCount > self::MAX_ELIGIBLE) {
            return $base + ['sample' => [], 'can_apply' => false, 'blocked_reason' => 'TOO_MANY_CANDIDATES', 'confirmation_token' => null, 'expires_at' => null];
        }

        $ids = $this->eligibleQuery($import->id, $filters, $spec['from'])->orderBy('id')->pluck('id')->all();
        $expires = now()->timestamp + self::TOKEN_TTL_SECONDS;

        return $base + [
            'sample' => $this->sample($import->id, $ids),
            'can_apply' => true,
            'blocked_reason' => null,
            'confirmation_token' => $this->issueToken($actor, $import->id, $action, $filters, $ids, $expires),
            'expires_at' => gmdate('c', $expires),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{action: string, affected: int}
     */
    public function apply(User $actor, MaintenanceDocumentImport $import, string $action, array $filters, string $token, ?string $accountId): array
    {
        $this->assertFiltersNarrow($filters);
        $spec = self::ACTIONS[$action];
        $claims = $this->verifyToken($token, $actor, $import->id, $action, $filters);

        $affected = DB::transaction(function () use ($actor, $import, $filters, $spec, $claims, $accountId) {
            // Lock the exact rows the mutation will touch, then compare their identity to the previewed set.
            $ids = $this->eligibleQuery($import->id, $filters, $spec['from'])->orderBy('id')->lockForUpdate()->pluck('id')->all();

            if (count($ids) !== $claims['n'] || ! hash_equals($claims['dg'], self::digest($ids))) {
                throw new ConflictHttpException('[STALE_PREVIEW] The candidates matching this filter changed after the preview. Preview again before applying.');
            }

            $updated = 0;
            foreach (array_chunk($ids, self::UPDATE_CHUNK) as $chunk) {
                // Re-assert the source status and unpublished state on every write: defence in depth on top of the row locks.
                $updated += MaintenanceKnowledgeEntry::whereIn('id', $chunk)
                    ->where('import_id', $import->id)
                    ->where('status', $spec['from'])
                    ->whereNull('published_at')
                    ->update(['status' => $spec['to']]);
            }
            if ($updated !== count($ids)) {
                throw new ConflictHttpException('[STALE_PREVIEW] The candidates matching this filter changed while applying. Nothing was changed; preview again.');
            }

            app(GovernanceAudit::class)->record($actor, $spec['event'], 'maintenance_document_import', $import->id, $accountId, [
                'changes' => [
                    'affected_count' => ['before' => null, 'after' => $updated],
                    'filter_fingerprint' => ['before' => null, 'after' => substr(self::filtersHash($filters), 0, 16)],
                    'filter_summary' => ['before' => null, 'after' => KnowledgeCandidateFilter::summarize($filters)],
                ],
            ]);

            return $updated;
        });

        return ['action' => $action, 'affected' => $affected];
    }

    /** A triage request with no narrowing criterion would mean "the whole import" - refused outright. */
    private function assertFiltersNarrow(array $filters): void
    {
        $narrow = KnowledgeCandidateFilter::hasRowCriteria($filters) || isset($filters['best_evidence']);
        if (! $narrow) {
            throw ValidationException::withMessages(['filters' => '[FILTER_REQUIRED] Bulk triage needs at least one filter (evidence, page range, code, collision or reference-like).']);
        }
    }

    private function matchingQuery(string $importId, array $filters)
    {
        $q = KnowledgeCandidateFilter::candidates($importId);
        KnowledgeCandidateFilter::applyRowCriteria($q, array_diff_key($filters, ['best_evidence' => true]));

        return KnowledgeCandidateFilter::applyBestEvidenceCriterion($q, $importId, $filters['best_evidence'] ?? null);
    }

    private function eligibleQuery(string $importId, array $filters, string $fromStatus)
    {
        return $this->matchingQuery($importId, $filters)->where('status', $fromStatus)->whereNull('published_at')->select('id');
    }

    /**
     * Evenly spaced picks from the ID-sorted eligible list: deterministic (no RAND) and spread across
     * the whole set rather than just its first rows. Returns structure only, never candidate text.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function sample(string $importId, array $ids): array
    {
        $total = count($ids);
        $take = min(self::SAMPLE_SIZE, $total);
        $picked = [];
        for ($i = 0; $i < $take; $i++) {
            $picked[] = $ids[(int) floor($i * $total / $take)];
        }

        return KnowledgeCandidateFilter::candidates($importId)->whereIn('id', $picked)
            ->select(['id', 'normalized_code', 'evidence', 'collision_status', 'source_page_start', 'source_page_end', 'status'])
            ->orderBy('source_page_start')->orderBy('normalized_code')->orderBy('id')
            ->get()->map(fn ($r) => [
                'id' => $r->id,
                'normalized_code' => $r->normalized_code,
                'evidence' => $r->evidence,
                'collision_status' => $r->collision_status,
                'source_page_start' => $r->source_page_start === null ? null : (int) $r->source_page_start,
                'source_page_end' => $r->source_page_end === null ? null : (int) $r->source_page_end,
                'status' => $r->status,
            ])->all();
    }

    /** @param  list<string>  $sortedIds */
    private static function digest(array $sortedIds): string
    {
        return hash('sha256', implode('|', $sortedIds));
    }

    private static function filtersHash(array $filters): string
    {
        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
    }

    /** @param  list<string>  $ids */
    private function issueToken(User $actor, string $importId, string $action, array $filters, array $ids, int $expires): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'v' => 1, 'i' => $importId, 'a' => $action, 'f' => self::filtersHash($filters), 'u' => (string) $actor->id,
            'n' => count($ids), 'dg' => self::digest($ids), 'exp' => $expires,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, $this->signingKey());
    }

    /** @return array{n: int, dg: string} */
    private function verifyToken(string $token, User $actor, string $importId, string $action, array $filters): array
    {
        $invalid = fn () => ValidationException::withMessages(['confirmation_token' => '[INVALID_CONFIRMATION_TOKEN] The confirmation is invalid. Preview again before applying.']);
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw $invalid();
        }
        if (! hash_equals(hash_hmac('sha256', $parts[0], $this->signingKey()), $parts[1])) {
            throw $invalid();
        }
        $claims = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($claims) || ($claims['v'] ?? null) !== 1) {
            throw $invalid();
        }
        // The token is only valid for exactly the import, action, filters and user it was issued for.
        if (($claims['i'] ?? null) !== $importId || ($claims['a'] ?? null) !== $action || ($claims['u'] ?? null) !== (string) $actor->id
            || ! hash_equals((string) ($claims['f'] ?? ''), self::filtersHash($filters))) {
            throw $invalid();
        }
        if ((int) ($claims['exp'] ?? 0) < now()->timestamp) {
            throw new ConflictHttpException('[STALE_PREVIEW] The preview expired. Preview again before applying.');
        }

        return ['n' => (int) $claims['n'], 'dg' => (string) $claims['dg']];
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required to sign bulk review confirmations.');
        }

        return hash('sha256', 'knowledge-filter-bulk-review|'.$key);
    }
}
