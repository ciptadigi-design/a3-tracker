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
 * V1.8 / V1.8.1 - Group-aware canonical review and SINGLE-CODE publish, with technician SOLUTION
 * VARIANTS.
 *
 * The review-to-publish unit for PDF-derived knowledge is the normalized error-code GROUP, not a
 * candidate row. One group publishes as exactly ONE logical error-code publication in ONE
 * transaction:
 *
 *   ONE machine_error_code       <-  the reviewer's canonical title/description/operator guidance
 *   0..N maintenance_error_solutions rows  <-  the reviewer's technician solution(s), each
 *                                     optionally labelled with the hardware/accessory/model
 *                                     context it applies to (V1.8.1)
 *   N maintenance_document_references     <-  one per unique SUPPORTING source page (provenance)
 *
 * Why not the legacy per-candidate MaintenanceKnowledgePublishService: it upserts machine_error_codes
 * by (account, model, code) with last-writer-wins, so publishing several occurrences of one code
 * would let candidate order decide the published text. Here the reviewer's explicit canonical
 * selection and authored content decide, and the supporting occurrences are provenance only.
 * Nothing here calls that service per occurrence, and legacy candidate-level publish is refused
 * for PDF-derived candidates (see the controller).
 *
 * V1.8.1 - SOLUTION VARIANTS. Read-only investigation of the real Production document (code
 * C-1127, and confirmed as a recurring pattern across 20+ other codes, not a one-off) found that a
 * single error code can have genuinely different, mutually-exclusive technician procedures
 * depending on which optional accessory/hardware is installed. maintenance_error_solutions was
 * already a multi-row table (unique on machine_error_code_id + step_number); it only lacked a way
 * to say WHICH context a row applies to. `applicability_label` (nullable) is that label. A
 * "solution variant identity" is (normalized applicability_label, normalized instruction) - see
 * classifySolution(): same instruction under a different label is a different, legitimate variant;
 * the same label with materially different instruction is a CONFLICT, never silently resolved.
 *
 * No migration was needed for the canonical/group mechanics: the proposed content travels in the
 * (token-bound) request; the server recomputes every fact it relies on (collision, supporting
 * pages, existing record, existing solution variants) at preview AND at publish, inside a
 * transaction that row-locks the group and the catalog record. Publishing marks ONLY the canonical
 * row (APPROVED + published_at); supporting occurrences and all candidate text are never rewritten.
 *
 * CANONICAL OCCURRENCE IS IMMUTABLE ONCE A GROUP IS PUBLISHED (V1.8.1): the first publish fixes
 * which occurrence anchors the group's provenance; a later request naming a different occurrence as
 * canonical is refused (CANONICAL_MISMATCH) rather than silently re-anchoring the group.
 *
 * IDEMPOTENCY is decided before staleness: if the group is already published and the requested
 * state is byte-identical to what is already in the catalog (shared fields, every solution
 * variant, every reference), the call succeeds without writing - a double click or a network retry
 * is not a conflict. A request that only ADDS a new labelled variant, or updates the shared fields
 * with explicit confirmation, is a legitimate, allowed mutation of an already-published group - see
 * plan()'s $groupOutcome. A request containing a solution CONFLICT is refused in full: nothing is
 * partially published.
 */
final class KnowledgeGroupPublisher
{
    public const PURPOSE = 'group_publish';

    public const TTL_SECONDS = 900;

    /** Supporting-page list bound in a response; a range wider than this never occurs for real candidates. */
    private const PAGE_LIST_LIMIT = 500;

    private const MAX_RANGE_SPAN = 20;

