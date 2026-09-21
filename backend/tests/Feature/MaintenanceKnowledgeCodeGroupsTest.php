<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Maintenance V1.7.2 - Knowledge Review Consolidation: the code-group READ MODEL.
 * A group is a projection over the existing maintenance_knowledge_entries rows
 * (grouped by normalized_code inside one import); nothing is stored for it. All
 * fixtures are synthetic - no real manual text.
 */
class MaintenanceKnowledgeCodeGroupsTest extends TestCase
{
    use RefreshDatabase;

    private const DOTS = 'Synthetic index line .......... 12';

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $import = $this->makeImport($home);
        $foreignImport = $this->makeImport($foreign);

        return compact('home', 'foreign', 'import', 'foreignImport');
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

    private function entry(MaintenanceDocumentImport $import, ?string $code, string $evidence, int $start, ?int $end = null, string $status = 'DRAFT', array $overrides = []): MaintenanceKnowledgeEntry
    {
        return MaintenanceKnowledgeEntry::create(array_merge([
            'import_id' => $import->id,
            'knowledge_type' => 'ERROR_CODE',
            'normalized_code' => $code,
            'code' => $code,
            'title' => 'Fixture title '.$code,
            'description' => 'Fixture excerpt for '.$code.' page '.$start,
            'evidence' => $evidence,
            'collision_status' => 'NEW',
            'source_page_start' => $start,
            'source_page_end' => $end ?? $start,
            'status' => $status,
        ], $overrides));
    }

    /** The shared dataset most tests read. */
    private function dataset(array $f): void
    {
        $i = $f['import'];
        // C-1001: mixed evidence, a two-page candidate, one dotted-leader reference row.
        $this->entry($i, 'C-1001', 'HIGH', 100);
        $this->entry($i, 'C-1001', 'MEDIUM', 101, 102);
        $this->entry($i, 'C-1001', 'LOW', 10, null, 'DRAFT', ['description' => self::DOTS]);
        // C-1002: LOW-only, every occurrence reference-like.
        $this->entry($i, 'C-1002', 'LOW', 11, null, 'DRAFT', ['description' => self::DOTS]);
        $this->entry($i, 'C-1002', 'LOW', 900, null, 'DRAFT', ['description' => self::DOTS]);
        // C-1003: MEDIUM best.
        $this->entry($i, 'C-1003', 'MEDIUM', 200, null, 'DRAFT', ['collision_status' => 'EXISTING']);
        // Review-state ladder.
        $this->entry($i, 'C-1004', 'HIGH', 300, null, 'DRAFT');
        $this->entry($i, 'C-1004', 'LOW', 301, null, 'REJECTED');
        $this->entry($i, 'C-1005', 'LOW', 310, null, 'REJECTED');
        $this->entry($i, 'C-1006', 'HIGH', 320, null, 'APPROVED');
        $this->entry($i, 'C-1007', 'HIGH', 330, null, 'APPROVED', ['published_at' => now()]);
        // A manual entry has no normalized_code: it is never part of a code group.
        $this->entry($i, null, 'LOW', 5, null, 'DRAFT', ['knowledge_type' => 'WARNING', 'evidence' => null, 'collision_status' => null]);
        // Another tenant's rows for the same codes must never leak in.
        $this->entry($f['foreignImport'], 'C-1001', 'HIGH', 1);
        $this->entry($f['foreignImport'], 'C-9999', 'HIGH', 2);
    }

