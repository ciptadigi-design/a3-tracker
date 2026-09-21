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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Maintenance V1.7.2 - filter-based bulk REJECT/RESTORE triage. Two steps:
 * a read-only preview (counts + bounded structural sample + signed token), then
 * an apply that re-derives the eligible set inside a locked transaction and
 * refuses unless it is exactly the previewed set. All fixtures are synthetic.
 */
class MaintenanceFilterBulkReviewTest extends TestCase
{
    use RefreshDatabase;

    private const DOTS = 'Synthetic index line .......... 12';

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);

        return ['home' => $home, 'foreign' => $foreign, 'import' => $this->makeImport($home), 'otherImport' => $this->makeImport($home), 'foreignImport' => $this->makeImport($foreign)];
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

    private function entry(MaintenanceDocumentImport $import, string $code, string $evidence, int $page, string $status = 'DRAFT', array $overrides = []): MaintenanceKnowledgeEntry
    {
        return MaintenanceKnowledgeEntry::create(array_merge([
            'import_id' => $import->id, 'knowledge_type' => 'ERROR_CODE', 'normalized_code' => $code, 'code' => $code,
            'title' => 'Fixture title '.$code, 'description' => 'Fixture excerpt '.$code, 'evidence' => $evidence, 'collision_status' => 'NEW',
            'source_page_start' => $page, 'source_page_end' => $page, 'status' => $status,
        ], $overrides));
    }

    /** A "TOC" cluster of LOW dotted-leader rows on pages 30-37 (8 rows) plus unrelated rows. */
    private function tocDataset(array $f): array
    {
        $toc = [];
        foreach (range(30, 37) as $i => $page) {
            $toc[] = $this->entry($f['import'], 'C-'.(2000 + $i), 'LOW', $page, 'DRAFT', ['description' => self::DOTS])->id;
        }
        $body = [
            $this->entry($f['import'], 'C-2000', 'HIGH', 1300)->id,
            $this->entry($f['import'], 'C-2001', 'MEDIUM', 1301)->id,
            $this->entry($f['import'], 'C-2002', 'LOW', 1302)->id, // LOW but in the body, not a dotted leader
        ];

        return ['toc' => $toc, 'body' => $body];
    }

    private function url(string $importId, string $tail): string
    {
        return "/api/v1/maintenance/document-imports/{$importId}/entries/bulk-review/{$tail}";
    }

    private function preview(User $user, string $importId, string $action, array $filters)
    {
        return $this->actingAs($user)->postJson($this->url($importId, 'preview'), ['action' => $action, 'filters' => $filters]);
    }

    private function apply(User $user, string $importId, string $action, array $filters, ?string $token)
    {
        return $this->actingAs($user)->postJson($this->url($importId, 'apply'), ['action' => $action, 'filters' => $filters, 'confirmation_token' => $token]);
    }

    private const TOC_FILTER = ['evidence' => 'LOW', 'source_page_from' => 30, 'source_page_to' => 38, 'reference_like' => 'yes'];

    private function statusCounts(string $importId): array
    {
        return MaintenanceKnowledgeEntry::where('import_id', $importId)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    // --- 19/20. preview counts and does not mutate ---

    public function test_preview_reports_matching_eligible_and_excluded_counts_with_a_bounded_structural_sample(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        // Two matching rows that are NOT eligible for reject: one already REJECTED, one published.
        $this->entry($f['import'], 'C-2100', 'LOW', 33, 'REJECTED', ['description' => self::DOTS]);
        $this->entry($f['import'], 'C-2101', 'LOW', 34, 'APPROVED', ['description' => self::DOTS, 'published_at' => now()]);

        $data = $this->preview($this->member($f['home']), $f['import']->id, 'reject', self::TOC_FILTER)->assertOk()->json('data');

        $this->assertSame(10, $data['matching_count']);
        $this->assertSame(8, $data['eligible_count']);
        $this->assertSame(2, $data['excluded_count']);
        $this->assertSame(['DRAFT' => 0, 'REJECTED' => 1, 'APPROVED' => 0, 'PUBLISHED' => 1], $data['excluded_breakdown']);
        $this->assertTrue($data['can_apply']);
        $this->assertNotEmpty($data['confirmation_token']);
        $this->assertLessThanOrEqual(10, count($data['sample']));
        $this->assertCount(8, $data['sample']);
        foreach ($data['sample'] as $row) {
            $this->assertSame(['id', 'normalized_code', 'evidence', 'collision_status', 'source_page_start', 'source_page_end', 'status'], array_keys($row));
            $this->assertSame('DRAFT', $row['status']);
        }
        $this->assertSame('reject', $data['action']);
        $this->assertSame('LOW', $data['filters']['evidence']);
    }

    public function test_preview_never_mutates_candidates_audit_or_published_tables(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $before = MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at'])->toArray();
        $auditBefore = DB::table('governance_audit_logs')->count();

        $this->preview($this->member($f['home']), $f['import']->id, 'reject', self::TOC_FILTER)->assertOk();

        $this->assertSame($before, MaintenanceKnowledgeEntry::orderBy('id')->get(['id', 'status', 'updated_at'])->toArray());
        $this->assertSame($auditBefore, DB::table('governance_audit_logs')->count());
        $this->assertSame(0, DB::table('machine_error_codes')->count());
    }

    public function test_the_sample_is_deterministic_and_carries_no_candidate_text(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        foreach (range(1, 40) as $n) {
            $this->entry($f['import'], 'C-'.(3000 + $n), 'LOW', 31, 'DRAFT', ['description' => self::DOTS]);
        }
        $user = $this->member($f['home']);
        $a = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->assertOk();
        $b = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->assertOk();

        $this->assertCount(10, $a->json('data.sample'));
        $this->assertSame($a->json('data.sample'), $b->json('data.sample'));
        $this->assertStringNotContainsString('Synthetic index line', $a->getContent());
        $this->assertStringNotContainsString('Fixture title', $a->getContent());
    }

    public function test_preview_with_nothing_eligible_is_blocked_and_issues_no_token(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $data = $this->preview($this->member($f['home']), $f['import']->id, 'reject', ['evidence' => 'HIGH', 'source_page_from' => 9000])->assertOk()->json('data');

        $this->assertSame(0, $data['eligible_count']);
        $this->assertFalse($data['can_apply']);
        $this->assertSame('NOTHING_ELIGIBLE', $data['blocked_reason']);
        $this->assertNull($data['confirmation_token']);
    }

    public function test_preview_refuses_to_issue_a_token_above_the_transaction_bound(): void
    {
        $f = $this->fixture();
        $rows = [];
        for ($n = 0; $n < 5001; $n++) {
            $rows[] = ['id' => (string) Str::uuid(), 'import_id' => $f['import']->id, 'knowledge_type' => 'ERROR_CODE', 'normalized_code' => 'C-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'code' => 'C-X', 'title' => 't', 'evidence' => 'LOW', 'collision_status' => 'NEW', 'source_page_start' => 1, 'source_page_end' => 1, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('maintenance_knowledge_entries')->insert($chunk);
        }
        $data = $this->preview($this->member($f['home']), $f['import']->id, 'reject', ['evidence' => 'LOW'])->assertOk()->json('data');

        $this->assertSame(5001, $data['eligible_count']);
        $this->assertFalse($data['can_apply']);
        $this->assertSame('TOO_MANY_CANDIDATES', $data['blocked_reason']);
        $this->assertNull($data['confirmation_token']);
    }

    // --- 21. filter bulk reject ---

    public function test_apply_rejects_exactly_the_previewed_candidates_and_nothing_else(): void
    {
        $f = $this->fixture();
        $ids = $this->tocDataset($f);
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        $totalBefore = MaintenanceKnowledgeEntry::where('import_id', $f['import']->id)->count();

        $result = $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertOk()->json('data');

        $this->assertSame(['action' => 'reject', 'affected' => 8], $result);
        foreach ($ids['toc'] as $id) {
            $this->assertSame('REJECTED', MaintenanceKnowledgeEntry::find($id)->status);
        }
        foreach ($ids['body'] as $id) {
            $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($id)->status, 'a row outside the filter must be untouched');
        }
        // No row deleted, provenance intact.
        $this->assertSame($totalBefore, MaintenanceKnowledgeEntry::where('import_id', $f['import']->id)->count());
        $sample = MaintenanceKnowledgeEntry::find($ids['toc'][0]);
        $this->assertSame(30, $sample->source_page_start);
        $this->assertSame('C-2000', $sample->normalized_code);
    }

    // --- 22. restore ---

    public function test_apply_restores_rejected_candidates_to_draft_through_the_same_controlled_flow(): void
    {
        $f = $this->fixture();
        $ids = $this->tocDataset($f);
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertOk();

        $restore = $this->preview($user, $f['import']->id, 'restore', self::TOC_FILTER)->assertOk()->json('data');
        $this->assertSame(8, $restore['eligible_count']);
        $result = $this->apply($user, $f['import']->id, 'restore', self::TOC_FILTER, $restore['confirmation_token'])->assertOk()->json('data');

        $this->assertSame(8, $result['affected']);
        foreach ($ids['toc'] as $id) {
            $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($id)->status);
        }
        $this->assertSame(11, MaintenanceKnowledgeEntry::where('import_id', $f['import']->id)->where('status', 'DRAFT')->count());
    }

    // --- 23/24. published + cross-import protection ---

    public function test_published_and_approved_rows_are_never_touched_even_when_they_match_the_filter(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $published = $this->entry($f['import'], 'C-2200', 'LOW', 31, 'APPROVED', ['description' => self::DOTS, 'published_at' => now()]);
        $approved = $this->entry($f['import'], 'C-2201', 'LOW', 32, 'APPROVED', ['description' => self::DOTS]);
        $user = $this->member($f['home']);

        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertOk();

        $this->assertSame('APPROVED', $published->fresh()->status);
        $this->assertNotNull($published->fresh()->published_at);
        $this->assertSame('APPROVED', $approved->fresh()->status);
    }

    public function test_rows_of_other_imports_are_untouched_including_another_tenants(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $sameAccountOther = $this->entry($f['otherImport'], 'C-2000', 'LOW', 31, 'DRAFT', ['description' => self::DOTS]);
        $foreignRow = $this->entry($f['foreignImport'], 'C-2000', 'LOW', 31, 'DRAFT', ['description' => self::DOTS]);
        $user = $this->member($f['home']);

        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertOk();

        $this->assertSame('DRAFT', $sameAccountOther->fresh()->status);
        $this->assertSame('DRAFT', $foreignRow->fresh()->status);
    }

    // --- 25. stale preview ---

    public function test_apply_is_rejected_as_stale_when_an_eligible_candidate_changed_after_the_preview(): void
    {
        $f = $this->fixture();
        $ids = $this->tocDataset($f);
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');

        // Someone approves one of the previewed candidates in the meantime.
        MaintenanceKnowledgeEntry::find($ids['toc'][3])->update(['status' => 'APPROVED']);
        $before = $this->statusCounts($f['import']->id);

        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertStatus(409);
        $this->assertSame($before, $this->statusCounts($f['import']->id), 'a stale apply must change nothing');
    }

    public function test_apply_is_stale_when_a_new_matching_candidate_appears_after_the_preview(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');

        $this->entry($f['import'], 'C-2300', 'LOW', 35, 'DRAFT', ['description' => self::DOTS]);

        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertStatus(409);
        $this->assertSame(0, MaintenanceKnowledgeEntry::where('import_id', $f['import']->id)->where('status', 'REJECTED')->count());
    }

    public function test_an_expired_preview_is_rejected_as_stale(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');

        Carbon::setTestNow(now()->addMinutes(16));
        try {
            $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertStatus(409);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(0, MaintenanceKnowledgeEntry::where('import_id', $f['import']->id)->where('status', 'REJECTED')->count());
    }

    // --- 26. tampered token / fingerprint ---

    public function test_a_token_is_bound_to_the_exact_filters_action_import_and_user(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);
        $other = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');

        // Widened filter, different action, different import, different user: all fail closed, nothing changes.
        $this->apply($user, $f['import']->id, 'reject', ['evidence' => 'LOW'], $token)->assertStatus(422);
        $this->apply($user, $f['import']->id, 'restore', self::TOC_FILTER, $token)->assertStatus(422);
        $this->apply($user, $f['otherImport']->id, 'reject', self::TOC_FILTER, $token)->assertStatus(422);
        $this->apply($other, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertStatus(422);
        $this->assertSame(0, MaintenanceKnowledgeEntry::where('status', 'REJECTED')->count());
    }

    public function test_forged_or_malformed_tokens_are_rejected(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        [$payload, $sig] = explode('.', $token);
        $forgedPayload = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'i' => $f['import']->id, 'a' => 'reject', 'f' => 'x', 'u' => $user->id, 'n' => 999, 'dg' => 'x', 'exp' => now()->timestamp + 999])), '+/', '-_'), '=');

        foreach ([$payload.'.'.strrev($sig), $forgedPayload.'.'.$sig, $payload, 'garbage', '.', $payload.'.'] as $bad) {
            $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $bad)->assertStatus(422);
        }
        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, null)->assertStatus(422);
        $this->assertSame(0, MaintenanceKnowledgeEntry::where('status', 'REJECTED')->count());
    }

    public function test_the_client_supplied_affected_count_is_never_trusted(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');

        $result = $this->actingAs($user)->postJson($this->url($f['import']->id, 'apply'), ['action' => 'reject', 'filters' => self::TOC_FILTER, 'confirmation_token' => $token, 'affected' => 99999, 'affected_count' => 99999])->assertOk()->json('data');

        $this->assertSame(8, $result['affected']);
    }

    // --- filter validation ---

    public function test_a_filterless_or_unsupported_bulk_request_is_refused(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);

        $this->preview($user, $f['import']->id, 'reject', [])->assertStatus(422);
        $this->preview($user, $f['import']->id, 'reject', ['best_evidence' => 'LOW'])->assertOk(); // a real criterion
        $this->preview($user, $f['import']->id, 'reject', ['unknown_filter' => 'x', 'evidence' => 'LOW'])->assertStatus(422);
        $this->preview($user, $f['import']->id, 'reject', ['status' => 'DRAFT', 'evidence' => 'LOW'])->assertStatus(422);
        $this->preview($user, $f['import']->id, 'reject', ['review_state' => 'UNREVIEWED', 'evidence' => 'LOW'])->assertStatus(422);
        $this->preview($user, $f['import']->id, 'approve', ['evidence' => 'LOW'])->assertStatus(422);
        $this->preview($user, $f['import']->id, 'publish', ['evidence' => 'LOW'])->assertStatus(422);
        $this->preview($user, $f['import']->id, 'reject', ['evidence' => 'LOW', 'source_page_from' => 50, 'source_page_to' => 10])->assertStatus(422);
    }

    public function test_only_reject_and_restore_exist_no_approve_publish_or_delete(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);
        foreach (['approve', 'publish', 'delete', 'REJECT'] as $bad) {
            $this->apply($user, $f['import']->id, $bad, ['evidence' => 'LOW'], 'x.y')->assertStatus(422);
        }
    }

    public function test_best_evidence_criterion_targets_rows_of_low_only_codes(): void
    {
        $f = $this->fixture();
        $i = $f['import'];
        $lowOnly = $this->entry($i, 'C-5000', 'LOW', 900)->id;
        $mixedLow = $this->entry($i, 'C-5001', 'LOW', 31)->id; // LOW row of a code that ALSO has a HIGH row
        $this->entry($i, 'C-5001', 'HIGH', 1300);
        $user = $this->member($f['home']);
        $filters = ['evidence' => 'LOW', 'best_evidence' => 'LOW'];

        $data = $this->preview($user, $i->id, 'reject', $filters)->assertOk()->json('data');
        $this->assertSame(1, $data['eligible_count']);
        $this->apply($user, $i->id, 'reject', $filters, $data['confirmation_token'])->assertOk();

        $this->assertSame('REJECTED', MaintenanceKnowledgeEntry::find($lowOnly)->status);
        $this->assertSame('DRAFT', MaintenanceKnowledgeEntry::find($mixedLow)->status);
    }

    // --- 15/16. authorization ---

    public function test_a_read_only_member_cannot_preview_or_apply(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $manager = $this->member($f['home']);
        $token = $this->preview($manager, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        $viewer = $this->member($f['home'], 'viewer');

        $this->preview($viewer, $f['import']->id, 'reject', self::TOC_FILTER)->assertStatus(403);
        $this->apply($viewer, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertStatus(403);
        $this->assertSame(0, MaintenanceKnowledgeEntry::where('status', 'REJECTED')->count());
    }

    public function test_a_member_of_another_account_cannot_preview_or_apply(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $outsider = $this->member($f['foreign']);

        $this->preview($outsider, $f['import']->id, 'reject', self::TOC_FILTER)->assertStatus(403);
        $this->apply($outsider, $f['import']->id, 'reject', self::TOC_FILTER, 'x.y')->assertStatus(403);
    }

    // --- 27/28. audit ---

    public function test_audit_records_only_safe_aggregates_never_text_or_id_lists(): void
    {
        $f = $this->fixture();
        $secret = 'This exact fixture sentence must never appear in governance_audit_logs.';
        foreach (range(30, 37) as $i => $page) {
            $this->entry($f['import'], 'C-'.(2000 + $i), 'LOW', $page, 'DRAFT', ['description' => self::DOTS.' '.$secret, 'title' => 'Secret title '.$i]);
        }
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertOk();

        $log = DB::table('governance_audit_logs')->where('target_id', $f['import']->id)->where('action', 'knowledge_candidates_filter_bulk_rejected')->first();
        $this->assertNotNull($log);
        $metadata = json_decode($log->metadata, true);
        $this->assertSame(8, $metadata['changes']['affected_count']['after']);
        $this->assertSame(16, strlen($metadata['changes']['filter_fingerprint']['after']));
        $this->assertSame('evidence=LOW;reference_like=yes;source_page_from=30;source_page_to=38', $metadata['changes']['filter_summary']['after']);
        $this->assertSame($f['home']->id, $log->account_id);
        $this->assertStringNotContainsString($secret, $log->metadata);
        $this->assertStringNotContainsString('Secret title', $log->metadata);
        $this->assertStringNotContainsString('Synthetic index line', $log->metadata);
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $log->metadata, 'no candidate/import ID list in audit metadata');
        $this->assertLessThan(500, strlen($log->metadata));
    }

    public function test_restore_is_audited_as_a_distinct_filter_event(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $user = $this->member($f['home']);
        $t1 = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');
        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $t1)->assertOk();
        $t2 = $this->preview($user, $f['import']->id, 'restore', self::TOC_FILTER)->json('data.confirmation_token');
        $this->apply($user, $f['import']->id, 'restore', self::TOC_FILTER, $t2)->assertOk();

        $actions = DB::table('governance_audit_logs')->where('target_id', $f['import']->id)->orderBy('created_at')->pluck('action')->all();
        $this->assertContains('knowledge_candidates_filter_bulk_rejected', $actions);
        $this->assertContains('knowledge_candidates_filter_bulk_restored', $actions);
    }

    // --- 29/30. regressions: V1.7 endpoint and the publish path ---

    public function test_the_existing_id_based_bulk_endpoint_and_its_100_id_cap_still_work(): void
    {
        $f = $this->fixture();
        $ids = $this->tocDataset($f);
        $user = $this->member($f['home']);

        $this->actingAs($user)->postJson("/api/v1/maintenance/document-imports/{$f['import']->id}/entries/bulk-review", ['entry_ids' => array_slice($ids['toc'], 0, 2), 'action' => 'reject'])
            ->assertOk()->assertJsonPath('data.affected', 2);
        $tooMany = array_map(fn () => (string) Str::uuid(), range(1, 101));
        $this->actingAs($user)->postJson("/api/v1/maintenance/document-imports/{$f['import']->id}/entries/bulk-review", ['entry_ids' => $tooMany, 'action' => 'reject'])->assertStatus(422);
    }

    public function test_filter_bulk_review_never_invokes_the_publish_service_or_writes_the_published_tables(): void
    {
        $f = $this->fixture();
        $this->tocDataset($f);
        $publish = $this->mock(MaintenanceKnowledgePublishService::class);
        $publish->shouldNotReceive('publish');
        $user = $this->member($f['home']);
        $token = $this->preview($user, $f['import']->id, 'reject', self::TOC_FILTER)->json('data.confirmation_token');

        $this->apply($user, $f['import']->id, 'reject', self::TOC_FILTER, $token)->assertOk();

        $this->assertSame(0, DB::table('machine_error_codes')->count());
        $this->assertSame(0, DB::table('maintenance_error_solutions')->count());
        $this->assertSame(0, DB::table('maintenance_document_references')->count());
        $this->assertSame(0, MaintenanceKnowledgeEntry::whereNotNull('published_at')->count());
        $this->assertSame(0, MaintenanceKnowledgeEntry::where('status', 'APPROVED')->count());
    }
}