    /** A client input error, not a domain limit - real groups never need anywhere near this many variants. */
    public const MAX_SOLUTIONS = 20;

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
            'existing_solution_labels' => $ctx['solutions']->pluck('applicability_label')->map(fn ($l) => $l ?? 'General')->unique()->values()->all(),
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
            'published' => $ctx['published']->isNotEmpty(),
            'group_outcome' => $plan['group_outcome'],
            'collision_status' => $plan['collision'],
            'requires_update_confirmation' => $plan['requires_update_confirmation'],
            // Echoed back exactly as submitted (the reviewer's own just-typed content, never existing
            // catalog prose) so the frontend can detect a stale preview by simple equality, the same way
            // it already does for title/description/operator_guidance.
            'proposed' => ['title' => $proposed['title'], 'description' => $proposed['description'], 'operator_guidance' => $proposed['operator_guidance'], 'solutions' => $proposed['solutions']],
            'existing' => $ctx['existing'] ? ['id' => $ctx['existing']->id, 'title' => $ctx['existing']->title, 'changed_fields' => $plan['changed_fields']] : null,
            'provenance' => [
                'occurrence_count' => $ctx['entries']->count(),
                'supporting_occurrence_count' => $ctx['supporting']->count(),
                'excluded_rejected_count' => $ctx['entries']->count() - $ctx['supporting']->count(),
                'supporting_page_count' => count($ctx['pages']),
                'supporting_pages' => array_slice($ctx['pages'], 0, self::PAGE_LIST_LIMIT),
            ],
            // Per-proposed-variant planning: safe to expose (labels + a status the reviewer already knows,
            // since they typed both sides), never a hash, never unrelated candidate/manual text.
            'solutions' => array_map(fn ($sp) => [
                'applicability_label' => $sp['applicability_label'],
                'status' => $sp['status'],
                'existing_instruction' => $sp['status'] === 'CONFLICT' ? $sp['existing_instruction'] : null,
            ], $plan['solution_plans']),
            'mutation' => [
                'error_code' => $plan['error_code'],
                'solutions_new' => $plan['solutions_new'],
                'solutions_identical' => $plan['solutions_identical'],
                'solutions_conflict' => $plan['solutions_conflict'],
                'references_to_create' => count($plan['pages_to_create']),
                'references_existing' => count($ctx['pages']) - count($plan['pages_to_create']),
            ],
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

            // 1. Idempotency first: a retry of an already-completed, byte-identical publication is a
            // success, never a conflict - regardless of how many times it is retried.
            if ($plan['already_published'] === 'IDENTICAL') {
                return $this->result($ctx, $canonical, false, 'unchanged', 0, 0, 0);
            }
            // 2. A solution CONFLICT fails the WHOLE request - never a partial publish of only the
            // non-conflicting variants.
            if ($plan['blocked_reason'] !== null) {
                throw new ConflictHttpException('['.$plan['blocked_reason'].'] This code group cannot be published in its current state.');
            }
            // 3. Staleness: the group or the existing catalog record (including its solution variants)
            // changed since this exact preview was issued.
            if (PreviewToken::isExpired($claims) || ! hash_equals((string) ($claims['s'] ?? ''), $ctx['fingerprint'])) {
                throw new ConflictHttpException('[STALE_PREVIEW] The group or the existing catalog record changed after the preview. Nothing was published; preview again.');
            }
            // 4. An existing catalog record's SHARED fields are never touched without the reviewer's
            // explicit awareness - published or not. Adding a new labelled variant alone never requires
            // this (see plan(): requires_update_confirmation is about changed_fields only).
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

            // Insert only the NEW_VARIANT proposals, in the reviewer's submitted order, with deterministic
            // consecutive step_number values after the current max - IDENTICAL proposals consume no
            // sequence number. Safe under concurrent publish attempts for the SAME code because $errorCode
            // was resolved through context()'s lockForUpdate() when it already existed; for a brand-new
            // code the (account, model, code) unique index is the existing, already-relied-upon race guard.
            $solutionsAdded = 0;
            $next = (int) MaintenanceErrorSolution::where('machine_error_code_id', $errorCode->id)->max('step_number') + 1;
            foreach ($plan['solution_plans'] as $sp) {
                if ($sp['status'] !== 'NEW_VARIANT') {
                    continue;
                }
                MaintenanceErrorSolution::create([
                    'machine_error_code_id' => $errorCode->id, 'step_number' => $next, 'applicability_label' => $sp['applicability_label'],
                    'instruction' => $sp['instruction'], 'requires_technician' => true, 'created_by' => $actor->id,
                ]);
                $next++;
                $solutionsAdded++;
            }
            $solutionsSkipped = $plan['solutions_identical'];