    private function readGroups(User $user, string $importId, array $query = [])
    {
        return $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$importId}/code-groups".($query ? '?'.http_build_query($query) : ''));
    }

    private function byCode($response): array
    {
        return collect($response->json('data.data'))->keyBy('normalized_code')->all();
    }

    // --- 1-6. grouping, counts, precedence, statuses, pages ---

    public function test_candidates_are_grouped_by_normalized_code_within_the_import(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $g = $this->byCode($this->readGroups($this->member($f['home']), $f['import']->id)->assertOk());

        $this->assertSame(['C-1001', 'C-1002', 'C-1003', 'C-1004', 'C-1005', 'C-1006', 'C-1007'], collect($g)->keys()->sort()->values()->all());
        $this->assertArrayNotHasKey('C-9999', $g);
        $this->assertSame(3, $g['C-1001']['occurrence_count']);
        $this->assertSame(2, $g['C-1002']['occurrence_count']);
    }

    public function test_evidence_counts_and_best_evidence_precedence_high_over_medium_over_low(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $g = $this->byCode($this->readGroups($this->member($f['home']), $f['import']->id)->assertOk());

        $this->assertSame(['HIGH' => 1, 'MEDIUM' => 1, 'LOW' => 1], $g['C-1001']['evidence_counts']);
        $this->assertSame('HIGH', $g['C-1001']['best_evidence']);
        $this->assertSame('MEDIUM', $g['C-1003']['best_evidence']);
        $this->assertSame('LOW', $g['C-1002']['best_evidence']);
        $this->assertSame(['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 2], $g['C-1002']['evidence_counts']);
    }

    public function test_candidate_status_counts_sum_to_occurrences_and_split_approved_from_published(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $g = $this->byCode($this->readGroups($this->member($f['home']), $f['import']->id)->assertOk());

        $this->assertSame(['DRAFT' => 1, 'REJECTED' => 1, 'APPROVED' => 0, 'PUBLISHED' => 0], $g['C-1004']['candidate_status_counts']);
        $this->assertSame(['DRAFT' => 0, 'REJECTED' => 0, 'APPROVED' => 1, 'PUBLISHED' => 0], $g['C-1006']['candidate_status_counts']);
        $this->assertSame(['DRAFT' => 0, 'REJECTED' => 0, 'APPROVED' => 0, 'PUBLISHED' => 1], $g['C-1007']['candidate_status_counts']);
        foreach ($g as $group) {
            $this->assertSame($group['occurrence_count'], array_sum($group['candidate_status_counts']));
        }
    }

    public function test_page_range_multi_page_flag_and_bounded_page_list(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $g = $this->byCode($this->readGroups($this->member($f['home']), $f['import']->id)->assertOk());

        $this->assertSame(10, $g['C-1001']['source_page_min']);
        $this->assertSame(102, $g['C-1001']['source_page_max']);
        $this->assertSame([10, 100, 101], $g['C-1001']['source_pages']);
        $this->assertSame(3, $g['C-1001']['distinct_page_count']);
        $this->assertTrue($g['C-1001']['has_multi_page_candidate']);
        $this->assertFalse($g['C-1002']['has_multi_page_candidate']);
        $this->assertFalse($g['C-1001']['source_pages_truncated']);
    }

    public function test_a_very_wide_group_lists_a_bounded_number_of_pages_and_flags_truncation(): void
    {
        $f = $this->fixture();
        foreach (range(1, 20) as $p) {
            $this->entry($f['import'], 'C-2000', 'LOW', $p);
        }
        $g = $this->byCode($this->readGroups($this->member($f['home']), $f['import']->id)->assertOk());

        $this->assertCount(12, $g['C-2000']['source_pages']);
        $this->assertTrue($g['C-2000']['source_pages_truncated']);
        $this->assertSame(20, $g['C-2000']['distinct_page_count']);
    }

    public function test_reference_like_signal_is_derived_from_the_stored_dotted_leader_and_no_text_is_returned(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $response = $this->readGroups($this->member($f['home']), $f['import']->id)->assertOk();
        $g = $this->byCode($response);

        $this->assertSame(1, $g['C-1001']['reference_like_count']);
        $this->assertFalse($g['C-1001']['reference_like_all']);
        $this->assertTrue($g['C-1002']['reference_like_all']);
        $this->assertStringNotContainsString('Synthetic index line', $response->getContent());
        $this->assertStringNotContainsString('Fixture excerpt', $response->getContent());
        $this->assertArrayNotHasKey('description', $g['C-1001']);
    }

    public function test_representative_candidate_is_the_strongest_then_earliest_occurrence(): void
    {
        $f = $this->fixture();
        $high = $this->entry($f['import'], 'C-3000', 'HIGH', 500);
        $this->entry($f['import'], 'C-3000', 'HIGH', 600);
        $this->entry($f['import'], 'C-3000', 'LOW', 5);
        $g = $this->byCode($this->readGroups($this->member($f['home']), $f['import']->id)->assertOk());

        $this->assertSame($high->id, $g['C-3000']['representative_candidate_id']);
    }

    // --- derived review state ---

    public function test_group_review_state_is_derived_deterministically_from_candidate_statuses(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $g = $this->byCode($this->readGroups($this->member($f['home']), $f['import']->id)->assertOk());

        $this->assertSame('UNREVIEWED', $g['C-1001']['review_state']);
        $this->assertSame('PARTIALLY_REVIEWED', $g['C-1004']['review_state']);
        $this->assertSame('REJECTED', $g['C-1005']['review_state']);
        $this->assertSame('APPROVED', $g['C-1006']['review_state']);
        $this->assertSame('PUBLISHED', $g['C-1007']['review_state']);
    }

    public function test_state_follows_the_rows_with_no_stored_group_status_to_drift(): void
    {
        $f = $this->fixture();
        $a = $this->entry($f['import'], 'C-4000', 'HIGH', 1);
        $b = $this->entry($f['import'], 'C-4000', 'LOW', 2);
        $user = $this->member($f['home']);
        $this->assertSame('UNREVIEWED', $this->byCode($this->readGroups($user, $f['import']->id))['C-4000']['review_state']);

        $a->update(['status' => 'REJECTED']);
        $this->assertSame('PARTIALLY_REVIEWED', $this->byCode($this->readGroups($user, $f['import']->id))['C-4000']['review_state']);

        $b->update(['status' => 'REJECTED']);
        $this->assertSame('REJECTED', $this->byCode($this->readGroups($user, $f['import']->id))['C-4000']['review_state']);
    }

    public function test_summary_reconciles_with_the_import_and_counts_manual_rows_as_ungrouped(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $summary = $this->readGroups($this->member($f['home']), $f['import']->id)->assertOk()->json('data.summary');

        $this->assertSame(12, $summary['total_candidates']);
        $this->assertSame(1, $summary['ungrouped_candidates']);
        $this->assertSame(7, $summary['distinct_codes']);
        $this->assertSame(['HIGH' => 4, 'MEDIUM' => 1, 'LOW' => 2], $summary['best_evidence_codes']);
        $this->assertSame(7, array_sum($summary['best_evidence_codes']));
        $this->assertSame(['UNREVIEWED' => 3, 'PARTIALLY_REVIEWED' => 1, 'REJECTED' => 1, 'APPROVED' => 1, 'PUBLISHED' => 1], $summary['review_state_codes']);
        $this->assertSame(4, $summary['codes_remaining']);
        $this->assertSame(3, $summary['codes_reviewed']);
    }

    // --- 7. pagination ---

    public function test_pagination_defaults_to_25_and_supports_10_and_50_only(): void
    {
        $f = $this->fixture();
        foreach (range(1, 30) as $n) {
            $this->entry($f['import'], 'C-'.(5000 + $n), 'LOW', $n);
        }
        $user = $this->member($f['home']);

        $default = $this->readGroups($user, $f['import']->id)->assertOk();
        $this->assertCount(25, $default->json('data.data'));
        $this->assertSame(30, $default->json('data.total'));
        $this->assertSame(2, $default->json('data.last_page'));

        $this->assertCount(10, $this->readGroups($user, $f['import']->id, ['per_page' => 10])->json('data.data'));
        $this->assertCount(30, $this->readGroups($user, $f['import']->id, ['per_page' => 50])->json('data.data'));
        $this->readGroups($user, $f['import']->id, ['per_page' => 20])->assertStatus(422);
        $this->readGroups($user, $f['import']->id, ['per_page' => 5000])->assertStatus(422);
    }

    public function test_pages_never_overlap_or_skip_groups(): void
    {
        $f = $this->fixture();
        foreach (range(1, 23) as $n) {
            $this->entry($f['import'], 'C-'.(6000 + $n), $n % 2 ? 'HIGH' : 'LOW', 1000 - $n);
        }
        $user = $this->member($f['home']);
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $rows = $this->readGroups($user, $f['import']->id, ['per_page' => 10, 'page' => $page])->assertOk()->json('data.data');
            foreach ($rows as $row) {
                $seen[] = $row['normalized_code'];
            }
        }
        $this->assertCount(23, $seen);
        $this->assertCount(23, array_unique($seen));
    }

    public function test_default_order_is_best_evidence_first_then_earliest_page(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], 'C-7002', 'LOW', 1);
        $this->entry($f['import'], 'C-7003', 'HIGH', 50);
        $this->entry($f['import'], 'C-7001', 'HIGH', 40);
        $this->entry($f['import'], 'C-7004', 'MEDIUM', 5);
        $codes = collect($this->readGroups($this->member($f['home']), $f['import']->id)->json('data.data'))->pluck('normalized_code')->all();

        $this->assertSame(['C-7001', 'C-7003', 'C-7004', 'C-7002'], $codes);
    }

    // --- 8-13. filters ---

    public function test_code_search_matches_partial_codes_and_rejects_unsafe_input(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $user = $this->member($f['home']);

        $this->assertSame(['C-1001'], collect($this->readGroups($user, $f['import']->id, ['code' => '1001'])->json('data.data'))->pluck('normalized_code')->all());
        $this->assertSame(['C-1001'], collect($this->readGroups($user, $f['import']->id, ['code' => 'c-1001'])->json('data.data'))->pluck('normalized_code')->all());
        $this->readGroups($user, $f['import']->id, ['code' => "1001'; DROP TABLE x;--"])->assertStatus(422);
        $this->readGroups($user, $f['import']->id, ['code' => '%'])->assertStatus(422);
    }

    public function test_evidence_filter_lists_groups_that_have_an_occurrence_at_that_level_and_keeps_full_counts(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $r = $this->readGroups($this->member($f['home']), $f['import']->id, ['evidence' => 'LOW'])->assertOk();
        $g = $this->byCode($r);

        $this->assertSame(['C-1001', 'C-1002', 'C-1004', 'C-1005'], collect($g)->keys()->sort()->values()->all());
        // The group still summarises ALL of its rows; only matching_occurrence_count reflects the filter.
        $this->assertSame(3, $g['C-1001']['occurrence_count']);
        $this->assertSame(1, $g['C-1001']['matching_occurrence_count']);
    }

    public function test_best_evidence_filter_and_the_strong_evidence_preset(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $user = $this->member($f['home']);
        $codes = fn (array $q) => collect($this->readGroups($user, $f['import']->id, $q)->assertOk()->json('data.data'))->pluck('normalized_code')->sort()->values()->all();

        $this->assertSame(['C-1001', 'C-1004', 'C-1006', 'C-1007'], $codes(['best_evidence' => 'HIGH']));
        $this->assertSame(['C-1003'], $codes(['best_evidence' => 'MEDIUM']));
        $this->assertSame(['C-1002', 'C-1005'], $codes(['best_evidence' => 'LOW']));
        $this->assertSame(['C-1001', 'C-1003', 'C-1004', 'C-1006', 'C-1007'], $codes(['best_evidence' => 'STRONG']));
    }

    public function test_status_and_collision_and_review_state_and_occurrence_filters(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $user = $this->member($f['home']);
        $codes = fn (array $q) => collect($this->readGroups($user, $f['import']->id, $q)->assertOk()->json('data.data'))->pluck('normalized_code')->sort()->values()->all();

        $this->assertSame(['C-1004', 'C-1005'], $codes(['status' => 'REJECTED']));
        $this->assertSame(['C-1006', 'C-1007'], $codes(['status' => 'APPROVED']));
        $this->assertSame(['C-1003'], $codes(['collision_status' => 'EXISTING']));
        $this->assertSame(['C-1004'], $codes(['review_state' => 'PARTIALLY_REVIEWED']));
        $this->assertSame(['C-1007'], $codes(['review_state' => 'PUBLISHED']));
        $this->assertSame(['C-1001'], $codes(['min_occurrences' => 3]));
        $this->assertSame(['C-1003', 'C-1005', 'C-1006', 'C-1007'], $codes(['max_occurrences' => 1]));
        $this->readGroups($user, $f['import']->id, ['review_state' => 'MAYBE'])->assertStatus(422);
        // A lone upper/lower bound is valid; only an inverted pair is rejected.
        $this->assertSame(['C-1002'], $codes(['source_page_to' => 11, 'evidence' => 'LOW', 'max_occurrences' => 2, 'min_occurrences' => 2]));
        $this->readGroups($user, $f['import']->id, ['min_occurrences' => 3, 'max_occurrences' => 2])->assertStatus(422);
    }

    public function test_page_range_filter_uses_overlap_and_row_criteria_apply_to_the_same_row(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $user = $this->member($f['home']);
        $codes = fn (array $q) => collect($this->readGroups($user, $f['import']->id, $q)->assertOk()->json('data.data'))->pluck('normalized_code')->sort()->values()->all();

        // C-1001 has a candidate on 101-102: a window touching only page 102 overlaps it.
        $this->assertSame(['C-1001'], $codes(['source_page_from' => 102, 'source_page_to' => 102]));
        $this->assertSame(['C-1001', 'C-1002'], $codes(['source_page_from' => 10, 'source_page_to' => 11]));
        // Same-row semantics: C-1001's LOW row is on page 10 while its HIGH row is on 100.
        // "HIGH within pages 1-50" must NOT match C-1001 (its HIGH row is outside the window).
        $this->assertNotContains('C-1001', $codes(['evidence' => 'HIGH', 'source_page_from' => 1, 'source_page_to' => 50]));
        $this->assertSame(['C-1001', 'C-1002'], $codes(['evidence' => 'LOW', 'source_page_from' => 1, 'source_page_to' => 50]));
        $this->readGroups($user, $f['import']->id, ['source_page_from' => 50, 'source_page_to' => 10])->assertStatus(422);
    }

    public function test_reference_like_filter_reproduces_the_dotted_leader_cluster_without_page_numbers(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $user = $this->member($f['home']);
        $codes = fn (array $q) => collect($this->readGroups($user, $f['import']->id, $q)->assertOk()->json('data.data'))->pluck('normalized_code')->sort()->values()->all();

        $this->assertSame(['C-1001', 'C-1002'], $codes(['reference_like' => 'yes']));
        $this->assertSame(['C-1002'], $codes(['best_evidence' => 'LOW', 'reference_like' => 'yes']));
        $this->assertNotContains('C-1002', $codes(['reference_like' => 'no']));
    }

    // --- 14/15. tenant isolation + authorization ---

    public function test_a_member_of_another_account_cannot_read_the_groups(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $outsider = $this->member($f['foreign']);

        $this->readGroups($outsider, $f['import']->id)->assertNotFound();
        $this->actingAs($outsider)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/C-1001")->assertNotFound();
    }

    public function test_a_read_only_member_can_read_groups(): void
    {
        $f = $this->fixture();
        $this->dataset($f);

        $this->readGroups($this->member($f['home'], 'viewer'), $f['import']->id)->assertOk();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $f = $this->fixture();
        $this->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups")->assertUnauthorized();
    }

    // --- 17/18. group detail ---

    public function test_group_detail_orders_high_then_medium_then_low_then_page_and_keeps_every_occurrence(): void
    {
        $f = $this->fixture();
        $i = $f['import'];
        $this->entry($i, 'C-8000', 'LOW', 7);
        $this->entry($i, 'C-8000', 'HIGH', 300);
        $this->entry($i, 'C-8000', 'MEDIUM', 50);
        $this->entry($i, 'C-8000', 'HIGH', 20);
        $this->entry($i, 'C-8000', 'LOW', 2);

        $detail = $this->actingAs($this->member($f['home']))->getJson("/api/v1/maintenance/document-imports/{$i->id}/code-groups/C-8000")->assertOk()->json('data');

        $this->assertSame([['HIGH', 20], ['HIGH', 300], ['MEDIUM', 50], ['LOW', 2], ['LOW', 7]], collect($detail['occurrences'])->map(fn ($o) => [$o['evidence'], $o['source_page_start']])->all());
        $this->assertSame(5, $detail['occurrence_count']);
        $this->assertCount(5, $detail['occurrences']);
        $this->assertFalse($detail['occurrences_truncated']);
    }

    public function test_group_detail_exposes_provenance_and_the_stored_excerpt_of_each_underlying_candidate(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $detail = $this->actingAs($this->member($f['home']))->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/c-1001")->assertOk()->json('data');

        $medium = collect($detail['occurrences'])->firstWhere('evidence', 'MEDIUM');
        $this->assertSame(101, $medium['source_page_start']);
        $this->assertSame(102, $medium['source_page_end']);
        $this->assertSame('NEW', $medium['collision_status']);
        $this->assertSame('DRAFT', $medium['status']);
        $this->assertNotEmpty($medium['description']);
        $this->assertTrue(collect($detail['occurrences'])->firstWhere('evidence', 'LOW')['reference_like']);
        $this->assertFalse($medium['reference_like']);
        // Every occurrence is a real, individually addressable candidate row.
        foreach ($detail['occurrences'] as $o) {
            $this->assertNotNull(MaintenanceKnowledgeEntry::find($o['id']));
        }
    }

    public function test_group_detail_of_an_unknown_or_foreign_code_is_not_found_and_never_crosses_imports(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $user = $this->member($f['home']);
        $base = "/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups";

        $this->actingAs($user)->getJson($base.'/C-9999')->assertNotFound();
        $this->actingAs($user)->getJson($base.'/C-0000')->assertNotFound();
        $detail = $this->actingAs($user)->getJson($base.'/C-1001')->assertOk()->json('data');
        $this->assertSame(3, $detail['occurrence_count']);
    }

    // --- read model is read-only + performance ---

    public function test_reading_groups_never_mutates_candidates_or_the_published_tables(): void
    {
        $f = $this->fixture();
        $this->dataset($f);
        $before = MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at', 'evidence'])->toArray();
        $user = $this->member($f['home']);

        $this->readGroups($user, $f['import']->id)->assertOk();
        $this->readGroups($user, $f['import']->id, ['best_evidence' => 'STRONG'])->assertOk();
        $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/C-1001")->assertOk();

        $this->assertSame($before, MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at', 'evidence'])->toArray());
        $this->assertSame(0, DB::table('machine_error_codes')->count());
        $this->assertSame(0, DB::table('maintenance_error_solutions')->count());
    }

    public function test_query_count_is_constant_regardless_of_how_many_groups_are_listed(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        foreach (range(1, 5) as $n) {
            $this->entry($f['import'], 'C-'.(9100 + $n), 'HIGH', $n);
        }
        $countQueries = function () use ($f, $user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->readGroups($user, $f['import']->id, ['per_page' => 50])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->readGroups($user, $f['import']->id)->assertOk(); // warm-up: exclude one-time per-process queries
        $small = $countQueries();

        foreach (range(6, 45) as $n) {
            $this->entry($f['import'], 'C-'.(9100 + $n), $n % 2 ? 'HIGH' : 'LOW', $n);
        }
        $large = $countQueries();

        // 5 groups vs 45 groups: identical number of queries => no per-group (N+1) queries.
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(12, $large);
    }
}
