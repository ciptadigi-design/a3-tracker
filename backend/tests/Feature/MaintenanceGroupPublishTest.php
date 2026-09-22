<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentReference;
use App\Models\MaintenanceErrorSolution;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\KnowledgeReview\PlaceholderTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Maintenance V1.8 - group-aware canonical review and SINGLE-CODE publish. One code group publishes as ONE
 * logical error-code publication (one machine_error_code, at most one solution step, one reference per
 * unique supporting page) from a reviewer-chosen canonical occurrence and reviewer-authored content.
 * All fixtures are synthetic; no real manual text.
 */
class MaintenanceGroupPublishTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'C-3102';

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $import = $this->makeImport($home);

        return ['home' => $home, 'foreign' => $foreign, 'import' => $import, 'otherImport' => $this->makeImport($home), 'foreignImport' => $this->makeImport($foreign)];
    }

    private function makeImport(Account $account, string $type = 'PDF_EXTRACTION'): MaintenanceDocumentImport
    {
        $document = MaintenanceDocument::create(['account_id' => $account->id, 'title' => 'Fixture Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        return MaintenanceDocumentImport::create(['document_id' => $document->id, 'import_type' => $type, 'status' => 'REVIEW', 'processing_version' => 2, 'candidate_count' => 0]);
    }

    private function member(Account $account, string $role = 'owner'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function entry(MaintenanceDocumentImport $import, string $code, string $evidence, int $start, ?int $end = null, string $status = 'DRAFT', array $o = []): MaintenanceKnowledgeEntry
    {
        return MaintenanceKnowledgeEntry::create(array_merge([
            'import_id' => $import->id, 'knowledge_type' => 'ERROR_CODE', 'normalized_code' => $code, 'code' => $code, 'title' => 'Error Code '.$code,
            'description' => 'Synthetic excerpt for '.$code.' p'.$start, 'evidence' => $evidence, 'collision_status' => 'NEW',
            'source_page_start' => $start, 'source_page_end' => $end ?? $start, 'status' => $status,
        ], $o));
    }

    /** The group used by most tests: 5 occurrences - page 100, a two-page 100-101 (page 100 is shared), a two-page 104-105, 900, and a LOW on 33. */
    private function group(array $f): array
    {
        $i = $f['import'];

        return [
            'high' => $this->entry($i, self::CODE, 'HIGH', 100),
            'dupPage' => $this->entry($i, self::CODE, 'MEDIUM', 100, 101),
            'multi' => $this->entry($i, self::CODE, 'MEDIUM', 104, 105),
            'low' => $this->entry($i, self::CODE, 'LOW', 900),
            'toc' => $this->entry($i, self::CODE, 'LOW', 33, null, 'DRAFT', ['description' => 'toc .......... 9']),
        ];
    }

    private function url(string $importId, string $tail, string $code = self::CODE): string
    {
        return "/api/v1/maintenance/document-imports/{$importId}/code-groups/{$code}/{$tail}";
    }

    private function payload(string $canonicalId, array $o = []): array
    {
        return array_merge([
            'canonical_candidate_id' => $canonicalId,
            'title' => 'Fuser temperature sensor abnormal',
            'description' => 'The fuser thermistor reports a temperature outside its range.',
            'operator_guidance' => 'Power the machine off and on. If it repeats, call service.',
            'technician_solution' => 'Replace the fuser thermistor and check its connector.',
        ], $o);
    }

    private function preview(User $u, string $importId, array $payload, string $code = self::CODE)
    {
        return $this->actingAs($u)->postJson($this->url($importId, 'publish-preview', $code), $payload);
    }

    private function publish(User $u, string $importId, array $payload, ?string $token, array $extra = [], string $code = self::CODE)
    {
        return $this->actingAs($u)->postJson($this->url($importId, 'publish', $code), $payload + ['confirmation_token' => $token] + $extra);
    }

    /** Preview then publish with the freshly issued token. */
    private function previewAndPublish(User $u, string $importId, array $payload, array $extra = [])
    {
        $token = $this->preview($u, $importId, $payload)->assertOk()->json('data.confirmation_token');

        return $this->publish($u, $importId, $payload, $token, $extra);
    }

    private function counts(): array
    {
        return [
            'codes' => MachineErrorCode::count(), 'solutions' => MaintenanceErrorSolution::count(), 'refs' => MaintenanceDocumentReference::count(),
            'published_entries' => MaintenanceKnowledgeEntry::whereNotNull('published_at')->count(), 'audit' => DB::table('governance_audit_logs')->count(),
        ];
    }

    // --- 1/3. group detail exposes occurrences, a SUGGESTED canonical and the server-side publication facts ---

    public function test_group_detail_returns_every_occurrence_and_only_suggests_the_strongest_as_canonical(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $d = $this->actingAs($this->member($f['home']))->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE)->assertOk()->json('data');

        $this->assertCount(5, $d['occurrences']);
        $this->assertSame($g['high']->id, $d['publication']['suggested_canonical_candidate_id'], 'strongest evidence, earliest page');
        $this->assertNull($d['publication']['suggested_title'], 'a placeholder title is never suggested');
        $this->assertFalse($d['publication']['published']);
        $this->assertNull($d['publication']['canonical_candidate_id'], 'nothing is chosen until the reviewer chooses');
        $this->assertSame('NEW', $d['publication']['server_collision_status']);
        $this->assertTrue(collect($d['occurrences'])->every(fn ($o) => $o['title_is_placeholder'] === true));
    }

    public function test_a_real_detector_title_is_offered_as_a_suggestion_but_never_applied(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE, 'HIGH', 100, null, 'DRAFT', ['title' => 'Fuser thermistor open circuit']);
        $d = $this->actingAs($this->member($f['home']))->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE)->json('data');

        $this->assertSame('Fuser thermistor open circuit', $d['publication']['suggested_title']);
        $this->assertSame(0, MachineErrorCode::count());
    }

    // --- 4. the reviewer may choose a lower-evidence occurrence on purpose ---

    public function test_the_reviewer_can_intentionally_choose_a_lower_evidence_canonical_and_it_is_the_one_recorded(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);

        $this->previewAndPublish($u, $f['import']->id, $this->payload($g['low']->id))->assertOk()->assertJsonPath('data.canonical_candidate_id', $g['low']->id);

        $this->assertNotNull($g['low']->fresh()->published_at);
        $this->assertNull($g['high']->fresh()->published_at, 'the strongest occurrence was only a suggestion');
    }

    // --- 2/24/25. the canonical occurrence must belong to this group ---

    public function test_a_candidate_of_another_group_import_or_account_cannot_be_the_canonical_occurrence(): void
    {
        $f = $this->fixture();
        $this->group($f);
        $otherGroup = $this->entry($f['import'], 'C-9999', 'HIGH', 5);
        $otherImport = $this->entry($f['otherImport'], self::CODE, 'HIGH', 5);
        $foreignRow = $this->entry($f['foreignImport'], self::CODE, 'HIGH', 5);
        $u = $this->member($f['home']);

        foreach ([$otherGroup->id, $otherImport->id, $foreignRow->id, '11111111-1111-1111-1111-111111111111'] as $bad) {
            $this->preview($u, $f['import']->id, $this->payload($bad))->assertStatus(422)->assertJsonValidationErrors('canonical_candidate_id');
        }
        $this->assertSame(0, MachineErrorCode::count());
    }

    // --- 5/6. placeholder guard ---

    public function test_a_placeholder_title_blocks_preview_and_publish_but_a_human_title_containing_the_code_is_fine(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);

        foreach (['Error Code C-3102', 'error code c-3102', 'C-3102', 'Error Code C3102', 'Error code', '   ', 'C - 3102'] as $bad) {
            $r = $this->preview($u, $f['import']->id, $this->payload($g['high']->id, ['title' => $bad]));
            $r->assertStatus(422);
        }
        $this->preview($u, $f['import']->id, $this->payload($g['high']->id, ['title' => 'Error Code C-3102']))->assertJsonPath('errors.title.0', 'Review and replace the placeholder title before publishing.');
        $this->publish($u, $f['import']->id, $this->payload($g['high']->id, ['title' => 'Error Code C-3102']), 'x.y')->assertStatus(422)->assertJsonValidationErrors('title');

        foreach (['C-3102 Fuser temperature abnormal', 'Fuser error (C-3102)', 'Fuser temperature abnormal'] as $good) {
            $this->preview($u, $f['import']->id, $this->payload($g['high']->id, ['title' => $good]))->assertOk();
        }
        $this->assertSame(0, MachineErrorCode::count());
    }

    public function test_placeholder_detection_follows_the_detector_convention_for_any_code(): void
    {
        $this->assertTrue(PlaceholderTitle::isPlaceholder('Error Code C-0001', 'C-0001'));
        $this->assertTrue(PlaceholderTitle::isPlaceholder('Error Code C-4714', 'C-0001'), 'any code token is stripped, not only this group\'s');
        $this->assertTrue(PlaceholderTitle::isPlaceholder(null, 'C-0001'));
        $this->assertFalse(PlaceholderTitle::isPlaceholder('Error Code C-0001 fuser jam', 'C-0001'));
        $this->assertFalse(PlaceholderTitle::isPlaceholder('Paper feed misfeed', 'C-0001'));
    }

    public function test_content_beyond_the_title_is_required(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);

        $this->preview($u, $f['import']->id, $this->payload($g['high']->id, ['description' => null, 'operator_guidance' => '   ', 'technician_solution' => null]))->assertStatus(422)->assertJsonValidationErrors('description');
        $this->preview($u, $f['import']->id, $this->payload($g['high']->id, ['description' => null, 'operator_guidance' => null]))->assertOk(); // a technician solution alone is enough
    }

    // --- 7/8/9/10. preview: no mutation, correct provenance ---

    public function test_preview_reports_supporting_pages_deduplicated_and_multi_page_ranges_and_mutates_nothing(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $before = $this->counts();
        $digest = MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'published_at', 'updated_at'])->toArray();

        $d = $this->preview($u, $f['import']->id, $this->payload($g['high']->id))->assertOk()->json('data');

        // pages 33, 100 (shared by two occurrences), 101, 104 + 105 (a two-page occurrence), 900 => 6 unique pages.
        $this->assertSame([33, 100, 101, 104, 105, 900], $d['provenance']['supporting_pages']);
        $this->assertSame(6, $d['provenance']['supporting_page_count']);
        $this->assertSame(5, $d['provenance']['supporting_occurrence_count']);
        $this->assertSame(0, $d['provenance']['excluded_rejected_count']);
        $this->assertSame('CREATE', $d['mutation']['error_code']);
        $this->assertSame(1, $d['mutation']['solutions_new']);
        $this->assertSame(0, $d['mutation']['solutions_identical']);
        $this->assertSame(0, $d['mutation']['solutions_conflict']);
        $this->assertSame(6, $d['mutation']['references_to_create']);
        $this->assertSame('NEW', $d['collision_status']);
        $this->assertFalse($d['requires_update_confirmation']);
        $this->assertTrue($d['can_publish']);
        $this->assertNotEmpty($d['confirmation_token']);
        $this->assertSame(100, $d['canonical_source_page']);

        $this->assertSame($before, $this->counts());
        $this->assertSame($digest, MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'published_at', 'updated_at'])->toArray());
        $this->assertStringNotContainsString('/home/', json_encode($d));
    }

    public function test_rejected_occurrences_are_not_supporting_evidence(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $g['toc']->update(['status' => 'REJECTED']);
        $g['low']->update(['status' => 'REJECTED']);

        $d = $this->preview($this->member($f['home']), $f['import']->id, $this->payload($g['high']->id))->assertOk()->json('data');

        $this->assertSame([100, 101, 104, 105], $d['provenance']['supporting_pages']);
        $this->assertSame(2, $d['provenance']['excluded_rejected_count']);
    }

    // --- 11/12/13/14/15/16/17. NEW publish: exactly one logical publication ---

    public function test_publishing_a_new_group_creates_exactly_one_error_code_one_solution_and_one_reference_per_page(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);

        $r = $this->previewAndPublish($u, $f['import']->id, $this->payload($g['multi']->id))->assertOk()->json('data');

        $this->assertTrue($r['published']);
        $this->assertFalse($r['already_published']);
        $this->assertSame('created', $r['mode']);
        $this->assertSame(6, $r['references_created']);
        $this->assertTrue($r['solution_created']);

        $this->assertSame(1, MachineErrorCode::count(), 'five occurrences, ONE error code');
        $code = MachineErrorCode::first();
        $this->assertSame(self::CODE, $code->code);
        $this->assertSame('Fuser temperature sensor abnormal', $code->title);
        $this->assertSame('The fuser thermistor reports a temperature outside its range.', $code->manufacturer_description);
        $this->assertSame('Power the machine off and on. If it repeats, call service.', $code->operator_description);
        $this->assertSame($f['home']->id, $code->account_id);
        $this->assertSame($f['import']->document_id, $code->source_document_id);
        $this->assertTrue($code->is_active);

        $this->assertSame(1, MaintenanceErrorSolution::count());
        $solution = MaintenanceErrorSolution::first();
        $this->assertSame('Replace the fuser thermistor and check its connector.', $solution->instruction);
        $this->assertTrue($solution->requires_technician);
        $this->assertSame(1, $solution->step_number);

        $pages = MaintenanceDocumentReference::where('machine_error_code_id', $code->id)->orderBy('page_number')->get();
        $this->assertSame([33, 100, 101, 104, 105, 900], $pages->pluck('page_number')->all());
        $this->assertTrue($pages->every(fn ($p) => $p->document_id === $f['import']->document_id && $p->reference_type === 'error_code' && $p->section_title === 'Fuser temperature sensor abnormal'));
        $this->assertNull($pages->first()->notes, 'no extracted page text in a reference');
    }

    public function test_only_the_canonical_row_is_marked_published_and_supporting_occurrences_are_untouched(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);

        $this->previewAndPublish($u, $f['import']->id, $this->payload($g['high']->id))->assertOk();

        $canonical = $g['high']->fresh();
        $this->assertSame('APPROVED', $canonical->status);
        $this->assertNotNull($canonical->published_at);
        $this->assertSame($u->id, $canonical->approved_by);
        foreach (['dupPage', 'multi', 'low', 'toc'] as $k) {
            $row = $g[$k]->fresh();
            $this->assertSame('DRAFT', $row->status);
            $this->assertNull($row->published_at);
            $this->assertSame($g[$k]->description, $row->description, 'candidate provenance text is never rewritten');
        }
        $this->assertSame(1, MaintenanceKnowledgeEntry::whereNotNull('published_at')->count());

        // The V1.7.2 read model now derives PUBLISHED for the group, with no per-row faking.
        $list = $this->actingAs($u)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups")->json('data.data');
        $this->assertSame('PUBLISHED', collect($list)->firstWhere('normalized_code', self::CODE)['review_state']);
        $detail = $this->actingAs($u)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE)->json('data.publication');
        $this->assertTrue($detail['published']);
        $this->assertSame($g['high']->id, $detail['canonical_candidate_id']);
    }

    public function test_candidate_order_never_decides_the_published_text(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        // Give every occurrence a different detector title; the reviewer's content must win no matter which is canonical.
        foreach ($g as $k => $row) {
            $row->update(['title' => 'Detector heading '.$k]);
        }

        $this->previewAndPublish($u, $f['import']->id, $this->payload($g['toc']->id, ['title' => 'Reviewer authored title']))->assertOk();

        $this->assertSame('Reviewer authored title', MachineErrorCode::first()->title);
        $this->assertSame(1, MachineErrorCode::count());
    }

    public function test_the_code_is_fixed_by_the_group_and_cannot_be_changed_through_the_form(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $token = $this->preview($u, $f['import']->id, $this->payload($g['high']->id))->json('data.confirmation_token');

        $this->publish($u, $f['import']->id, $this->payload($g['high']->id), $token, ['code' => 'C-0001', 'normalized_code' => 'C-0001'])->assertOk();

        $this->assertSame([self::CODE], MachineErrorCode::pluck('code')->all());
    }

    public function test_a_group_without_a_technician_solution_creates_no_solution_step(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);

        $r = $this->previewAndPublish($this->member($f['home']), $f['import']->id, $this->payload($g['high']->id, ['technician_solution' => null, 'operator_guidance' => null]))->assertOk()->json('data');

        $this->assertFalse($r['solution_created']);
        $this->assertSame(0, MaintenanceErrorSolution::count());
        $this->assertNull(MachineErrorCode::first()->operator_description);
    }

    public function test_the_code_is_scoped_to_the_imports_machine_model_not_just_the_account(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        // A record for the same code under a DIFFERENT scope (another account) is not a collision.
        MachineErrorCode::create(['account_id' => $f['foreign']->id, 'code' => self::CODE, 'title' => 'Foreign record', 'manufacturer_description' => 'x']);

        $d = $this->preview($this->member($f['home']), $f['import']->id, $this->payload($g['high']->id))->assertOk()->json('data');

        $this->assertSame('NEW', $d['collision_status']);
        $this->assertNull($d['existing']);
    }

    public function test_the_import_machine_model_scopes_the_catalog_lookup_and_the_new_record(): void
    {
        $f = $this->fixture();
        $maker = Manufacturer::create(['account_id' => $f['home']->id, 'code' => 'MK', 'name' => 'Maker', 'is_active' => true]);
        $modelA = MachineModel::create(['account_id' => $f['home']->id, 'manufacturer_id' => $maker->id, 'model_code' => 'A', 'name' => 'Model A', 'is_active' => true]);
        $modelB = MachineModel::create(['account_id' => $f['home']->id, 'manufacturer_id' => $maker->id, 'model_code' => 'B', 'name' => 'Model B', 'is_active' => true]);
        $f['import']->update(['machine_model_id' => $modelA->id]);
        $g = $this->group($f);
        $u = $this->member($f['home']);
        // The SAME code under a DIFFERENT machine model is a different catalog record, not a collision.
        $other = MachineErrorCode::create(['account_id' => $f['home']->id, 'machine_model_id' => $modelB->id, 'code' => self::CODE, 'title' => 'Model B record', 'manufacturer_description' => 'B cause']);

        $d = $this->preview($u, $f['import']->id, $this->payload($g['high']->id))->assertOk()->json('data');
        $this->assertSame('NEW', $d['collision_status']);
        $this->publish($u, $f['import']->id, $this->payload($g['high']->id), $d['confirmation_token'])->assertOk();

        $this->assertSame(2, MachineErrorCode::count());
        $this->assertSame('Model B record', $other->fresh()->title, 'the other model\'s record is untouched');
        $created = MachineErrorCode::where('machine_model_id', $modelA->id)->first();
        $this->assertNotNull($created);
        $this->assertSame(self::CODE, $created->code);
    }

    // --- 18. transactional ---

    public function test_a_failure_part_way_through_publishing_leaves_nothing_behind(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $token = $this->preview($u, $f['import']->id, $this->payload($g['high']->id))->json('data.confirmation_token');
        $before = $this->counts();

        // `created` (not `creating`): Laravel halts a `creating` chain at the model's own boot listener, which returns
        // the new id. Failing AFTER the third row was inserted also proves already-written rows are rolled back.
        $n = 0;
        MaintenanceDocumentReference::created(function () use (&$n) {
            if (++$n === 3) {
                throw new \RuntimeException('simulated failure after the third reference was inserted');
            }
        });
        try {
            $this->publish($u, $f['import']->id, $this->payload($g['high']->id), $token)->assertStatus(500);
        } finally {
            MaintenanceDocumentReference::flushEventListeners();
        }

        $this->assertSame($before, $this->counts(), 'the error code, solution, references, canonical mark and audit all rolled back');
        $this->assertNull($g['high']->fresh()->published_at);
        $this->assertSame('DRAFT', $g['high']->fresh()->status);
    }

    // --- 19/20. idempotency ---

    public function test_publishing_twice_is_idempotent_and_creates_no_duplicates(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($g['high']->id);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');

        $first = $this->publish($u, $f['import']->id, $payload, $token)->assertOk()->json('data');
        $after = $this->counts();
        $second = $this->publish($u, $f['import']->id, $payload, $token)->assertOk()->json('data');

        $this->assertTrue($first['published']);
        $this->assertFalse($second['published']);
        $this->assertTrue($second['already_published']);
        $this->assertSame('unchanged', $second['mode']);
        $this->assertSame($first['published_at'], $second['published_at']);
        $this->assertSame($after, $this->counts(), 'no duplicate code, solution step, reference, lifecycle state or audit row');
        $this->assertSame(1, MachineErrorCode::count());
        $this->assertSame(1, MaintenanceErrorSolution::count());
        $this->assertSame(6, MaintenanceDocumentReference::count());
        $this->assertSame(1, DB::table('governance_audit_logs')->where('action', 'maintenance_knowledge_group.published')->count());
    }

    public function test_a_retry_after_the_preview_has_expired_is_still_an_idempotent_success(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($g['high']->id);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');
        $this->publish($u, $f['import']->id, $payload, $token)->assertOk();

        Carbon::setTestNow(now()->addMinutes(30));
        try {
            $this->publish($u, $f['import']->id, $payload, $token)->assertOk()->assertJsonPath('data.already_published', true);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(1, MachineErrorCode::count());
    }

    public function test_previewing_an_already_published_identical_group_reports_it_and_offers_no_token(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($g['high']->id);
        $this->previewAndPublish($u, $f['import']->id, $payload)->assertOk();

        $d = $this->preview($u, $f['import']->id, $payload)->assertOk()->json('data');

        $this->assertSame('IDENTICAL', $d['already_published']);
        $this->assertFalse($d['can_publish']);
        $this->assertNull($d['confirmation_token']);
    }

    /**
     * V1.8.1 architecture change: a published group's SHARED fields (title/description/operator
     * guidance) are no longer frozen forever after first publish. The real Konica document need
     * (C-1127: one canonical code, two technician-solution variants published incrementally) means
     * a later publish must be able to legitimately update shared fields OR add a new labelled
     * solution variant - it is refused only when it silently disagrees with something already
     * published (see the CONFLICT-specific tests below). A shared-field difference now goes
     * through the SAME explicit confirm_update gate an EXISTING/POTENTIAL_UPDATE collision already
     * used pre-publish - it is never silently applied, and never a stale-looking flat 409 either.
     */
    public function test_a_published_groups_shared_fields_can_be_updated_later_only_with_explicit_confirmation(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $a = $this->payload($g['high']->id);
        // Same canonical, same technician solution text (so the only real difference is the shared title).
        $b = $this->payload($g['high']->id, ['title' => 'A different reviewer title']);
        $this->previewAndPublish($u, $f['import']->id, $a)->assertOk();

        $preview = $this->preview($u, $f['import']->id, $b)->assertOk()->json('data');
        $this->assertTrue($preview['published']);
        $this->assertTrue($preview['can_publish']);
        $this->assertNull($preview['blocked_reason']);
        $this->assertTrue($preview['requires_update_confirmation']);
        $this->assertSame('UPDATE_SHARED', $preview['group_outcome']);
        $this->assertNotEmpty($preview['confirmation_token']);

        $this->publish($u, $f['import']->id, $b, $preview['confirmation_token'])->assertStatus(422)->assertJsonValidationErrors('confirm_update');
        $this->assertSame('Fuser temperature sensor abnormal', MachineErrorCode::first()->title, 'nothing was overwritten without confirmation');

        $r = $this->publish($u, $f['import']->id, $b, $preview['confirmation_token'], ['confirm_update' => true])->assertOk()->json('data');

        $this->assertSame('A different reviewer title', MachineErrorCode::first()->title);
        $this->assertSame(1, MachineErrorCode::count(), 'updated in place, never duplicated');
        $this->assertSame('updated', $r['mode']);
        $this->assertSame(1, MaintenanceErrorSolution::count(), 'the identical technician solution text was not duplicated');
    }

    /**
     * The counterpart to the above: an UNLABELLED solution that genuinely disagrees with the
     * already-published unlabelled solution is a CONFLICT (Section 6), and is refused outright -
     * confirm_update never overrides a solution conflict, because confirm_update is specifically
     * about the shared canonical fields, not about which technician text is correct.
     */
    public function test_a_published_groups_unlabelled_solution_cannot_be_silently_replaced_by_different_text(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $a = $this->payload($g['high']->id);
        $b = $this->payload($g['high']->id, ['technician_solution' => 'A materially different unlabelled technician procedure.']);
        $this->previewAndPublish($u, $f['import']->id, $a)->assertOk();

        $preview = $this->preview($u, $f['import']->id, $b)->assertOk()->json('data');
        $this->assertFalse($preview['can_publish']);
        $this->assertSame('SOLUTION_CONFLICT', $preview['blocked_reason']);
        $this->assertSame('CONFLICT', $preview['group_outcome']);
        $this->assertSame('CONFLICT', $preview['solutions'][0]['status']);
        $this->assertNull($preview['confirmation_token']);

        $this->assertSame(1, MaintenanceErrorSolution::count());
        $this->assertSame(1, MachineErrorCode::count());
    }

    // --- 21/22/23. stale preview, tampered token, mismatched code ---

    public function test_a_stale_preview_is_rejected_and_changes_nothing(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($g['high']->id);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');
        $g['low']->update(['status' => 'REJECTED']); // a supporting occurrence changed after the preview
        $before = $this->counts();

        $this->publish($u, $f['import']->id, $payload, $token)->assertStatus(409);

        $this->assertSame($before, $this->counts());
        $this->assertNull($g['high']->fresh()->published_at);
    }

    public function test_a_preview_goes_stale_when_the_existing_catalog_record_changes(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $existing = MachineErrorCode::create(['account_id' => $f['home']->id, 'code' => self::CODE, 'title' => 'Manually maintained', 'manufacturer_description' => 'Original']);
        $payload = $this->payload($g['high']->id);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');
        $existing->update(['manufacturer_description' => 'Edited by someone else meanwhile']);

        $this->publish($u, $f['import']->id, $payload, $token, ['confirm_update' => true])->assertStatus(409);

        $this->assertSame('Edited by someone else meanwhile', $existing->fresh()->manufacturer_description);
        $this->assertNull($g['high']->fresh()->published_at);
    }

    public function test_an_expired_preview_is_rejected_when_nothing_is_published_yet(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($g['high']->id);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');

        Carbon::setTestNow(now()->addMinutes(16));
        try {
            $this->publish($u, $f['import']->id, $payload, $token)->assertStatus(409);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(0, MachineErrorCode::count());
    }

    public function test_tampered_forged_and_mismatched_tokens_are_rejected(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $other = $this->member($f['home']);
        $this->entry($f['import'], 'C-4000', 'HIGH', 7);
        $payload = $this->payload($g['high']->id);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');
        [$p, $s] = explode('.', $token);

        // signature tampered, payload forged, garbage, missing
        foreach ([$p.'.'.strrev($s), 'garbage', $p, '.', $p.'.'] as $bad) {
            $this->publish($u, $f['import']->id, $payload, $bad)->assertStatus(422);
        }
        $this->publish($u, $f['import']->id, $payload, null)->assertStatus(422);
        // content changed after the preview, different canonical, different user, different group code
        $this->publish($u, $f['import']->id, $this->payload($g['high']->id, ['title' => 'Edited after preview']), $token)->assertStatus(422);
        $this->publish($u, $f['import']->id, $this->payload($g['multi']->id), $token)->assertStatus(422);
        $this->publish($other, $f['import']->id, $payload, $token)->assertStatus(422);
        $this->publish($u, $f['import']->id, $payload, $token, [], 'C-4000')->assertStatus(422);

        $this->assertSame(0, MachineErrorCode::count());
        $this->assertSame(0, MaintenanceKnowledgeEntry::whereNotNull('published_at')->count());
    }

    // --- 26/27/28. authorization ---

    public function test_only_managers_of_the_owning_account_can_preview_or_publish(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $manager = $this->member($f['home']);
        $token = $this->preview($manager, $f['import']->id, $this->payload($g['high']->id))->json('data.confirmation_token');
        $payload = $this->payload($g['high']->id);

        foreach ([$this->member($f['home'], 'viewer'), $this->member($f['foreign'])] as $blocked) {
            $this->preview($blocked, $f['import']->id, $payload)->assertStatus(403);
            $this->publish($blocked, $f['import']->id, $payload, $token)->assertStatus(403);
        }
        $this->app['auth']->forgetGuards(); // actingAs persists for the rest of the test
        $this->postJson($this->url($f['import']->id, 'publish-preview'), $payload)->assertUnauthorized();
        $this->postJson($this->url($f['import']->id, 'publish'), $payload + ['confirmation_token' => $token])->assertUnauthorized();
        $this->assertSame(0, MachineErrorCode::count());
        $this->assertSame(0, MaintenanceKnowledgeEntry::whereNotNull('published_at')->count());
    }

    // --- 29/30. collision handling ---

    public function test_an_existing_record_is_never_overwritten_without_explicit_confirmation(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $existing = MachineErrorCode::create(['account_id' => $f['home']->id, 'code' => self::CODE, 'title' => 'Hand-written title', 'manufacturer_description' => 'Hand-written cause', 'operator_description' => 'Hand-written guidance']);
        MaintenanceErrorSolution::create(['machine_error_code_id' => $existing->id, 'step_number' => 1, 'instruction' => 'Existing step one', 'requires_technician' => true]);
        // A NEW, distinctly-labelled variant alongside the shared-field update - proves the two are
        // planned and applied together (Section 7), not that a new anonymous step silently appears.
        $payload = $this->payload($g['high']->id, ['technician_solution' => null, 'technician_solutions' => [['applicability_label' => 'Accessory A/B', 'instruction' => 'A brand new labelled technician procedure.']]]);

        $preview = $this->preview($u, $f['import']->id, $payload)->assertOk()->json('data');
        $this->assertSame('EXISTING', $preview['collision_status']);
        $this->assertTrue($preview['requires_update_confirmation']);
        $this->assertSame('UPDATE', $preview['mutation']['error_code']);
        $this->assertSame(['title', 'manufacturer_description', 'operator_description'], $preview['existing']['changed_fields']);
        $this->assertSame('Hand-written title', $preview['existing']['title']);
        $this->assertSame('NEW_VARIANT', $preview['solutions'][0]['status']);

        $this->publish($u, $f['import']->id, $payload, $preview['confirmation_token'])->assertStatus(422)->assertJsonValidationErrors('confirm_update');
        $this->assertSame('Hand-written title', $existing->fresh()->title, 'nothing was overwritten');
        $this->assertNull($g['high']->fresh()->published_at);

        $r = $this->publish($u, $f['import']->id, $payload, $preview['confirmation_token'], ['confirm_update' => true])->assertOk()->json('data');

        $this->assertSame('updated', $r['mode']);
        $this->assertSame(1, MachineErrorCode::count(), 'updated in place, never duplicated');
        $this->assertSame('Fuser temperature sensor abnormal', $existing->fresh()->title);
        $this->assertSame(2, MaintenanceErrorSolution::count(), 'a new labelled variant is appended, the existing unlabelled step is preserved untouched');
        $this->assertSame(1, MaintenanceErrorSolution::where('instruction', 'Existing step one')->whereNull('applicability_label')->count());
        $this->assertSame(1, MaintenanceErrorSolution::where('applicability_label', 'Accessory A/B')->count());
        $this->assertSame(2, MaintenanceErrorSolution::where('machine_error_code_id', $existing->id)->max('step_number'));
    }

    public function test_a_potential_update_record_also_needs_explicit_confirmation(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        MachineErrorCode::create(['account_id' => $f['home']->id, 'code' => self::CODE, 'title' => 'Empty stub']);
        $payload = $this->payload($g['high']->id);

        $preview = $this->preview($u, $f['import']->id, $payload)->assertOk()->json('data');
        $this->assertSame('POTENTIAL_UPDATE', $preview['collision_status']);
        $this->assertTrue($preview['requires_update_confirmation']);

        $this->publish($u, $f['import']->id, $payload, $preview['confirmation_token'])->assertStatus(422);
        $this->assertSame('Empty stub', MachineErrorCode::first()->title);
        $this->publish($u, $f['import']->id, $payload, $preview['confirmation_token'], ['confirm_update' => true])->assertOk();
        $this->assertSame('Fuser temperature sensor abnormal', MachineErrorCode::first()->title);
        $this->assertSame(1, MachineErrorCode::count());
    }

    public function test_an_identical_existing_step_and_existing_page_references_are_not_duplicated_on_update(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $existing = MachineErrorCode::create(['account_id' => $f['home']->id, 'code' => self::CODE, 'title' => 'Old', 'manufacturer_description' => 'Old cause']);
        MaintenanceErrorSolution::create(['machine_error_code_id' => $existing->id, 'step_number' => 1, 'instruction' => '  REPLACE the fuser   thermistor and check its connector. ', 'requires_technician' => true]);
        MaintenanceDocumentReference::create(['document_id' => $f['import']->document_id, 'machine_error_code_id' => $existing->id, 'reference_type' => 'error_code', 'page_number' => 100]);

        $d = $this->preview($u, $f['import']->id, $this->payload($g['high']->id))->assertOk()->json('data');
        $this->assertSame(0, $d['mutation']['solutions_new']);
        $this->assertSame(1, $d['mutation']['solutions_identical']);
        $this->assertSame(5, $d['mutation']['references_to_create']);
        $this->assertSame(1, $d['mutation']['references_existing']);

        $this->publish($u, $f['import']->id, $this->payload($g['high']->id), $d['confirmation_token'], ['confirm_update' => true])->assertOk();

        $this->assertSame(1, MaintenanceErrorSolution::count());
        $this->assertSame(1, MaintenanceDocumentReference::where('page_number', 100)->count());
        $this->assertSame(6, MaintenanceDocumentReference::count());
    }

    public function test_a_rejected_canonical_cannot_be_published(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $token = $this->preview($u, $f['import']->id, $this->payload($g['high']->id))->json('data.confirmation_token');
        $g['high']->update(['status' => 'REJECTED']);

        $d = $this->preview($u, $f['import']->id, $this->payload($g['high']->id))->assertOk()->json('data');
        $this->assertFalse($d['can_publish']);
        $this->assertSame('CANONICAL_REJECTED', $d['blocked_reason']);
        $this->publish($u, $f['import']->id, $this->payload($g['high']->id), $token)->assertStatus(409);
        $this->assertSame(0, MachineErrorCode::count());
    }

    // --- 31/32/33/34. audit ---

    public function test_the_group_publish_audit_is_one_compact_event_without_prose_or_tokens(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $payload = $this->payload($g['high']->id, ['description' => 'SECRET-DESCRIPTION-PROSE', 'operator_guidance' => 'SECRET-OPERATOR-PROSE', 'technician_solution' => 'SECRET-SOLUTION-PROSE', 'title' => 'SECRET-TITLE-PROSE fuser']);
        $g['high']->update(['description' => 'SECRET-CANDIDATE-EXCERPT']);
        $token = $this->preview($u, $f['import']->id, $payload)->json('data.confirmation_token');
        $this->publish($u, $f['import']->id, $payload, $token)->assertOk();
        $this->publish($u, $f['import']->id, $payload, $token)->assertOk(); // the retry must add nothing

        $logs = DB::table('governance_audit_logs')->where('action', 'maintenance_knowledge_group.published')->get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $m = json_decode($log->metadata, true)['changes'];
        $this->assertSame($f['import']->id, $log->target_id);
        $this->assertSame($f['home']->id, $log->account_id);
        $this->assertSame(self::CODE, $m['normalized_code']['after']);
        $this->assertSame($g['high']->id, $m['canonical_candidate_id']['after']);
        $this->assertSame(5, $m['occurrence_count']['after']);
        $this->assertSame(6, $m['supporting_page_count']['after']);
        $this->assertSame('NEW', $m['collision_status']['after']);
        $this->assertSame('created', $m['publish_mode']['after']);
        $this->assertSame(16, strlen($m['publication_fingerprint']['after']));
        $this->assertLessThan(900, strlen($log->metadata));

        $everything = DB::table('governance_audit_logs')->get()->pluck('metadata')->implode(' ');
        foreach (['SECRET-DESCRIPTION-PROSE', 'SECRET-OPERATOR-PROSE', 'SECRET-SOLUTION-PROSE', 'SECRET-CANDIDATE-EXCERPT'] as $prose) {
            $this->assertStringNotContainsString($prose, $everything, 'no prose in any audit row of this publication');
        }
        // The group event itself carries no title either. (The pre-existing, reviewed machine_error_code event records the
        // short title label exactly as the legacy publish always has; descriptions and solutions are never in it.)
        $this->assertStringNotContainsString('SECRET-TITLE-PROSE', $log->metadata);
        $codeEvent = DB::table('governance_audit_logs')->where('action', 'machine_error_code.published_from_import')->first();
        $this->assertNotNull($codeEvent);
        $this->assertStringNotContainsString('SECRET-DESCRIPTION-PROSE', $codeEvent->metadata);
        $this->assertStringNotContainsString(substr($token, 0, 24), $everything, 'no confirmation token in any audit row');
        $this->assertStringNotContainsString('/home/', $everything);
    }

    // --- 35. legacy behaviour ---

    public function test_legacy_candidate_publish_is_refused_for_pdf_derived_candidates_and_unchanged_for_manual_entries(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $g['high']->update(['status' => 'APPROVED']);

        $this->actingAs($u)->postJson("/api/v1/maintenance/knowledge-entries/{$g['high']->id}/publish")->assertStatus(409);
        $this->assertSame(0, MachineErrorCode::count());
        $this->assertNull($g['high']->fresh()->published_at);

        $manualImport = $this->makeImport($f['home'], 'MANUAL_ENTRY');
        $manual = MaintenanceKnowledgeEntry::create(['import_id' => $manualImport->id, 'knowledge_type' => 'ERROR_CODE', 'code' => 'E-101', 'title' => 'Hand-entered', 'description' => 'd', 'status' => 'APPROVED']);
        $this->actingAs($u)->postJson("/api/v1/maintenance/knowledge-entries/{$manual->id}/publish")->assertOk();
        $this->assertSame(1, MachineErrorCode::where('code', 'E-101')->count());
    }

    // --- 36. no bulk publish ---

    public function test_no_bulk_or_publish_all_route_exists(): void
    {
        $publishRoutes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->methods()[0].' '.$r->uri())->filter(fn ($u) => str_contains($u, 'publish'))->values()->all();

        $this->assertEqualsCanonicalizing([
            'POST api/v1/maintenance/knowledge-entries/{id}/publish',
            'POST api/v1/maintenance/document-imports/{id}/code-groups/{code}/publish-preview',
            'POST api/v1/maintenance/document-imports/{id}/code-groups/{code}/publish',
        ], $publishRoutes);
        $this->assertSame([], collect($publishRoutes)->filter(fn ($u) => preg_match('/bulk|all|batch/', $u) === 1)->all());
    }

    public function test_publishing_one_group_never_touches_another_group_of_the_same_import(): void
    {
        $f = $this->fixture();
        $g = $this->group($f);
        $u = $this->member($f['home']);
        $sibling = $this->entry($f['import'], 'C-4000', 'HIGH', 7);

        $this->previewAndPublish($u, $f['import']->id, $this->payload($g['high']->id))->assertOk();

        $this->assertNull($sibling->fresh()->published_at);
        $this->assertSame('DRAFT', $sibling->fresh()->status);
        $this->assertSame(0, MachineErrorCode::where('code', 'C-4000')->count());
    }
}