            // One reference per unique supporting page not already referenced for this document + code. Page numbers only.
            $refsCreated = 0;
            $title = mb_substr($proposed['title'], 0, 200);
            foreach ($plan['pages_to_create'] as $page) {
                MaintenanceDocumentReference::create(['document_id' => $import->document_id, 'machine_error_code_id' => $errorCode->id, 'reference_type' => 'error_code', 'page_number' => $page, 'section_title' => $title, 'created_by' => $actor->id]);
                $refsCreated++;
            }

            // Mark ONLY the canonical occurrence: the reviewer's explicit publish is the approval. Supporting
            // rows stay as they are. A repeat publish against the same, already-published canonical is a
            // harmless no-op update (still the same row, still APPROVED, published_at left as first set).
            if ($canonical->published_at === null) {
                $canonical->update(['status' => 'APPROVED', 'approved_by' => $canonical->approved_by ?? $actor->id, 'published_at' => now()]);
            }

            $mode = $created ? 'created' : ($plan['changed_fields'] !== [] ? 'updated' : ($solutionsAdded > 0 || $refsCreated > 0 ? 'variants_added' : 'unchanged'));
            $audit->record($actor, 'maintenance_knowledge_group.published', 'maintenance_document_import', $import->id, $accountId, [
                'changes' => [
                    'normalized_code' => ['before' => null, 'after' => $code],
                    'canonical_candidate_id' => ['before' => null, 'after' => $canonical->id],
                    'occurrence_count' => ['before' => null, 'after' => $ctx['entries']->count()],
                    'supporting_page_count' => ['before' => null, 'after' => count($ctx['pages'])],
                    'collision_status' => ['before' => null, 'after' => $plan['collision']],
                    'publish_mode' => ['before' => null, 'after' => $mode],
                    'solutions_proposed' => ['before' => null, 'after' => count($plan['solution_plans'])],
                    'solutions_added' => ['before' => null, 'after' => $solutionsAdded],
                    'solutions_skipped_identical' => ['before' => null, 'after' => $solutionsSkipped],
                    'applicability_labels' => ['before' => null, 'after' => mb_substr(implode(',', array_map(fn ($sp) => $sp['applicability_label'] ?? 'General', array_filter($plan['solution_plans'], fn ($sp) => $sp['status'] === 'NEW_VARIANT'))), 0, 300)],
                    'publication_fingerprint' => ['before' => null, 'after' => substr(hash('sha256', $this->contentHash($proposed).'|'.$canonical->id), 0, 16)],
                ],
            ]);

            app(MaintenanceKnowledgePublishService::class)->advanceImportStatusIfComplete($import);

            return $this->result($ctx, $canonical->fresh(), true, $mode, $solutionsAdded, $solutionsSkipped, $refsCreated, $errorCode);
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
        $sq = MaintenanceErrorSolution::where('machine_error_code_id', $existing?->id)->orderBy('step_number');
        $solutions = $existing ? ($lock ? $sq->lockForUpdate() : $sq)->get(['step_number', 'applicability_label', 'instruction']) : collect();
        $refPages = $existing
            ? MaintenanceDocumentReference::where('document_id', $import->document_id)->where('machine_error_code_id', $existing->id)->whereNotNull('page_number')->pluck('page_number')->map(fn ($p) => (int) $p)->unique()->values()->all()
            : [];
        $refCount = $existing ? MaintenanceDocumentReference::where('machine_error_code_id', $existing->id)->count() : 0;

