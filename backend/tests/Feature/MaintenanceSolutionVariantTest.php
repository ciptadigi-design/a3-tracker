<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MachineErrorCode;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentReference;
use App\Models\MaintenanceErrorSolution;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Maintenance V1.8.1 - Solution Variant Publishing.
 *
 * Real-document motivation (read-only Production investigation, never reproduced here): a single
 * error code can have genuinely different technician procedures depending on which optional
 * accessory/hardware is installed. This is a recurring manual pattern (confirmed across 20+ real
 * codes), not a one-off. maintenance_error_solutions was already a multi-row table; it only lacked
 * a label saying WHICH context a row applies to (`applicability_label`, additive, nullable - see
 * the V1.8.1 migration). A solution's identity is (normalized applicability_label, normalized
 * instruction): the same instruction under a different label is a different, legitimate variant;
 * the same label with materially different instruction is a CONFLICT, never silently resolved.
 *
 * All fixtures here are entirely synthetic (code C-9001, "Synthetic Finisher Error", "Accessory
 * A/B" / "Accessory C") - no real Konica manual text anywhere in this file.
 */
class MaintenanceSolutionVariantTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'C-9001';

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $import = $this->makeImport($home);

        return ['home' => $home, 'import' => $import];
    }

    private function makeImport(Account $account): MaintenanceDocumentImport
    {
        $document = MaintenanceDocument::create(['account_id' => $account->id, 'title' => 'Fixture Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        return MaintenanceDocumentImport::create(['document_id' => $document->id, 'import_type' => 'PDF_EXTRACTION', 'status' => 'REVIEW', 'processing_version' => 2, 'candidate_count' => 0]);
    }

    private function member(Account $account, string $role = 'owner'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function entry(MaintenanceDocumentImport $import, string $evidence, int $start, ?int $end = null): MaintenanceKnowledgeEntry
    {
        return MaintenanceKnowledgeEntry::create([
            'import_id' => $import->id, 'knowledge_type' => 'ERROR_CODE', 'normalized_code' => self::CODE, 'code' => self::CODE,
            'title' => 'Error Code '.self::CODE, 'description' => 'Synthetic excerpt p'.$start, 'evidence' => $evidence, 'collision_status' => 'NEW',
            'source_page_start' => $start, 'source_page_end' => $end ?? $start, 'status' => 'DRAFT',
        ]);
    }

    /** One canonical occurrence, plenty of body evidence, no rejected noise - the variant mechanics are the focus here. */
    private function group(array $f): MaintenanceKnowledgeEntry
    {
        $canonical = $this->entry($f['import'], 'HIGH', 200);
        $this->entry($f['import'], 'MEDIUM', 210);

        return $canonical;
    }

    private function url(string $importId, string $tail): string
    {
        return "/api/v1/maintenance/document-imports/{$importId}/code-groups/".self::CODE."/{$tail}";
    }

    /** @param  list<array{applicability_label: ?string, instruction: string}>  $solutions */
    private function payload(string $canonicalId, array $solutions = [], array $o = []): array
    {
        return array_merge([
            'canonical_candidate_id' => $canonicalId,
            'title' => 'Synthetic Finisher Error',
            'description' => 'Synthetic detected condition for automated tests.',
            'operator_guidance' => 'Synthetic operator guidance.',
            'technician_solutions' => $solutions,
        ], $o);
    }

    private function variant(string $label, string $instruction): array
    {
        return ['applicability_label' => $label, 'instruction' => $instruction];
    }

    private function preview(User $u, string $importId, array $payload)
    {
        return $this->actingAs($u)->postJson($this->url($importId, 'publish-preview'), $payload);
    }

    private function publish(User $u, string $importId, array $payload, ?string $token, array $extra = [])
    {
        return $this->actingAs($u)->postJson($this->url($importId, 'publish'), $payload + ['confirmation_token' => $token] + $extra);
    }

    private function previewAndPublish(User $u, string $importId, array $payload, array $extra = [])
    {
        $token = $this->preview($u, $importId, $payload)->assertOk()->json('data.confirmation_token');

        return $this->publish($u, $importId, $payload, $token, $extra);
    }

    // --- 3. new canonical code + one labeled solution ---

    public function test_a_new_code_publishes_with_one_labelled_solution(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]);

        $r = $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk()->json('data');

        $this->assertSame('created', $r['mode']);
        $this->assertSame(1, $r['solutions_added']);
        $this->assertSame(0, $r['solutions_skipped']);
        $this->assertTrue($r['solution_created']);
        $code = MachineErrorCode::where('code', self::CODE)->sole();
        $solution = MaintenanceErrorSolution::where('machine_error_code_id', $code->id)->sole();
        $this->assertSame('Accessory A/B', $solution->applicability_label);
        $this->assertSame('Synthetic technician procedure A.', $solution->instruction);
        $this->assertSame(1, $solution->step_number);
    }

    // --- 4/5. two labeled solutions in ONE publish request, sharing one machine_error_code_id ---

    public function test_two_labelled_variants_publish_in_one_request_and_share_one_error_code(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory C', 'Synthetic technician procedure B.'),
        ]);

        $r = $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk()->json('data');

        $this->assertSame(2, $r['solutions_added']);
        $this->assertSame(1, MachineErrorCode::where('code', self::CODE)->count());
        $code = MachineErrorCode::where('code', self::CODE)->sole();
        $solutions = MaintenanceErrorSolution::where('machine_error_code_id', $code->id)->orderBy('step_number')->get();
        $this->assertCount(2, $solutions);
        $this->assertTrue($solutions->every(fn ($s) => $s->machine_error_code_id === $code->id));
    }

    // --- 6. applicability labels persist correctly ---

    public function test_applicability_labels_persist_exactly_including_null_for_unlabelled(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            ['applicability_label' => null, 'instruction' => 'A generally applicable synthetic procedure.'],
        ]);

        $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk();

        $labels = MaintenanceErrorSolution::orderBy('step_number')->pluck('applicability_label')->all();
        $this->assertSame(['Accessory A/B', null], $labels);
    }

    public function test_applicability_label_whitespace_is_trimmed_and_blank_normalizes_to_null(): void
    {
        $solution = MaintenanceErrorSolution::create(['machine_error_code_id' => (MachineErrorCode::create(['code' => self::CODE, 'title' => 't']))->id, 'step_number' => 1, 'applicability_label' => '  Accessory A/B  ', 'instruction' => 'x']);
        $this->assertSame('Accessory A/B', $solution->applicability_label);

        $blank = MaintenanceErrorSolution::create(['machine_error_code_id' => $solution->machine_error_code_id, 'step_number' => 2, 'applicability_label' => '   ', 'instruction' => 'x']);
        $this->assertNull($blank->applicability_label);

        $empty = MaintenanceErrorSolution::create(['machine_error_code_id' => $solution->machine_error_code_id, 'step_number' => 3, 'applicability_label' => '', 'instruction' => 'x']);
        $this->assertNull($empty->applicability_label);
    }

    // --- 7/22. deterministic step_number ordering; sequence never duplicated on retry ---

    public function test_step_numbers_are_deterministic_and_follow_submission_order(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [
            $this->variant('Zebra', 'Synthetic procedure Z.'),
            $this->variant('Accessory A/B', 'Synthetic procedure A.'),
        ]);

        $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk();

        $ordered = MaintenanceErrorSolution::orderBy('step_number')->pluck('applicability_label')->all();
        $this->assertSame(['Zebra', 'Accessory A/B'], $ordered, 'submission order, not alphabetical');
    }

    // --- 8/9. existing code + new labelled solution(s): ADD_VARIANTS path ---

    public function test_adding_one_new_labelled_variant_to_an_already_published_group_succeeds(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $first = $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]);
        $this->previewAndPublish($u, $f['import']->id, $first)->assertOk();

        $second = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'), // identical - skipped
            $this->variant('Accessory C', 'Synthetic technician procedure B.'),   // new
        ]);
        $preview = $this->preview($u, $f['import']->id, $second)->assertOk()->json('data');
        $this->assertTrue($preview['published']);
        $this->assertTrue($preview['can_publish']);
        $this->assertNull($preview['blocked_reason']);
        $this->assertSame('ADD_VARIANTS', $preview['group_outcome']);
        $this->assertFalse($preview['requires_update_confirmation'], 'adding a variant alone never requires shared-field confirmation');
        $this->assertSame('IDENTICAL', $preview['solutions'][0]['status']);
        $this->assertSame('NEW_VARIANT', $preview['solutions'][1]['status']);

        $r = $this->publish($u, $f['import']->id, $second, $preview['confirmation_token'])->assertOk()->json('data');

        $this->assertSame(1, $r['solutions_added']);
        $this->assertSame(1, $r['solutions_skipped']);
        $this->assertSame('variants_added', $r['mode']);
        $this->assertSame(2, MaintenanceErrorSolution::count());
        $this->assertSame(1, MachineErrorCode::count(), 'never duplicated');
    }

    public function test_adding_two_new_variants_to_an_already_published_group_appends_both_atomically(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $first = $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]);
        $this->previewAndPublish($u, $f['import']->id, $first)->assertOk();

        $second = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory C', 'Synthetic technician procedure C.'),
            $this->variant('Accessory D', 'Synthetic technician procedure D.'),
        ]);
        $r = $this->previewAndPublish($u, $f['import']->id, $second)->assertOk()->json('data');

        $this->assertSame(2, $r['solutions_added']);
        $this->assertSame(3, MaintenanceErrorSolution::count());
        $steps = MaintenanceErrorSolution::orderBy('step_number')->pluck('step_number')->all();
        $this->assertSame([1, 2, 3], $steps, 'consecutive, no gaps, no duplicates');
    }

    // --- 10/11. duplicate exact variant / retry: idempotent, no duplicate ---

    public function test_publishing_the_exact_same_variant_set_twice_is_idempotent(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory C', 'Synthetic technician procedure C.'),
        ]);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');
        $first = $this->publish($u, $f['import']->id, $payload, $token)->assertOk()->json('data');
        $countsAfterFirst = ['codes' => MachineErrorCode::count(), 'solutions' => MaintenanceErrorSolution::count(), 'refs' => MaintenanceDocumentReference::count(), 'audit' => DB::table('governance_audit_logs')->count()];

        $second = $this->publish($u, $f['import']->id, $payload, $token)->assertOk()->json('data');

        $this->assertTrue($first['published']);
        $this->assertFalse($second['published']);
        $this->assertTrue($second['already_published']);
        $this->assertSame('unchanged', $second['mode']);
        $this->assertSame($countsAfterFirst, ['codes' => MachineErrorCode::count(), 'solutions' => MaintenanceErrorSolution::count(), 'refs' => MaintenanceDocumentReference::count(), 'audit' => DB::table('governance_audit_logs')->count()]);
    }

    public function test_a_fresh_preview_and_retry_of_an_already_published_identical_group_is_also_idempotent(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]);
        $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk();
        $before = ['codes' => MachineErrorCode::count(), 'solutions' => MaintenanceErrorSolution::count()];

        $preview = $this->preview($u, $f['import']->id, $payload)->assertOk()->json('data');
        $this->assertSame('IDENTICAL', $preview['already_published']);
        $this->assertFalse($preview['can_publish']);
        $this->assertNull($preview['confirmation_token']);

        $this->assertSame($before, ['codes' => MachineErrorCode::count(), 'solutions' => MaintenanceErrorSolution::count()]);
    }

    // --- 12. same label + different instruction: CONFLICT ---

    public function test_same_label_with_materially_different_instruction_is_a_conflict(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]))->assertOk();

        $conflicting = $this->payload($canonical->id, [$this->variant('Accessory A/B', 'A completely different synthetic procedure.')]);
        $preview = $this->preview($u, $f['import']->id, $conflicting)->assertOk()->json('data');

        $this->assertFalse($preview['can_publish']);
        $this->assertSame('SOLUTION_CONFLICT', $preview['blocked_reason']);
        $this->assertSame('CONFLICT', $preview['solutions'][0]['status']);
        $this->assertSame('Synthetic technician procedure A.', $preview['solutions'][0]['existing_instruction']);
        $this->assertSame(1, MaintenanceErrorSolution::count());
    }

    // --- 13. existing NULL label + different instruction: CONFLICT ---

    public function test_existing_unlabelled_solution_with_different_instruction_is_a_conflict(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [['applicability_label' => null, 'instruction' => 'The original general procedure.']]))->assertOk();

        $conflicting = $this->payload($canonical->id, [['applicability_label' => null, 'instruction' => 'A different general procedure.']]);
        $preview = $this->preview($u, $f['import']->id, $conflicting)->assertOk()->json('data');

        $this->assertFalse($preview['can_publish']);
        $this->assertSame('SOLUTION_CONFLICT', $preview['blocked_reason']);
        $this->assertSame('CONFLICT', $preview['solutions'][0]['status']);
        $this->assertSame(1, MaintenanceErrorSolution::count(), 'no second anonymous row silently added');
    }

    // --- 14. same instruction + different label: distinct legitimate variants ---

    public function test_the_same_instruction_text_under_two_different_labels_is_two_legitimate_variants(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'The exact same synthetic procedure text.'),
            $this->variant('Accessory C', 'The exact same synthetic procedure text.'),
        ]);

        $r = $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk()->json('data');

        $this->assertSame(2, $r['solutions_added']);
        $this->assertSame(2, MaintenanceErrorSolution::count());
        $labels = MaintenanceErrorSolution::pluck('applicability_label')->sort()->values()->all();
        $this->assertSame(['Accessory A/B', 'Accessory C'], $labels);
    }

    // --- 15. mixed request: one IDENTICAL + one NEW_VARIANT -> only the new one inserted ---

    public function test_a_mixed_identical_and_new_variant_request_inserts_only_the_new_one(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]))->assertOk();

        $mixed = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory C', 'Synthetic technician procedure C.'),
        ]);
        $r = $this->previewAndPublish($u, $f['import']->id, $mixed)->assertOk()->json('data');

        $this->assertSame(1, $r['solutions_added']);
        $this->assertSame(1, $r['solutions_skipped']);
        $this->assertSame(2, MaintenanceErrorSolution::count());
        $this->assertSame(1, MaintenanceErrorSolution::where('applicability_label', 'Accessory A/B')->count(), 'not duplicated');
    }

    // --- 16. mixed request containing CONFLICT: the whole transaction fails, nothing partial ---

    public function test_a_mixed_request_with_one_conflicting_variant_fails_the_entire_publish(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]))->assertOk();
        $before = ['codes' => MachineErrorCode::count(), 'solutions' => MaintenanceErrorSolution::count(), 'refs' => MaintenanceDocumentReference::count()];

        $mixed = $this->payload($canonical->id, [
            $this->variant('Accessory C', 'A brand new, non-conflicting synthetic procedure.'),
            $this->variant('Accessory A/B', 'A conflicting different synthetic procedure.'),
        ]);
        $preview = $this->preview($u, $f['import']->id, $mixed)->assertOk()->json('data');
        $this->assertFalse($preview['can_publish']);
        $this->assertSame('SOLUTION_CONFLICT', $preview['blocked_reason']);
        $this->assertNull($preview['confirmation_token'], 'no token is issued for a blocked preview, so publish cannot even be attempted');

        $this->assertSame($before, ['codes' => MachineErrorCode::count(), 'solutions' => MaintenanceErrorSolution::count(), 'refs' => MaintenanceDocumentReference::count()], 'the non-conflicting variant was NOT partially inserted');
    }

    // --- 20. adding a variant cannot bypass the canonical shared-field conflict path ---

    public function test_a_shared_field_change_bundled_with_a_new_variant_still_requires_explicit_confirmation(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]))->assertOk();

        // Same canonical, DIFFERENT title (shared-field change) AND a new labelled variant, bundled in one request.
        $bundled = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory C', 'Synthetic technician procedure C.'),
        ], ['title' => 'A materially different title']);
        $preview = $this->preview($u, $f['import']->id, $bundled)->assertOk()->json('data');

        $this->assertTrue($preview['can_publish']);
        $this->assertTrue($preview['requires_update_confirmation'], 'the bundled shared-field change still needs explicit confirmation');
        $this->assertSame('UPDATE_SHARED', $preview['group_outcome'], 'shared-field change takes priority in the outcome label even though a variant is also proposed');

        $this->publish($u, $f['import']->id, $bundled, $preview['confirmation_token'])->assertStatus(422)->assertJsonValidationErrors('confirm_update');
        $this->assertSame(1, MaintenanceErrorSolution::count(), 'the new variant was NOT slipped in without confirmation either');
        $this->assertSame('Synthetic Finisher Error', MachineErrorCode::first()->title, 'title unchanged');

        $this->publish($u, $f['import']->id, $bundled, $preview['confirmation_token'], ['confirm_update' => true])->assertOk();
        $this->assertSame('A materially different title', MachineErrorCode::first()->title);
        $this->assertSame(2, MaintenanceErrorSolution::count(), 'now the variant is added too, together with the confirmed shared-field update');
    }

    // --- 21. stale preview detects a solution change made between preview and publish ---

    public function test_stale_preview_is_rejected_when_a_solution_variant_changes_after_preview(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]))->assertOk();

        $addC = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory C', 'Synthetic technician procedure C.'),
        ]);
        $token = $this->preview($u, $f['import']->id, $addC)->json('data.confirmation_token');

        // Someone else publishes a DIFFERENT third variant in between.
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory D', 'Synthetic technician procedure D.'),
        ]))->assertOk();
        $before = MaintenanceErrorSolution::count();

        $this->publish($u, $f['import']->id, $addC, $token)->assertStatus(409);

        $this->assertSame($before, MaintenanceErrorSolution::count(), 'the stale request added nothing');
    }

    // --- 23. audit records labels/counts/mode but never instruction prose ---

    public function test_audit_records_solution_counts_and_labels_but_never_instruction_text(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'SECRET-PROCEDURE-A-TEXT'),
            $this->variant('Accessory C', 'SECRET-PROCEDURE-C-TEXT'),
        ]);

        $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk();

        $log = DB::table('governance_audit_logs')->where('action', 'maintenance_knowledge_group.published')->sole();
        $m = json_decode($log->metadata, true)['changes'];
        $this->assertSame(2, $m['solutions_proposed']['after']);
        $this->assertSame(2, $m['solutions_added']['after']);
        $this->assertSame(0, $m['solutions_skipped_identical']['after']);
        $this->assertSame('Accessory A/B,Accessory C', $m['applicability_labels']['after']);
        $this->assertStringNotContainsString('SECRET-PROCEDURE', $log->metadata);
    }

    public function test_audit_reflects_variants_added_mode_when_only_solutions_change(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]))->assertOk();

        $this->previewAndPublish($u, $f['import']->id, $this->payload($canonical->id, [
            $this->variant('Accessory A/B', 'Synthetic technician procedure A.'),
            $this->variant('Accessory C', 'Synthetic technician procedure C.'),
        ]))->assertOk();

        // governance_audit_logs.created_at has only second precision, so two rows from the same test can
        // tie under an ORDER BY - identify the "variants_added" row by content, never by position/order.
        $logs = DB::table('governance_audit_logs')->where('action', 'maintenance_knowledge_group.published')->get();
        $this->assertCount(2, $logs);
        $modes = $logs->map(fn ($l) => json_decode($l->metadata, true)['changes']['publish_mode']['after'])->all();
        $this->assertEqualsCanonicalizing(['created', 'variants_added'], $modes);
    }

    // --- 26/27. legacy technician_solution string payload remains fully accepted ---

    public function test_the_legacy_technician_solution_string_payload_remains_accepted_and_unlabelled(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        // No technician_solutions[] at all - only the pre-V1.8.1 scalar field.
        $payload = $this->payload($canonical->id, []);
        unset($payload['technician_solutions']);
        $payload['technician_solution'] = 'A legacy-shaped synthetic technician procedure.';

        $r = $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk()->json('data');

        $this->assertSame(1, $r['solutions_added']);
        $solution = MaintenanceErrorSolution::sole();
        $this->assertNull($solution->applicability_label);
        $this->assertSame('A legacy-shaped synthetic technician procedure.', $solution->instruction);
    }

    public function test_legacy_response_shape_fields_remain_present_for_old_consumers(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($canonical->id, []);
        unset($payload['technician_solutions']);
        $payload['technician_solution'] = 'A legacy-shaped synthetic technician procedure.';

        $r = $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk()->json('data');

        // Fields an old (pre-variant) frontend/consumer already reads must still be present with the same meaning.
        foreach (['published', 'mode', 'normalized_code', 'canonical_candidate_id', 'machine_error_code', 'published_at', 'supporting_page_count', 'solution_created', 'references_created'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertTrue($r['solution_created']);
    }

    // --- authorization / tenant isolation unchanged (smoke - full matrix already covered in MaintenanceGroupPublishTest) ---

    public function test_authorization_and_tenant_isolation_are_unaffected_by_variant_support(): void
    {
        $f = $this->fixture();
        $canonical = $this->group($f);
        $viewer = $this->member($f['home'], 'viewer');
        $payload = $this->payload($canonical->id, [$this->variant('Accessory A/B', 'Synthetic technician procedure A.')]);

        $this->preview($viewer, $f['import']->id, $payload)->assertStatus(403);
        $this->assertSame(0, MachineErrorCode::count());
    }
}
