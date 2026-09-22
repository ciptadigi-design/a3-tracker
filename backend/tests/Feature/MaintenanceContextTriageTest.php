<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentPage;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Maintenance V1.10 - Knowledge Review Context Triage. A cheap, deterministic, PURELY
 * STRUCTURAL list-view signal (context_hint/context_reason): where a group's representative
 * candidate's code text falls within its own source page. It never implies correctness - only
 * that a reviewer may want to also read a neighbouring page (V1.9's Source Page Context) before
 * deciding. Real Production validation: 534 HIGH-evidence groups split 58.4% SELF_CONTAINED /
 * 14.8% PAGE_END_CONTINUATION / 26.8% PAGE_START_CONTINUATION - a genuinely useful, non-
 * degenerate distribution, independently confirmed against two real codes (C-1124/C-1125, same
 * source page, opposite classification) read directly during V1.9's acceptance. All fixtures
 * here are synthetic - no real manual text.
 */
class MaintenanceContextTriageTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 5);
        $home = Account::create(['code' => 'HOM'.$suffix, 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRG'.$suffix, 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        [$document, $import] = $this->makeImport($home);
        [$foreignDocument, $foreignImport] = $this->makeImport($foreign);

        return compact('home', 'foreign', 'document', 'import', 'foreignDocument', 'foreignImport');
    }

    /** @return array{0: MaintenanceDocument, 1: MaintenanceDocumentImport} */
    private function makeImport(Account $account): array
    {
        $document = MaintenanceDocument::create(['account_id' => $account->id, 'title' => 'Fixture Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $import = MaintenanceDocumentImport::create(['document_id' => $document->id, 'import_type' => 'PDF_EXTRACTION', 'status' => 'REVIEW', 'processing_version' => 2, 'candidate_count' => 0]);

        return [$document, $import];
    }

    private function member(Account $account, string $role = 'owner'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function entry(MaintenanceDocumentImport $import, string $code, int $start, array $overrides = []): MaintenanceKnowledgeEntry
    {
        return MaintenanceKnowledgeEntry::create(array_merge([
            'import_id' => $import->id,
            'knowledge_type' => 'ERROR_CODE',
            'normalized_code' => $code,
            'code' => $code,
            'title' => 'Fixture title '.$code,
            'description' => 'Fixture excerpt for '.$code,
            'evidence' => 'HIGH',
            'collision_status' => 'NEW',
            'source_page_start' => $start,
            'source_page_end' => $start,
            'status' => 'DRAFT',
        ], $overrides));
    }

    private function page(MaintenanceDocument $document, int $number, string $text): MaintenanceDocumentPage
    {
        return MaintenanceDocumentPage::create(['document_id' => $document->id, 'page_number' => $number, 'raw_text' => $text]);
    }

    /** Builds a page whose text places `code` at an exact position ratio (0.0-1.0) within it. */
    private function pageWithCodeAtRatio(MaintenanceDocument $document, int $number, string $code, float $ratio, int $length = 1000): void
    {
        $position = (int) round($length * $ratio);
        $before = str_repeat('x', $position);
        $after = str_repeat('y', max(0, $length - $position - strlen($code)));
        $this->page($document, $number, $before.$code.$after);
    }

    private function listGroups(User $user, string $importId, array $query = [])
    {
        return $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$importId}/code-groups".($query ? '?'.http_build_query($query) : ''));
    }

    private function byCode($response): array
    {
        return collect($response->json('data.data'))->keyBy('normalized_code')->all();
    }

    // --- 1. self-contained classification ---

    public function test_a_mid_page_match_is_classified_self_contained_with_no_reason(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3001', 100);
        $this->pageWithCodeAtRatio($f['document'], 100, 'C-3001', 0.50);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('SELF_CONTAINED', $groups['C-3001']['context_hint']);
        $this->assertNull($groups['C-3001']['context_reason']);
    }

    // --- 2/3. context-recommended, page-end continuation ---

    public function test_a_match_near_the_page_end_is_classified_context_recommended_page_end_continuation(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3002', 101);
        $this->pageWithCodeAtRatio($f['document'], 101, 'C-3002', 0.85);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('CONTEXT_RECOMMENDED', $groups['C-3002']['context_hint']);
        $this->assertSame('PAGE_END_CONTINUATION', $groups['C-3002']['context_reason']);
    }

    // --- 4. page-start/previous-page context ---

    public function test_a_match_near_the_page_start_is_classified_context_recommended_page_start_continuation(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3003', 102);
        $this->pageWithCodeAtRatio($f['document'], 102, 'C-3003', 0.02);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('CONTEXT_RECOMMENDED', $groups['C-3003']['context_hint']);
        $this->assertSame('PAGE_START_CONTINUATION', $groups['C-3003']['context_reason']);
    }

    public function test_the_exact_threshold_boundaries_are_inclusive(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3004', 200); // exactly at the near-end threshold
        $this->pageWithCodeAtRatio($f['document'], 200, 'C-3004', 0.75);
        $this->entry($f['import'], 'C-3005', 201); // exactly at the near-start threshold
        $this->pageWithCodeAtRatio($f['document'], 201, 'C-3005', 0.10);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('CONTEXT_RECOMMENDED', $groups['C-3004']['context_hint']);
        $this->assertSame('CONTEXT_RECOMMENDED', $groups['C-3005']['context_hint']);
    }

    // --- 5. existing multi-page occurrence still classifies from its own start page ---

    public function test_a_multi_page_occurrence_is_classified_from_its_own_source_page_start(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3006', 300, ['source_page_end' => 301]);
        $this->pageWithCodeAtRatio($f['document'], 300, 'C-3006', 0.90);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('CONTEXT_RECOMMENDED', $groups['C-3006']['context_hint']);
    }

    // --- 6. multiple occurrences use the same representative candidate already established ---

    public function test_classification_uses_the_same_representative_candidate_as_representative_candidate_id(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3007', 400, ['evidence' => 'LOW']);
        $this->entry($f['import'], 'C-3007', 401, ['evidence' => 'HIGH']); // best evidence, earliest -> representative
        $this->entry($f['import'], 'C-3007', 402, ['evidence' => 'HIGH']);
        $this->pageWithCodeAtRatio($f['document'], 401, 'C-3007', 0.80); // the representative's own page
        $this->pageWithCodeAtRatio($f['document'], 402, 'C-3007', 0.20); // a different occurrence's page - must be ignored

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        // The representative is the earliest HIGH-evidence occurrence (page 401), matching
        // representative_candidate_id's own established selection - never page 402.
        $this->assertSame('CONTEXT_RECOMMENDED', $groups['C-3007']['context_hint']);
        $this->assertSame('PAGE_END_CONTINUATION', $groups['C-3007']['context_reason']);
    }

    // --- 7. rejected occurrences don't break representative selection or classification ---

    public function test_a_rejected_occurrence_can_still_be_the_representative_and_is_classified_normally(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3008', 500, ['status' => 'REJECTED']);
        $this->pageWithCodeAtRatio($f['document'], 500, 'C-3008', 0.90);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('CONTEXT_RECOMMENDED', $groups['C-3008']['context_hint']);
    }

    // --- 8/9. missing adjacent page / malformed data fail safe, never a false alarm ---

    public function test_a_group_with_no_matching_page_row_defaults_to_self_contained(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3009', 600); // no MaintenanceDocumentPage row created

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('SELF_CONTAINED', $groups['C-3009']['context_hint']);
        $this->assertNull($groups['C-3009']['context_reason']);
    }

    public function test_a_code_not_literally_present_on_its_own_recorded_page_defaults_to_self_contained(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3010', 601);
        $this->page($f['document'], 601, 'Completely unrelated synthetic text with no code mention at all.');

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('SELF_CONTAINED', $groups['C-3010']['context_hint']);
    }

    public function test_an_empty_page_defaults_to_self_contained(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3011', 602);
        $this->page($f['document'], 602, '');

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));

        $this->assertSame('SELF_CONTAINED', $groups['C-3011']['context_hint']);
    }

    // --- 10. deterministic ---

    public function test_the_classification_is_deterministic_across_repeated_requests(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3012', 700);
        $this->pageWithCodeAtRatio($f['document'], 700, 'C-3012', 0.85);
        $user = $this->member($f['home']);

        $first = $this->byCode($this->listGroups($user, $f['import']->id))['C-3012'];
        $second = $this->byCode($this->listGroups($user, $f['import']->id))['C-3012'];

        $this->assertSame($first['context_hint'], $second['context_hint']);
        $this->assertSame($first['context_reason'], $second['context_reason']);
    }

    // --- 11. pagination is preserved ---

    public function test_context_hints_do_not_disturb_pagination(): void
    {
        $f = $this->fixture();
        foreach (range(1, 30) as $n) {
            $this->entry($f['import'], 'C-31'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 800 + $n);
        }
        $user = $this->member($f['home']);

        $page1 = $this->listGroups($user, $f['import']->id, ['per_page' => 25, 'page' => 1])->assertOk()->json('data');
        $page2 = $this->listGroups($user, $f['import']->id, ['per_page' => 25, 'page' => 2])->assertOk()->json('data');

        $this->assertCount(25, $page1['data']);
        $this->assertCount(5, $page2['data']);
        $this->assertSame([], array_intersect(array_column($page1['data'], 'normalized_code'), array_column($page2['data'], 'normalized_code')));
    }

    // --- 12. code search still works ---

    public function test_code_search_still_works_alongside_context_hints(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3200', 900);
        $this->entry($f['import'], 'C-4200', 901);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id, ['code' => '3200']));

        $this->assertArrayHasKey('C-3200', $groups);
        $this->assertArrayNotHasKey('C-4200', $groups);
    }

    // --- 13. review-status filter still works ---

    public function test_review_state_filter_still_works_alongside_context_hints(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3300', 910, ['status' => 'REJECTED']);
        $this->entry($f['import'], 'C-3301', 911, ['status' => 'DRAFT']);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id, ['review_state' => 'REJECTED']));

        $this->assertArrayHasKey('C-3300', $groups);
        $this->assertArrayNotHasKey('C-3301', $groups);
    }

    // --- 14. collision filter still works ---

    public function test_collision_filter_still_works_alongside_context_hints(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3400', 920, ['collision_status' => 'EXISTING']);
        $this->entry($f['import'], 'C-3401', 921, ['collision_status' => 'NEW']);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id, ['collision_status' => 'EXISTING']));

        $this->assertArrayHasKey('C-3400', $groups);
        $this->assertArrayNotHasKey('C-3401', $groups);
    }

    // --- 15. context filter itself ---

    public function test_the_context_filter_returns_only_matching_groups_and_preserves_pagination(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3500', 930);
        $this->pageWithCodeAtRatio($f['document'], 930, 'C-3500', 0.90); // CONTEXT_RECOMMENDED
        $this->entry($f['import'], 'C-3501', 931);
        $this->pageWithCodeAtRatio($f['document'], 931, 'C-3501', 0.50); // SELF_CONTAINED

        $recommended = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id, ['context' => 'CONTEXT_RECOMMENDED']));
        $selfContained = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id, ['context' => 'SELF_CONTAINED']));

        $this->assertArrayHasKey('C-3500', $recommended);
        $this->assertArrayNotHasKey('C-3501', $recommended);
        $this->assertArrayHasKey('C-3501', $selfContained);
        $this->assertArrayNotHasKey('C-3500', $selfContained);
    }

    public function test_the_context_filter_composes_with_code_search(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3600', 940);
        $this->pageWithCodeAtRatio($f['document'], 940, 'C-3600', 0.90);
        $this->entry($f['import'], 'C-3601', 941);
        $this->pageWithCodeAtRatio($f['document'], 941, 'C-3601', 0.90);

        $groups = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id, ['context' => 'CONTEXT_RECOMMENDED', 'code' => '3600']));

        $this->assertArrayHasKey('C-3600', $groups);
        $this->assertArrayNotHasKey('C-3601', $groups);
    }

    // --- 16. tenant isolation ---

    public function test_context_hints_never_leak_another_accounts_page_text_or_classification(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3700', 950);
        $this->pageWithCodeAtRatio($f['document'], 950, 'C-3700', 0.90);
        $this->entry($f['foreignImport'], 'C-3700', 950);
        $this->pageWithCodeAtRatio($f['foreignDocument'], 950, 'C-3700', 0.50); // same code/page number, different account, opposite classification

        $home = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id));
        $foreign = $this->byCode($this->listGroups($this->member($f['foreign']), $f['foreignImport']->id));

        $this->assertSame('CONTEXT_RECOMMENDED', $home['C-3700']['context_hint']);
        $this->assertSame('SELF_CONTAINED', $foreign['C-3700']['context_hint']);
    }

    public function test_a_foreign_tenant_cannot_read_another_accounts_group_list_at_all(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3701', 951);

        $this->actingAs($this->member($f['foreign']))->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups")->assertNotFound();
    }

    // --- 17. no mutation ---

    public function test_reading_context_hints_never_mutates_anything(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3800', 960);
        $this->pageWithCodeAtRatio($f['document'], 960, 'C-3800', 0.90);
        $before = MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at'])->toArray();

        $this->listGroups($this->member($f['home']), $f['import']->id)->assertOk();
        $this->listGroups($this->member($f['home']), $f['import']->id, ['context' => 'CONTEXT_RECOMMENDED'])->assertOk();

        $this->assertSame($before, MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at'])->toArray());
        $this->assertSame(0, DB::table('machine_error_codes')->count());
        $this->assertSame(0, DB::table('governance_audit_logs')->count());
    }

    // --- list payload never carries raw text ---

    public function test_the_list_response_never_includes_raw_page_text_only_the_hint_and_reason(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-3900', 970);
        $this->pageWithCodeAtRatio($f['document'], 970, 'C-3900', 0.90, 2000);

        $response = $this->listGroups($this->member($f['home']), $f['import']->id);
        $raw = $response->getContent();

        $this->assertStringNotContainsString(str_repeat('x', 100), $raw, 'no run of the synthetic page filler text ever reaches the list payload');
        $group = $this->byCode($response)['C-3900'];
        $this->assertArrayHasKey('context_hint', $group);
        $this->assertArrayHasKey('context_reason', $group);
        $this->assertArrayNotHasKey('raw_text', $group);
    }

    // --- 18. no N+1 regression ---

    public function test_context_hint_computation_does_not_grow_query_count_per_group(): void
    {
        $f = $this->fixture();
        foreach (range(1, 5) as $n) {
            $this->entry($f['import'], 'C-40'.$n, 1000 + $n);
            $this->pageWithCodeAtRatio($f['document'], 1000 + $n, 'C-40'.$n, 0.85);
        }
        $user = $this->member($f['home']);
        $this->listGroups($user, $f['import']->id)->assertOk(); // warm-up

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listGroups($user, $f['import']->id)->assertOk();
        $fiveWide = count(DB::getQueryLog());
        DB::disableQueryLog();

        $f2 = $this->fixture();
        $this->entry($f2['import'], 'C-4101', 1101);
        $this->pageWithCodeAtRatio($f2['document'], 1101, 'C-4101', 0.85);
        $user2 = $this->member($f2['home']);
        $this->listGroups($user2, $f2['import']->id)->assertOk(); // warm-up

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->listGroups($user2, $f2['import']->id)->assertOk();
        $oneWide = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($oneWide, $fiveWide, 'context-hint computation must be one bulk query regardless of how many groups are on the page');
    }

    // --- detail() shows the same hint ---

    public function test_the_group_detail_view_exposes_the_same_context_hint_as_the_list(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-4200', 1200);
        $this->pageWithCodeAtRatio($f['document'], 1200, 'C-4200', 0.90);

        $listHint = $this->byCode($this->listGroups($this->member($f['home']), $f['import']->id))['C-4200']['context_hint'];
        $detail = $this->actingAs($this->member($f['home']))->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/C-4200")->assertOk()->json('data');

        $this->assertSame($listHint, $detail['context_hint']);
        $this->assertSame('CONTEXT_RECOMMENDED', $detail['context_hint']);
        $this->assertArrayHasKey('source_pages', $detail, 'V1.9 detail behavior is unchanged');
    }
}