        $entriesDigest = hash('sha256', $entries->map(fn ($e) => implode(':', [$e->id, $e->status, $e->published_at ? 'P' : '-', $e->source_page_start, $e->source_page_end]))->implode('|'));
        // Every existing solution row's (label, instruction) pair is folded in - not just a count/max
        // step - so an out-of-band edit to a solution's text (not just adding/removing a row) also
        // invalidates a stale preview.
        $solutionsDigest = $solutions->map(fn ($s) => ($s->applicability_label ?? '').'='.$this->squash($s->instruction))->sort()->values()->implode('|');
        $existingDigest = $existing
            ? hash('sha256', json_encode([$existing->id, $existing->title, $existing->manufacturer_description, $existing->operator_description, $existing->official_solution, $existing->solution_summary, (bool) $existing->is_active, $solutionsDigest, $refCount]))
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

        $solutionPlans = array_map(fn ($s) => $this->classifySolution($ctx['solutions'], $s), $proposed['solutions']);
        $solutionsNew = count(array_filter($solutionPlans, fn ($sp) => $sp['status'] === 'NEW_VARIANT'));
        $solutionsIdentical = count(array_filter($solutionPlans, fn ($sp) => $sp['status'] === 'IDENTICAL'));
        $solutionsConflict = count(array_filter($solutionPlans, fn ($sp) => $sp['status'] === 'CONFLICT'));

        $pagesToCreate = array_values(array_diff($ctx['pages'], $ctx['ref_pages']));

        // The canonical occurrence is fixed at first publish; a later request naming a different
        // occurrence is a hard conflict, never a silent re-anchor.
        $canonicalMismatch = $ctx['published']->isNotEmpty()
            && ! ($ctx['published']->count() === 1 && $ctx['published']->first()->id === $proposed['canonical_candidate_id']);

        $identical = $ctx['published']->isNotEmpty() && ! $canonicalMismatch
            && $existing !== null && $changed === [] && $solutionsConflict === 0 && $solutionsNew === 0 && $pagesToCreate === [];

        $blocked = null;
        if ($canonicalMismatch) {
            $blocked = 'CANONICAL_MISMATCH';
        } elseif ($solutionsConflict > 0) {
            $blocked = 'SOLUTION_CONFLICT';
        } elseif (! $identical && $canonical->status === 'REJECTED') {
            $blocked = 'CANONICAL_REJECTED';
        }

        $groupOutcome = match (true) {
            $blocked === 'SOLUTION_CONFLICT' || $blocked === 'CANONICAL_MISMATCH' => 'CONFLICT',
            $existing === null => 'CREATE',
            $identical => 'NO_CHANGE',
            $changed !== [] => 'UPDATE_SHARED',
            $solutionsNew > 0 || $pagesToCreate !== [] => 'ADD_VARIANTS',
            default => 'NO_CHANGE',
        };

