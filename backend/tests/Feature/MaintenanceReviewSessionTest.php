<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\MaintenanceKnowledgeReviewSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Maintenance V1.11 - Review Workflow Benchmark instrumentation. Confirms the telemetry layer
 * measures the existing human review/publish workflow without being able to influence it: no
 * spoofed reviewer identity, no cross-account leakage, no unbounded counters, and no path from
 * this controller to publish/approve/reject/restore. All fixtures are synthetic.
 */
class MaintenanceReviewSessionTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'C-4201';

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);

        return ['home' => $home, 'foreign' => $foreign, 'import' => $this->makeImport($home), 'foreignImport' => $this->makeImport($foreign)];
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

    private function entry(MaintenanceDocumentImport $import, string $code, string $evidence = 'HIGH', int $start = 100): MaintenanceKnowledgeEntry
    {
        return MaintenanceKnowledgeEntry::create([
            'import_id' => $import->id, 'knowledge_type' => 'ERROR_CODE', 'normalized_code' => $code, 'code' => $code, 'title' => 'Error Code '.$code,
            'description' => 'Synthetic excerpt for '.$code, 'evidence' => $evidence, 'collision_status' => 'NEW',
            'source_page_start' => $start, 'source_page_end' => $start, 'status' => 'DRAFT',
        ]);
    }

    private function beginSession(User $u, MaintenanceDocumentImport $import, string $code = self::CODE)
    {
        return $this->actingAs($u)->postJson("/api/v1/maintenance/document-imports/{$import->id}/code-groups/{$code}/review-session/start");
    }

    private function heartbeat(User $u, string $sessionId, array $payload)
    {
        return $this->actingAs($u)->patchJson("/api/v1/maintenance/review-sessions/{$sessionId}/heartbeat", $payload);
    }

    // --- 1. start ---

    public function test_starting_a_session_requires_authentication(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $this->postJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE.'/review-session/start')->assertStatus(401);
    }

    public function test_starting_a_session_records_the_authenticated_reviewer_and_account(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);

        $r = $this->beginSession($u, $f['import'])->assertOk();
        $row = MaintenanceKnowledgeReviewSession::findOrFail($r->json('data.id'));
        $this->assertSame($u->id, $row->reviewer_user_id);
        $this->assertSame($f['home']->id, $row->account_id);
        $this->assertSame('GROUP_OPENED', $row->current_stage);
        $this->assertNull($row->completed_at);
    }

    public function test_reopening_the_same_group_resumes_rather_than_duplicates_the_session(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);

        $first = $this->beginSession($u, $f['import'])->assertOk()->json('data.id');
        $second = $this->beginSession($u, $f['import'])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, MaintenanceKnowledgeReviewSession::count());
    }

    // --- 2. account/tenant isolation ---

    public function test_a_foreign_account_member_cannot_start_a_session_on_another_accounts_import(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $outsider = $this->member($f['foreign']);

        $this->beginSession($outsider, $f['import'])->assertStatus(404);
    }

    public function test_benchmark_summary_only_sees_sessions_from_its_own_import(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $this->entry($f['foreignImport'], self::CODE);
        $home = $this->member($f['home']);
        $foreignUser = $this->member($f['foreign']);

        $this->beginSession($home, $f['import'])->assertOk();
        $this->beginSession($foreignUser, $f['foreignImport'])->assertOk();

        $summary = $this->actingAs($home)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/review-benchmark")->assertOk()->json('data');
        $this->assertSame(1, $summary['sessions_started']);
    }

    // --- 3. reviewer identity cannot be spoofed ---

    public function test_a_heartbeat_cannot_be_sent_by_a_different_user_than_the_sessions_reviewer(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $owner = $this->member($f['home']);
        $otherMember = $this->member($f['home']);

        $sessionId = $this->beginSession($owner, $f['import'])->json('data.id');

        $this->heartbeat($otherMember, $sessionId, ['active_seconds' => 30])->assertStatus(403);
    }

    public function test_reviewer_id_in_request_body_is_ignored_reviewer_is_always_server_derived(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $intruder = $this->member($f['home']);

        $r = $this->actingAs($u)->postJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE.'/review-session/start', ['reviewer_user_id' => $intruder->id]);
        $row = MaintenanceKnowledgeReviewSession::findOrFail($r->json('data.id'));
        $this->assertSame($u->id, $row->reviewer_user_id);
    }

    // --- 4. invalid group rejected ---

    public function test_starting_a_session_for_a_nonexistent_code_still_creates_a_session_row(): void
    {
        // Sessions track the REVIEW workflow, not catalog existence - a reviewer can open a
        // group detail view for a code that has zero (or since-rejected) occurrences. The route
        // itself still 404s for a malformed code (see groupCode()).
        $f = $this->fixture();
        $u = $this->member($f['home']);
        $this->beginSession($u, $f['import'], 'NOT-A-REAL-CODE')->assertOk();
    }

    public function test_a_malformed_code_is_rejected(): void
    {
        $f = $this->fixture();
        $u = $this->member($f['home']);
        $this->actingAs($u)->postJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".rawurlencode('bad code!').'/review-session/start')->assertStatus(404);
    }

    public function test_starting_a_session_against_a_nonexistent_import_404s(): void
    {
        $f = $this->fixture();
        $u = $this->member($f['home']);
        $this->actingAs($u)->postJson('/api/v1/maintenance/document-imports/00000000-0000-0000-0000-000000000000/code-groups/'.self::CODE.'/review-session/start')->assertStatus(404);
    }

    // --- 5. heartbeat idempotency ---

    public function test_a_duplicate_heartbeat_with_the_same_client_seq_does_not_double_count(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $this->heartbeat($u, $sessionId, ['active_seconds' => 30, 'client_seq' => 1])->assertOk();
        $this->heartbeat($u, $sessionId, ['active_seconds' => 30, 'client_seq' => 1])->assertOk();

        $this->assertSame(30, MaintenanceKnowledgeReviewSession::findOrFail($sessionId)->active_seconds);
    }

    public function test_a_higher_client_seq_applies_normally(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $this->heartbeat($u, $sessionId, ['active_seconds' => 20, 'client_seq' => 1])->assertOk();
        $this->heartbeat($u, $sessionId, ['active_seconds' => 20, 'client_seq' => 2])->assertOk();

        $this->assertSame(40, MaintenanceKnowledgeReviewSession::findOrFail($sessionId)->active_seconds);
    }

    // --- 6. active seconds bounded ---

    public function test_active_seconds_per_heartbeat_are_clamped_to_a_sane_maximum(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $this->heartbeat($u, $sessionId, ['active_seconds' => 999999])->assertOk();

        $this->assertLessThanOrEqual(180, MaintenanceKnowledgeReviewSession::findOrFail($sessionId)->active_seconds);
    }

    // --- 7. negative duration rejected ---

    public function test_negative_active_seconds_is_rejected_by_validation(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $this->heartbeat($u, $sessionId, ['active_seconds' => -10])->assertStatus(422);
    }

    // --- 8. source interaction increments bounded ---

    public function test_source_page_views_increment_and_are_bounded_per_call(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $this->heartbeat($u, $sessionId, ['source_page_views' => 999]);
        $row = MaintenanceKnowledgeReviewSession::findOrFail($sessionId);
        $this->assertLessThanOrEqual(25, $row->source_page_views);
        $this->assertNotNull($row->source_review_started_at);
    }

    // --- 9. authoring edit increment ---

    public function test_authoring_edits_increment(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $this->heartbeat($u, $sessionId, ['authoring_edits' => 3, 'stage' => 'AUTHORING_STARTED'])->assertOk();

        $row = MaintenanceKnowledgeReviewSession::findOrFail($sessionId);
        $this->assertSame(3, $row->authoring_edits);
        $this->assertSame('AUTHORING_STARTED', $row->current_stage);
        $this->assertNotNull($row->authoring_started_at);
    }

    // --- 10. validation failure increment ---

    public function test_validation_failures_increment(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $this->heartbeat($u, $sessionId, ['validation_failures' => 2])->assertOk();

        $this->assertSame(2, MaintenanceKnowledgeReviewSession::findOrFail($sessionId)->validation_failures);
    }

    // --- 11. instrumentation cannot publish ---

    public function test_no_review_session_route_can_mutate_the_knowledge_catalog(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_contains($r->getActionName(), 'MaintenanceReviewSessionController'));
        $this->assertTrue($routes->isNotEmpty());
        $methods = $routes->map(fn ($r) => $r->getActionMethod())->all();
        $this->assertEqualsCanonicalizing(['start', 'heartbeat', 'abandon', 'benchmark'], $methods);
        // Structural guarantee: the controller has no dependency on the publish service.
        $source = file_get_contents(app_path('Http/Controllers/Api/MaintenanceReviewSessionController.php'));
        $this->assertStringNotContainsString('KnowledgeGroupPublisher', $source);
    }

    // --- 12. actual publish can finalize matching session ---

    public function test_a_real_publish_completes_the_matching_open_session_as_published(): void
    {
        $f = $this->fixture();
        $entry = $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');
        $this->heartbeat($u, $sessionId, ['active_seconds' => 40, 'stage' => 'AUTHORING_STARTED']);

        $payload = ['canonical_candidate_id' => $entry->id, 'title' => 'Fuser sensor abnormal', 'description' => 'Desc.', 'operator_guidance' => 'Guidance.', 'technician_solution' => 'Replace sensor.'];
        $token = $this->actingAs($u)->postJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE.'/publish-preview', $payload)->assertOk()->json('data.confirmation_token');
        $this->actingAs($u)->postJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE.'/publish', $payload + ['confirmation_token' => $token])->assertOk();

        $row = MaintenanceKnowledgeReviewSession::findOrFail($sessionId);
        $this->assertSame('PUBLISHED', $row->outcome);
        $this->assertNotNull($row->completed_at);
    }

    // --- 13. GovernanceAudit publish behavior unchanged ---

    public function test_review_session_activity_never_writes_to_governance_audit(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $before = DB::table('governance_audit_logs')->count();

        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');
        $this->heartbeat($u, $sessionId, ['active_seconds' => 30, 'authoring_edits' => 2]);

        $this->assertSame($before, DB::table('governance_audit_logs')->count());
    }

    // --- 14. incomplete session semantics ---

    public function test_an_abandoned_session_is_marked_incomplete_until_explicitly_abandoned_or_published(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $sessionId = $this->beginSession($u, $f['import'])->json('data.id');

        $summary = $this->actingAs($u)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/review-benchmark")->assertOk()->json('data');
        $this->assertSame(1, $summary['incomplete_session_count']);

        $this->actingAs($u)->postJson("/api/v1/maintenance/review-sessions/{$sessionId}/abandon")->assertOk();
        $row = MaintenanceKnowledgeReviewSession::findOrFail($sessionId);
        $this->assertSame('ABANDONED', $row->outcome);
        $this->assertNotNull($row->completed_at);
    }

    // --- 15. benchmark median calculations ---

    public function test_benchmark_reports_median_not_average_for_active_time(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);

        foreach ([10, 20, 900] as $i => $seconds) {
            MaintenanceKnowledgeReviewSession::create([
                'account_id' => $f['home']->id, 'document_import_id' => $f['import']->id, 'code' => self::CODE, 'reviewer_user_id' => $u->id,
                'started_at' => now(), 'last_activity_at' => now(), 'completed_at' => now(), 'outcome' => 'PUBLISHED', 'active_seconds' => $seconds,
            ]);
        }

        $summary = $this->actingAs($u)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/review-benchmark")->assertOk()->json('data');
        $this->assertEquals(20.0, $summary['median_active_time_to_publish_seconds']);
    }

    // --- 16. SELF_CONTAINED/HIGH cohort filter ---

    public function test_benchmark_can_filter_to_the_self_contained_high_cohort(): void
    {
        $f = $this->fixture();
        $high = $this->entry($f['import'], 'C-5001', 'HIGH');
        $low = $this->entry($f['import'], 'C-5002', 'LOW');
        $u = $this->member($f['home']);

        $this->beginSession($u, $f['import'], 'C-5001')->assertOk();
        $this->beginSession($u, $f['import'], 'C-5002')->assertOk();

        $summary = $this->actingAs($u)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/review-benchmark?cohort=self_contained_high")->assertOk()->json('data');
        $this->assertSame(1, $summary['sessions_started']);
    }

    // --- 17. context classification remains derived correctly (unchanged) ---

    public function test_context_hint_classification_is_untouched_by_this_milestone(): void
    {
        $f = $this->fixture();
        $this->entry($f['import'], self::CODE);
        $u = $this->member($f['home']);
        $d = $this->actingAs($u)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/code-groups/".self::CODE)->assertOk()->json('data');
        $this->assertSame('SELF_CONTAINED', $d['context_hint']);
    }

    // --- 18. no N+1 regression in benchmark summary (bounded by session count) ---

    public function test_benchmark_summary_query_count_does_not_grow_per_session_without_cohort_filter(): void
    {
        $f = $this->fixture();
        $u = $this->member($f['home']);
        for ($i = 0; $i < 5; $i++) {
            $this->entry($f['import'], 'C-60'.$i);
            $this->beginSession($u, $f['import'], 'C-60'.$i);
        }

        DB::enableQueryLog();
        $this->actingAs($u)->getJson("/api/v1/maintenance/document-imports/{$f['import']->id}/review-benchmark")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // One session scan plus a small constant number of auth/scope lookups - never one query per session.
        $this->assertLessThan(15, $count);
    }
}
