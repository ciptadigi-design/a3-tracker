<?php

namespace App\Services\KnowledgeReview;

use App\Models\MachineErrorCode;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentReference;
use App\Models\MaintenanceErrorSolution;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use App\Services\GovernanceAudit;
use App\Services\MaintenanceKnowledgePublishService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * V1.8 - Group-aware canonical review and SINGLE-CODE publish.
 *
 * The review-to-publish unit for PDF-derived knowledge is the normalized error-code GROUP, not a candidate
 * row. One group publishes as exactly ONE logical error-code publication in ONE transaction:
 *
 *   ONE machine_error_code  <-  the reviewer's canonical title/description/operator guidance
 *   0..1 maintenance_error_solution step  <-  the reviewer's technician solution
 *   N maintenance_document_references  <-  one per unique SUPPORTING source page (provenance)
 *
 * Why not the legacy per-candidate MaintenanceKnowledgePublishService: it upserts machine_error_codes by
 * (account, model, code) with last-writer-wins, so publishing several occurrences of one code would let
 * candidate order decide the published text. Here the reviewer's explicit canonical selection and authored
 * content decide, and the supporting occurrences are provenance only. Nothing here calls that service per
 * occurrence, and legacy candidate-level publish is refused for PDF-derived candidates (see the controller).
 *
 * No migration: the canonical content travels in the (token-bound) request; the server recomputes every
 * fact it relies on (collision, supporting pages, existing record) at preview AND at publish, inside a
 * transaction that row-locks the group. Publishing marks ONLY the canonical row (APPROVED + published_at);
 * supporting occurrences are never rewritten, so the V1.7.2 derived group state becomes PUBLISHED with no
 * fake per-row mutation. Candidate text (the detector's excerpts) is never modified or copied.
 *
 * Idempotency is decided BEFORE staleness: if the group is already published and the requested canonical
 * state is identical to what is already in the catalog, the call succeeds without writing (a double click or
 * a network retry is not a conflict); if it differs, publishing is refused (409) - changing published
 * content needs a future update flow and must never happen because a duplicate request arrived.
 */
final class KnowledgeGroupPublisher
{
    public const PURPOSE = 'group_publish';

    public const TTL_SECONDS = 900;

    /** Supporting-page list bound in a response; a range wider than this never occurs for real candidates. */
    private const PAGE_LIST_LIMIT = 500;

    private const MAX_RANGE_SPAN = 20;

    // ---------------------------------------------------------------- read side (group detail)

    /** @return array<string, mixed> */
    public function summary(MaintenanceDocumentImport $import, string $code): array
    {
        $ctx = $this->context($import, $code, false);
        $published = $ctx['published'];
        $suggested = $ctx['entries']->filter(fn ($e) => $e->status !== 'REJECTED' && $e->published_at === null)
            ->sortBy([fn ($a, $b) => $this->rank($b->evidence) <=> $this->rank($a->evidence), fn ($a, $b) => ($a->source_page_start ?? PHP_INT_MAX) <=> ($b->source_page_start ?? PHP_INT_MAX), fn ($a, $b) => strcmp($a->id, $b->id)])->first();

        return [
            'published' => $published->isNotEmpty(),
            'published_at' => $published->first()?->published_at?->toIso8601String(),
            'canonical_candidate_id' => $published->count() === 1 ? $published->first()->id : null,
            'machine_error_code_id' => $ctx['existing']?->id,
            'supporting_page_count' => count($ctx['pages']),
            'supporting_pages' => array_slice($ctx['pages'], 0, self::PAGE_LIST_LIMIT),
            'supporting_occurrence_count' => $ctx['supporting']->count(),
            'excluded_rejected_count' => $ctx['entries']->count() - $ctx['supporting']->count(),
            // A SUGGESTION only (strongest evidence, then earliest page). The reviewer's explicit choice is authoritative.
            'suggested_canonical_candidate_id' => $suggested?->id,
            'suggested_title' => $suggested && ! PlaceholderTitle::isPlaceholder($suggested->title, $code) ? $suggested->title : null,
            'server_collision_status' => $this->collision($ctx['existing'], $ctx['solutions']->count()),
            'existing_record' => $ctx['existing'] ? ['id' => $ctx['existing']->id, 'title' => $ctx['existing']->title] : null,
        ];
    }

    // ---------------------------------------------------------------- preview (read-only)

    /**
     * @param  array<string, mixed>  $input  validated canonical input
     * @return array<string, mixed>
     */
    public function preview(User $actor, MaintenanceDocumentImport $import, string $code, array $input): array
    {
        $proposed = $this->proposed($input);
        $this->assertContent($proposed, $code);
        $ctx = $this->context($import, $code, false);
        $canonical = $this->canonical($ctx, $proposed['canonical_candidate_id']);
        $plan = $this->plan($ctx, $proposed);

        $base = [
            'normalized_code' => $code,
            'canonical_candidate_id' => $canonical->id,
            'canonical_source_page' => $canonical->source_page_start === null ? null : (int) $canonical->source_page_start,
            'collision_status' => $plan['collision'],
            'requires_update_confirmation' => $plan['requires_update_confirmation'],
            'proposed' => ['title' => $proposed['title'], 'description' => $proposed['description'], 'operator_guidance' => $proposed['operator_guidance'], 'technician_solution' => $proposed['technician_solution']],
            'existing' => $ctx['existing'] ? ['id' => $ctx['existing']->id, 'title' => $ctx['existing']->title, 'changed_fields' => $plan['changed_fields']] : null,
            'provenance' => [
                'occurrence_count' => $ctx['entries']->count(),
                'supporting_occurrence_count' => $ctx['supporting']->count(),
                'excluded_rejected_count' => $ctx['entries']->count() - $ctx['supporting']->count(),
                'supporting_page_count' => count($ctx['pages']),
                'supporting_pages' => array_slice($ctx['pages'], 0, self::PAGE_LIST_LIMIT),
            ],
            'mutation' => ['error_code' => $plan['error_code'], 'solution' => $plan['solution'], 'references_to_create' => count($plan['pages_to_create']), 'references_existing' => count($ctx['pages']) - count($plan['pages_to_create'])],
            'already_published' => $plan['already_published'],
        ];

        $block = $plan['blocked_reason'];
        if ($block !== null || $plan['already_published'] !== null) {
            return $base + ['can_publish' => false, 'blocked_reason' => $block, 'confirmation_token' => null, 'expires_at' => null];
        }

        $expires = now()->timestamp + self::TTL_SECONDS;
        $token = PreviewToken::issue(self::PURPOSE, [
            'i' => $import->id, 'c' => $code, 'u' => (string) $actor->id, 'k' => $canonical->id,
            'h' => $this->contentHash($proposed), 's' => $ctx['fingerprint'], 'r' => $plan['requires_update_confirmation'],
        ], self::TTL_SECONDS);

        return $base + ['can_publish' => true, 'blocked_reason' => null, 'confirmation_token' => $token, 'expires_at' => gmdate('c', $expires)];
    }

    // ---------------------------------------------------------------- publish (the one mutation)

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function publish(User $actor, MaintenanceDocumentImport $import, string $code, array $input, string $token, bool $confirmUpdate): array
    {
        $proposed = $this->proposed($input);
        $this->assertContent($proposed, $code);
        $claims = PreviewToken::claims(self::PURPOSE, $token);
        // The token is only valid for exactly the import, group, user, canonical occurrence and content it was issued for.
        $invalid = fn () => ValidationException::withMessages(['confirmation_token' => '[INVALID_CONFIRMATION_TOKEN] The confirmation does not match this publication. Preview again before publishing.']);
        if (($claims['i'] ?? null) !== $import->id || ($claims['c'] ?? null) !== $code || ($claims['u'] ?? null) !== (string) $actor->id
            || ($claims['k'] ?? null) !== $proposed['canonical_candidate_id'] || ! hash_equals((string) ($claims['h'] ?? ''), $this->contentHash($proposed))) {
            throw $invalid();
        }

        $accountId = $import->document->account_id;

        return DB::transaction(function () use ($actor, $import, $code, $proposed, $claims, $confirmUpdate, $accountId) {
            $ctx = $this->context($import, $code, true);
            $canonical = $this->canonical($ctx, $proposed['canonical_candidate_id']);
            $plan = $this->plan($ctx, $proposed);

            // 1. Idempotency first: a retry of an already-completed identical publication is a success, never a conflict.
            if ($plan['already_published'] === 'IDENTICAL') {
                return $this->result($ctx, $plan, $canonical, false, 'unchanged', false, 0);
            }
            if ($plan['already_published'] === 'DIFFERENT') {
                throw new ConflictHttpException('[ALREADY_PUBLISHED] This code group is already published with different content. Changing published knowledge needs a separate update review.');
            }
            // 2. State conflicts and staleness.
            if ($plan['blocked_reason'] !== null) {
                throw new ConflictHttpException('['.$plan['blocked_reason'].'] This code group cannot be published in its current state.');
            }
            if (PreviewToken::isExpired($claims) || ! hash_equals((string) ($claims['s'] ?? ''), $ctx['fingerprint'])) {
                throw new ConflictHttpException('[STALE_PREVIEW] The group or the existing catalog record changed after the preview. Nothing was published; preview again.');
            }
            // 3. An existing catalog record is never touched without the reviewer's explicit awareness.
            if ($plan['requires_update_confirmation'] && ! $confirmUpdate) {
                throw ValidationException::withMessages(['confirm_update' => '[UPDATE_CONFIRMATION_REQUIRED] A catalog record for this code already exists. Confirm the update explicitly to publish.']);
            }

            $errorCode = $ctx['existing'];
            $created = false;
            $audit = app(GovernanceAudit::class);

            if ($errorCode === null) {
                $errorCode = new MachineErrorCode(['account_id' => $accountId, 'machine_model_id' => $import->machine_model_id]);
                $errorCode->fill([
                    'code' => $code, 'title' => $proposed['title'], 'category' => 'ERROR_CODE', 'severity' => 'warning',
                    'manufacturer_description' => $proposed['description'], 'operator_description' => $proposed['operator_guidance'],
                    'source_document_id' => $import->document_id, 'is_active' => true,
                ]);
                $errorCode->save();
                $created = true;
                $audit->changed($actor, 'machine_error_code.published_from_import', 'machine_error_code', $errorCode->id, $accountId, [], $audit->snapshot($errorCode), array_keys($errorCode->getChanges()));
            } elseif ($plan['changed_fields'] !== []) {
                $before = $audit->snapshot($errorCode);
                $fill = [];
                foreach ($plan['changed_fields'] as $field) {
                    $fill[$field] = match ($field) {
                        'title' => $proposed['title'],
                        'manufacturer_description' => $proposed['description'],
                        'operator_description' => $proposed['operator_guidance'],
                    };
                }
                $errorCode->fill($fill + ($errorCode->source_document_id === null ? ['source_document_id' => $import->document_id] : []));
                $errorCode->save();
                $audit->changed($actor, 'machine_error_code.published_from_import', 'machine_error_code', $errorCode->id, $accountId, $before, $audit->snapshot($errorCode), array_keys($errorCode->getChanges()));
            }

            $solutionCreated = false;
            if ($plan['solution'] === 'CREATE') {
                $next = (int) MaintenanceErrorSolution::where('machine_error_code_id', $errorCode->id)->max('step_number') + 1;
                MaintenanceErrorSolution::create(['machine_error_code_id' => $errorCode->id, 'step_number' => $next, 'instruction' => $proposed['technician_solution'], 'requires_technician' => true, 'created_by' => $actor->id]);
                $solutionCreated = true;
            }

            // One reference per unique supporting page not already referenced for this document + code. Page numbers only.
            $refsCreated = 0;
            $title = mb_substr($proposed['title'], 0, 200);
            foreach ($plan['pages_to_create'] as $page) {
                MaintenanceDocumentReference::create(['document_id' => $import->document_id, 'machine_error_code_id' => $errorCode->id, 'reference_type' => 'error_code', 'page_number' => $page, 'section_title' => $title, 'created_by' => $actor->id]);
                $refsCreated++;
            }

            // Mark ONLY the canonical occurrence: the reviewer's explicit publish is the approval. Supporting rows stay as they are.
            $canonical->update(['status' => 'APPROVED', 'approved_by' => $canonical->approved_by ?? $actor->id, 'published_at' => now()]);

            $mode = $created ? 'created' : 'updated';
            $audit->record($actor, 'maintenance_knowledge_group.published', 'maintenance_document_import', $import->id, $accountId, [
                'changes' => [
                    'normalized_code' => ['before' => null, 'after' => $code],
                    'canonical_candidate_id' => ['before' => null, 'after' => $canonical->id],
                    'occurrence_count' => ['before' => null, 'after' => $ctx['entries']->count()],
                    'supporting_page_count' => ['before' => null, 'after' => count($ctx['pages'])],
                    'collision_status' => ['before' => null, 'after' => $plan['collision']],
                    'publish_mode' => ['before' => null, 'after' => $mode],
                    'publication_fingerprint' => ['before' => null, 'after' => substr(hash('sha256', $this->contentHash($proposed).'|'.$canonical->id), 0, 16)],
                ],
            ]);

            app(MaintenanceKnowledgePublishService::class)->advanceImportStatusIfComplete($import);

            $ctx['existing'] = $errorCode;

            return $this->result($ctx, $plan, $canonical->fresh(), true, $mode, $solutionCreated, $refsCreated);
        });
    }

    // ---------------------------------------------------------------- shared evaluation

    /**
     * Everything the preview and the publish rely on, derived from the database (never from the client).
     * With $lock the group's rows and the existing catalog record are row-locked for the transaction.
     *
     * @return array<string, mixed>
     */
    private function context(MaintenanceDocumentImport $import, string $code, bool $lock): array
    {
        $q = MaintenanceKnowledgeEntry::where('import_id', $import->id)->where('normalized_code', $code)->orderBy('id');
        $entries = ($lock ? $q->lockForUpdate() : $q)->get();
        if ($entries->isEmpty()) {
            abort(404);
        }

        $supporting = $entries->filter(fn ($e) => $e->status !== 'REJECTED')->values();
        $pages = [];
        foreach ($supporting as $e) {
            if ($e->source_page_start === null) {
                continue;
            }
            $start = (int) $e->source_page_start;
            $end = min(max($start, (int) ($e->source_page_end ?? $start)), $start + self::MAX_RANGE_SPAN);
            for ($p = $start; $p <= $end; $p++) {
                $pages[$p] = true;
            }
        }
        $pages = array_keys($pages);
        sort($pages);

        $eq = MachineErrorCode::where('account_id', $import->document->account_id)->where('machine_model_id', $import->machine_model_id)->where('code', $code);
        $existing = ($lock ? $eq->lockForUpdate() : $eq)->first();
        $solutions = $existing ? MaintenanceErrorSolution::where('machine_error_code_id', $existing->id)->orderBy('step_number')->get(['step_number', 'instruction']) : collect();
        $refPages = $existing
            ? MaintenanceDocumentReference::where('document_id', $import->document_id)->where('machine_error_code_id', $existing->id)->whereNotNull('page_number')->pluck('page_number')->map(fn ($p) => (int) $p)->unique()->values()->all()
            : [];
        $refCount = $existing ? MaintenanceDocumentReference::where('machine_error_code_id', $existing->id)->count() : 0;

        $entriesDigest = hash('sha256', $entries->map(fn ($e) => implode(':', [$e->id, $e->status, $e->published_at ? 'P' : '-', $e->source_page_start, $e->source_page_end]))->implode('|'));
        $existingDigest = $existing
            ? hash('sha256', json_encode([$existing->id, $existing->title, $existing->manufacturer_description, $existing->operator_description, $existing->official_solution, $existing->solution_summary, (bool) $existing->is_active, $solutions->count(), (int) $solutions->max('step_number'), $refCount]))
            : 'NEW';

        return [
            'entries' => $entries, 'supporting' => $supporting, 'pages' => $pages,
            'published' => $entries->filter(fn ($e) => $e->published_at !== null)->values(),
            'existing' => $existing, 'solutions' => $solutions, 'ref_pages' => $refPages,
            'fingerprint' => hash('sha256', $entriesDigest.'|'.$existingDigest),
        ];
    }

    /** @param  array<string, mixed>  $ctx */
    private function canonical(array $ctx, string $id): MaintenanceKnowledgeEntry
    {
        /** @var Collection $entries */
        $entries = $ctx['entries'];
        $canonical = $entries->firstWhere('id', $id);
        // A candidate of another group, another import or another account simply is not in this group's rows.
        if (! $canonical) {
            throw ValidationException::withMessages(['canonical_candidate_id' => 'The canonical occurrence must be one of this code group\'s occurrences.']);
        }

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    private function plan(array $ctx, array $proposed): array
    {
        $existing = $ctx['existing'];
        $canonical = $ctx['entries']->firstWhere('id', $proposed['canonical_candidate_id']);
        $collision = $this->collision($existing, $ctx['solutions']->count());

        $changed = [];
        if ($existing) {
            if ($this->norm($existing->title) !== $this->norm($proposed['title'])) {
                $changed[] = 'title';
            }
            // A blank proposed field never blanks an existing value: only provided content is written.
            if ($proposed['description'] !== null && $this->norm($existing->manufacturer_description) !== $this->norm($proposed['description'])) {
                $changed[] = 'manufacturer_description';
            }
            if ($proposed['operator_guidance'] !== null && $this->norm($existing->operator_description) !== $this->norm($proposed['operator_guidance'])) {
                $changed[] = 'operator_description';
            }
        } else {
            $changed = array_values(array_filter(['title', $proposed['description'] !== null ? 'manufacturer_description' : null, $proposed['operator_guidance'] !== null ? 'operator_description' : null]));
        }

        $solution = 'NONE';
        if ($proposed['technician_solution'] !== null) {
            $needle = $this->squash($proposed['technician_solution']);
            $solution = $ctx['solutions']->contains(fn ($s) => $this->squash($s->instruction) === $needle) ? 'SKIP_EXISTS' : 'CREATE';
        }

        $pagesToCreate = array_values(array_diff($ctx['pages'], $ctx['ref_pages']));

        $already = null;
        if ($ctx['published']->isNotEmpty()) {
            $identical = $ctx['published']->count() === 1 && $ctx['published']->first()->id === $proposed['canonical_candidate_id']
                && $existing !== null && $changed === [] && $solution !== 'CREATE' && $pagesToCreate === [];
            $already = $identical ? 'IDENTICAL' : 'DIFFERENT';
        }

        $blocked = null;
        if ($already === 'DIFFERENT') {
            $blocked = 'ALREADY_PUBLISHED_DIFFERENT';
        } elseif ($already === null && $canonical->status === 'REJECTED') {
            $blocked = 'CANONICAL_REJECTED';
        }

        return [
            'collision' => $collision,
            'requires_update_confirmation' => $existing !== null && $already === null,
            'changed_fields' => $changed,
            'error_code' => $existing === null ? 'CREATE' : ($changed === [] ? 'NONE' : 'UPDATE'),
            'solution' => $solution,
            'pages_to_create' => $pagesToCreate,
            'already_published' => $already,
            'blocked_reason' => $blocked,
        ];
    }

    /**
     * Server-side collision, recomputed now (the V1.6 candidate snapshot can be stale): NEW = no catalog record;
     * EXISTING = a record with real content; POTENTIAL_UPDATE = a record that exists but is still empty.
     */
    private function collision(?MachineErrorCode $existing, int $solutionCount): string
    {
        if ($existing === null) {
            return 'NEW';
        }
        $hasContent = $solutionCount > 0 || filled($existing->manufacturer_description) || filled($existing->operator_description) || filled($existing->official_solution) || filled($existing->solution_summary);

        return $hasContent ? 'EXISTING' : 'POTENTIAL_UPDATE';
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function result(array $ctx, array $plan, MaintenanceKnowledgeEntry $canonical, bool $published, string $mode, bool $solutionCreated, int $refsCreated): array
    {
        return [
            'published' => $published,
            'already_published' => ! $published,
            'mode' => $mode,
            'normalized_code' => $canonical->normalized_code,
            'canonical_candidate_id' => $canonical->id,
            'machine_error_code' => $ctx['existing'] ? ['id' => $ctx['existing']->id, 'code' => $ctx['existing']->code, 'title' => $ctx['existing']->title] : null,
            'published_at' => ($published ? $canonical->published_at : ($ctx['published']->first()?->published_at))?->toIso8601String(),
            'supporting_page_count' => count($ctx['pages']),
            'solution_created' => $solutionCreated,
            'references_created' => $refsCreated,
        ];
    }

    // ---------------------------------------------------------------- input handling

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function proposed(array $input): array
    {
        $clean = function ($v): ?string {
            $s = trim(str_replace(["\r\n", "\r"], "\n", (string) ($v ?? '')));

            return $s === '' ? null : $s;
        };

        return [
            'canonical_candidate_id' => (string) $input['canonical_candidate_id'],
            'title' => (string) $clean($input['title'] ?? ''),
            'description' => $clean($input['description'] ?? null),
            'operator_guidance' => $clean($input['operator_guidance'] ?? null),
            'technician_solution' => $clean($input['technician_solution'] ?? null),
        ];
    }

    /** @param  array<string, mixed>  $p */
    private function assertContent(array $p, string $code): void
    {
        if (PlaceholderTitle::isPlaceholder($p['title'], $code)) {
            throw ValidationException::withMessages(['title' => 'Review and replace the placeholder title before publishing.']);
        }
        if ($p['description'] === null && $p['operator_guidance'] === null && $p['technician_solution'] === null) {
            throw ValidationException::withMessages(['description' => 'Add at least a description, operator guidance or a technician solution before publishing.']);
        }
    }

    /** @param  array<string, mixed>  $p */
    private function contentHash(array $p): string
    {
        return hash('sha256', json_encode([$p['title'], $p['description'], $p['operator_guidance'], $p['technician_solution']], JSON_THROW_ON_ERROR));
    }

    private function norm(?string $s): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $s));
    }

    private function squash(?string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $s)));
    }

    private function rank(?string $evidence): int
    {
        return match ($evidence) {
            'HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1, default => 0,
        };
    }
}