        return [
            'collision' => $collision,
            'requires_update_confirmation' => $existing !== null && ! $identical && $changed !== [],
            'changed_fields' => $changed,
            'error_code' => $existing === null ? 'CREATE' : ($changed === [] ? 'NONE' : 'UPDATE'),
            'solution_plans' => $solutionPlans,
            'solutions_new' => $solutionsNew,
            'solutions_identical' => $solutionsIdentical,
            'solutions_conflict' => $solutionsConflict,
            'pages_to_create' => $pagesToCreate,
            'already_published' => $identical ? 'IDENTICAL' : null,
            'group_outcome' => $groupOutcome,
            'blocked_reason' => $blocked,
        ];
    }

    /**
     * One proposed (applicability_label, instruction) pair against the code's EXISTING solution rows.
     * Identity is the (normalized label, normalized instruction) pair - see the class docblock.
     *
     * @param  Collection<int, MaintenanceErrorSolution>  $existingSolutions
     * @param  array{applicability_label: ?string, instruction: string}  $proposed
     * @return array{applicability_label: ?string, instruction: string, status: string, existing_instruction: ?string}
     */
    private function classifySolution(Collection $existingSolutions, array $proposed): array
    {
        $label = $proposed['applicability_label'];
        $needle = $this->squash($proposed['instruction']);
        $sameLabel = $existingSolutions->filter(fn ($s) => $s->applicability_label === $label);

        $status = match (true) {
            $sameLabel->isEmpty() => 'NEW_VARIANT',
            $sameLabel->contains(fn ($s) => $this->squash($s->instruction) === $needle) => 'IDENTICAL',
            default => 'CONFLICT',
        };

        return [
            'applicability_label' => $label,
            'instruction' => $proposed['instruction'],
            'status' => $status,
            'existing_instruction' => $status === 'CONFLICT' ? $sameLabel->first()->instruction : null,
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

    /** @param  array<string, mixed>  $ctx */
    private function result(array $ctx, MaintenanceKnowledgeEntry $canonical, bool $published, string $mode, int $solutionsAdded, int $solutionsSkipped, int $refsCreated, ?MachineErrorCode $errorCode = null): array
    {
        $ec = $errorCode ?? $ctx['existing'];

        return [
            'published' => $published,
            'already_published' => ! $published,
            'mode' => $mode,
            'normalized_code' => $canonical->normalized_code,
            'canonical_candidate_id' => $canonical->id,
            'machine_error_code' => $ec ? ['id' => $ec->id, 'code' => $ec->code, 'title' => $ec->title] : null,
            'published_at' => ($published ? $canonical->published_at : ($ctx['published']->first()?->published_at))?->toIso8601String(),
            'supporting_page_count' => count($ctx['pages']),
            'solution_created' => $solutionsAdded > 0,
            'solutions_added' => $solutionsAdded,
            'solutions_skipped' => $solutionsSkipped,
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
            'solutions' => $this->solutionProposals($input, $clean),
        ];
    }

    /**
     * V1.8.1 - accepts the new `technician_solutions[]` array shape, and stays backward compatible
     * with the V1.8 single `technician_solution` string (wrapped as one unlabelled proposal). If a
     * caller somehow sends both, the array takes precedence - the legacy field is documented as the
     * single-solution shorthand for callers who have not adopted the array, not a second input.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{applicability_label: ?string, instruction: string}>
     */
    private function solutionProposals(array $input, \Closure $clean): array
    {
        $raw = $input['technician_solutions'] ?? null;
        if (! is_array($raw) || $raw === []) {
            $legacy = $clean($input['technician_solution'] ?? null);

            return $legacy === null ? [] : [['applicability_label' => null, 'instruction' => $legacy]];
        }

        $out = [];
        $seenLabels = [];
        foreach ($raw as $item) {
            $instruction = $clean(is_array($item) ? ($item['instruction'] ?? null) : null);
            if ($instruction === null) {
                continue; // a blank instruction is not a real proposal - never a validation trap for an empty trailing row
            }
            $label = $clean(is_array($item) ? ($item['applicability_label'] ?? null) : null);
            $labelKey = $label === null ? "\0null" : mb_strtolower($label);
            if (isset($seenLabels[$labelKey])) {
                throw ValidationException::withMessages(['technician_solutions' => 'Each technician solution must have a distinct applicability (or leave it blank for at most one general solution).']);
            }
            $seenLabels[$labelKey] = true;
            $out[] = ['applicability_label' => $label, 'instruction' => $instruction];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $p */
    private function assertContent(array $p, string $code): void
    {
        if (PlaceholderTitle::isPlaceholder($p['title'], $code)) {
            throw ValidationException::withMessages(['title' => 'Review and replace the placeholder title before publishing.']);
        }
        if ($p['description'] === null && $p['operator_guidance'] === null && $p['solutions'] === []) {
            throw ValidationException::withMessages(['description' => 'Add at least a description, operator guidance or a technician solution before publishing.']);
        }
    }

    /** @param  array<string, mixed>  $p */
    private function contentHash(array $p): string
    {
        $solutions = array_map(fn ($s) => [$s['applicability_label'], $s['instruction']], $p['solutions']);

        return hash('sha256', json_encode([$p['title'], $p['description'], $p['operator_guidance'], $solutions], JSON_THROW_ON_ERROR));
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
