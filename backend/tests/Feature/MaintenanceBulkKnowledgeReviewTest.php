<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use App\Services\MaintenanceKnowledgePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Maintenance V1.7 - Bulk Knowledge Review & Triage. The real Production
 * V1.6.1 acceptance run produced 2680 draft candidates in one import -
 * reviewing them one at a time is impractical. This endpoint lets a
 * reviewer bulk-reject (or restore) many DRAFT/REJECTED candidates in one
 * synchronous, transactional, set-based request.
 *
 * Deliberately narrow: no bulk approve, no bulk publish, never touches an
 * APPROVED row (which is exactly how a "published" entry is represented in
 * this domain - status stays APPROVED with published_at set, there is no
 * separate PUBLISHED status value) - see BULK_TRANSITIONS' own docblock.
 */
class MaintenanceBulkKnowledgeReviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $document = MaintenanceDocument::create(['account_id' => $home->id, 'title' => 'Fixture Service Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $import = MaintenanceDocumentImport::create(['document_id' => $document->id, 'import_type' => 'PDF_EXTRACTION', 'status' => 'REVIEW', 'processing_version' => 2, 'candidate_count' => 0]);
        $foreignDocument = MaintenanceDocument::create(['account_id' => $foreign->id, 'title' => 'Foreign Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $foreignImport = MaintenanceDocumentImport::create(['document_id' => $foreignDocument->id, 'import_type' => 'PDF_EXTRACTION', 'status' => 'REVIEW', 'processing_version' => 2, 'candidate_count' => 0]);

        return compact('home', 'foreign', 'document', 'import', 'foreignDocument', 'foreignImport');
    }

    private function member(Account $account, string $role = 'owner'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    /** @return list<string> entry IDs */
    private function makeEntries(MaintenanceDocumentImport $import, int $count, string $status = 'DRAFT', array $overrides = []): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = MaintenanceKnowledgeEntry::create(array_merge([
                'import_id' => $import->id,
                'knowledge_type' => 'ERROR_CODE',
                'normalized_code' => 'C-'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'code' => 'C-'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'title' => 'Fixture title '.$i,
                'description' => 'Fixture bounded context excerpt only, never real manual prose.',
                'evidence' => 'LOW',
                'collision_status' => 'NEW',
                'source_page_start' => 10 + $i,
                'source_page_end' => 10 + $i,
                'status' => $status,
            ], $overrides));
            $ids[] = $entry->id;
        }

        return $ids;
    }

    private function bulkReview(User $user, string $importId, array $entryIds, string $action)
    {
        return $this->actingAs($user)->postJson("/api/v1/maintenance/document-imports/{$importId}/entries/bulk-review", [
            'entry_ids' => $entryIds,
            'action' => $action,
        ]);
    }

    // --- 1/2/3. manager bulk-rejects DRAFT candidates; rows remain in DB; provenance unchanged ---

    public function test_manager_bulk_rejects_draft_candidates_and_rows_remain_with_provenance_intact(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 5);

        $response = $this->bulkReview($user, $f['import']->id, $ids, 'reject')->assertOk();
        $this->assertSame(['action' => 'reject', 'affected' => 5], $response->json('data'));

        $entries = MaintenanceKnowledgeEntry::whereIn('id', $ids)->get();
        $this->assertCount(5, $entries, 'rejecting must never delete rows');
        foreach ($entries as $entry) {
            $this->assertSame('REJECTED', $entry->status);
            $this->assertNotNull($entry->normalized_code);
            $this->assertNotNull($entry->source_page_start);
            $this->assertNotNull($entry->description);
            $this->assertSame('LOW', $entry->evidence);
            $this->assertSame('NEW', $entry->collision_status);
        }
    }

    // --- 4/19. publication tables untouched, publish service unaffected ---

    public function test_publication_tables_remain_untouched_by_bulk_reject(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 10);

        $this->bulkReview($user, $f['import']->id, $ids, 'reject')->assertOk();

        $this->assertSame(0, DB::table('machine_error_codes')->count());
        $this->assertSame(0, DB::table('maintenance_error_solutions')->count());
        $this->assertSame(0, DB::table('maintenance_document_references')->count());
    }

    public function test_publish_service_is_never_invoked_by_bulk_review(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $approved = $this->makeEntries($f['import'], 1, 'APPROVED', ['approved_by' => $user->id])[0];

        // APPROVED is never a valid bulk source status - proves this by
        // asserting rejection, not by mocking the publish service (which
        // this endpoint must never even reference).
        $this->bulkReview($user, $f['import']->id, [$approved], 'reject')->assertStatus(409);

        $entry = MaintenanceKnowledgeEntry::find($approved);
        $this->assertSame('APPROVED', $entry->status);
        $this->assertNull($entry->published_at);
    }

    public function test_an_already_published_entry_can_never_be_reached_by_bulk_review(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $approvedId = $this->makeEntries($f['import'], 1, 'APPROVED', ['approved_by' => $user->id, 'code' => 'C-9999', 'normalized_code' => 'C-9999'])[0];
        $entry = MaintenanceKnowledgeEntry::find($approvedId);
        app(MaintenanceKnowledgePublishService::class)->publish($entry, $user);

        $this->bulkReview($user, $f['import']->id, [$approvedId], 'reject')->assertStatus(409);

        $entry->refresh();
        $this->assertSame('APPROVED', $entry->status);
        $this->assertNotNull($entry->published_at, 'sanity: entry really is published');
        $this->assertSame(1, DB::table('machine_error_codes')->count(), 'the earlier publish must be untouched');
    }

    // --- 5/6. bulk action audited, raw text never in audit metadata ---

    public function test_bulk_reject_is_audited_without_raw_candidate_text(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $secretText = 'This exact fixture sentence must never appear in governance_audit_logs.';
        $ids = $this->makeEntries($f['import'], 3, 'DRAFT', ['description' => $secretText]);

        $this->bulkReview($user, $f['import']->id, $ids, 'reject')->assertOk();

        $log = DB::table('governance_audit_logs')->where('target_id', $f['import']->id)->where('action', 'knowledge_candidates_bulk_rejected')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('"after":3', $log->metadata);
        $this->assertStringNotContainsString($secretText, $log->metadata);
    }

    // --- 7. non-manager forbidden ---

    public function test_a_member_without_management_capability_cannot_bulk_review(): void
    {
        $f = $this->fixture();
        $viewer = $this->member($f['home'], 'viewer');
        $ids = $this->makeEntries($f['import'], 2);

        $this->bulkReview($viewer, $f['import']->id, $ids, 'reject')->assertStatus(403);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[0])->status);
    }

    // --- 8. foreign tenant forbidden ---

    public function test_a_foreign_account_member_cannot_bulk_review_another_accounts_import(): void
    {
        $f = $this->fixture();
        $foreignUser = $this->member($f['foreign']);
        $ids = $this->makeEntries($f['import'], 2);

        $this->bulkReview($foreignUser, $f['import']->id, $ids, 'reject')->assertStatus(403);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[0])->status);
    }

    // --- 9/10. foreign-import entry rejected, mixed-import IDs produce zero mutation ---

    public function test_entries_from_a_different_import_cause_the_whole_request_to_fail_with_zero_mutation(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ownIds = $this->makeEntries($f['import'], 2);
        $foreignIds = $this->makeEntries($f['foreignImport'], 1);

        $this->bulkReview($user, $f['import']->id, array_merge($ownIds, $foreignIds), 'reject')->assertStatus(409);

        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ownIds[0])->status, 'zero mutation - even the entries that WERE eligible must not be touched');
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ownIds[1])->status);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($foreignIds[0])->status);
    }

    // --- 11. unsupported action rejected ---

    public function test_an_unsupported_action_is_rejected(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 1);

        $this->bulkReview($user, $f['import']->id, $ids, 'publish')->assertStatus(422);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[0])->status);
    }

    // --- 12. empty entry list rejected ---

    public function test_an_empty_entry_list_is_rejected(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);

        $this->bulkReview($user, $f['import']->id, [], 'reject')->assertStatus(422);
    }

    // --- 13. duplicate IDs handled safely ---

    public function test_duplicate_ids_in_the_request_are_handled_safely_not_double_counted(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 2);

        $response = $this->bulkReview($user, $f['import']->id, [$ids[0], $ids[0], $ids[1]], 'reject')->assertOk();

        $this->assertSame(2, $response->json('data.affected'));
        $this->assertSame('REJECTED', MaintenanceKnowledgeEntry::find($ids[0])->status);
        $this->assertSame('REJECTED', MaintenanceKnowledgeEntry::find($ids[1])->status);
    }

    // --- 14. batch limit enforced ---

    public function test_batch_limit_is_enforced(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 101);

        $this->bulkReview($user, $f['import']->id, $ids, 'reject')->assertStatus(422);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[0])->status, 'a rejected-for-batch-size request must mutate nothing');
    }

    public function test_batch_limit_boundary_of_exactly_one_hundred_is_accepted(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 100);

        $this->bulkReview($user, $f['import']->id, $ids, 'reject')->assertOk()->assertJsonPath('data.affected', 100);
    }

    // --- 15. stale/ineligible status produces atomic conflict ---

    public function test_a_stale_already_rejected_entry_in_the_selection_causes_atomic_conflict(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 3);
        // Simulates a concurrent reviewer having already rejected one entry
        // between this reviewer's page load and their bulk submit.
        MaintenanceKnowledgeEntry::whereKey($ids[1])->update(['status' => 'REJECTED']);

        $this->bulkReview($user, $f['import']->id, $ids, 'reject')->assertStatus(409);

        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[0])->status, 'atomic - the still-eligible entries must not be silently mutated either');
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[2])->status);
    }

    // --- 16. filtered list reflects mutation afterward ---

    public function test_filtered_listing_reflects_the_bulk_mutation_afterward(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 4);

        $this->bulkReview($user, $f['import']->id, array_slice($ids, 0, 2), 'reject')->assertOk();

        $draft = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/entries?status=DRAFT")->assertOk();
        $rejected = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/entries?status=REJECTED")->assertOk();
        $this->assertSame(2, $draft->json('data.total'));
        $this->assertSame(2, $rejected->json('data.total'));
    }

    // --- 17/18. evidence counts truthful, candidate_count on import not reduced ---

    public function test_evidence_summary_and_import_candidate_count_are_unaffected_by_rejection(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 6);
        $f['import']->update(['candidate_count' => 6]);

        $this->bulkReview($user, $f['import']->id, array_slice($ids, 0, 4), 'reject')->assertOk();

        $show = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}")->assertOk();
        $this->assertSame(6, $show->json('data.candidate_count'), 'candidate_count must not be reduced merely because entries were rejected');
        $this->assertSame(6, $show->json('data.evidence_summary.LOW'), 'evidence_summary counts all entries regardless of review status');
    }

    // --- 20. restore-to-draft ---

    public function test_manager_can_restore_rejected_candidates_to_draft(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 3, 'REJECTED');

        $response = $this->bulkReview($user, $f['import']->id, $ids, 'restore')->assertOk();

        $this->assertSame(3, $response->json('data.affected'));
        foreach ($ids as $id) {
            $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($id)->status);
        }
    }

    public function test_restore_cannot_be_applied_to_a_draft_entry(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 1, 'DRAFT');

        $this->bulkReview($user, $f['import']->id, $ids, 'restore')->assertStatus(409);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[0])->status);
    }

    public function test_restore_is_audited_as_a_distinct_event(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 2, 'REJECTED');

        $this->bulkReview($user, $f['import']->id, $ids, 'restore')->assertOk();

        $this->assertDatabaseHas('governance_audit_logs', ['target_id' => $f['import']->id, 'action' => 'knowledge_candidates_bulk_restored']);
    }

    // --- non-UUID / malformed entry_ids are rejected safely ---

    public function test_a_non_uuid_entry_id_is_rejected_by_validation(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);

        $this->bulkReview($user, $f['import']->id, ['not-a-real-uuid'], 'reject')->assertStatus(422);
    }

    public function test_a_nonexistent_but_well_formed_uuid_causes_zero_mutation_conflict(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $ids = $this->makeEntries($f['import'], 1);

        $this->bulkReview($user, $f['import']->id, [$ids[0], (string) Str::uuid()], 'reject')->assertStatus(409);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($ids[0])->status);
    }
}
