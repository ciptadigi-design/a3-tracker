<?php

namespace Tests\Feature;

use App\Jobs\ProcessMaintenanceKnowledgeJob;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MachineErrorCode;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentExtraction;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceDocumentPage;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\User;
use App\Services\KnowledgeProcessing\MaintenanceKnowledgeProcessingService;
use App\Services\MaintenanceKnowledgePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Maintenance V1.6 - Extracted Knowledge Processing. Turns a COMPLETED
 * extraction's already-extracted pages (maintenance_document_pages) into
 * draft maintenance_knowledge_entries candidates via a deterministic
 * detector (PdfKnowledgeCandidateDetector/ErrorCodeNormalizer) - no PDF/OCR
 * involved here at all, so fixtures are plain synthetic raw_text rows, never
 * a real PDF and never real Konica manual content.
 *
 * The detection format itself (dash-required "C-DDDD", excluding bare
 * "CDDDD") was determined by read-only sampling of the real Production
 * extraction before writing the detector - see
 * PdfKnowledgeCandidateDetector's own docblock and
 * docs/maintenance/V1.6_KNOWLEDGE_PROCESSING.md for the evidence (patterns
 * only, no manual text retained).
 */
class MaintenanceKnowledgeProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $document = MaintenanceDocument::create(['account_id' => $home->id, 'title' => 'Fixture Service Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        return compact('home', 'foreign', 'document');
    }

    private function member(Account $account, string $role = 'owner'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function completedExtraction(MaintenanceDocument $document, array $pages, ?Carbon $completedAt = null): MaintenanceDocumentExtraction
    {
        foreach ($pages as $pageNumber => $text) {
            MaintenanceDocumentPage::create(['document_id' => $document->id, 'page_number' => $pageNumber, 'raw_text' => $text, 'metadata' => ['char_count' => mb_strlen($text)]]);
        }
        $completedAt ??= now();

        return MaintenanceDocumentExtraction::create([
            'document_id' => $document->id,
            'status' => 'COMPLETED',
            'total_pages' => count($pages),
            'processed_pages' => count($pages),
            'started_at' => $completedAt->copy()->subMinute(),
            'completed_at' => $completedAt,
        ]);
    }

    // --- 1. processing requires a COMPLETED extraction ---

    public function test_processing_requires_a_completed_extraction(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);

        $this->expectException(ConflictHttpException::class);
        app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
    }

    // --- 2/3/4. code detection, normalization, obvious false-positive rejection ---

    public function test_detects_dash_coded_pages_but_rejects_the_bare_model_number_false_positive(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [
            1 => "bizhub PRESS C1070\nTroubleshooting\nC - 1001\nCause:\nFixture cause only.\nAction:\nFixture action only.",
            2 => 'bizhub PRESS C1070 running header only, no code section here at all.',
        ]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entries = MaintenanceKnowledgeEntry::where('import_id', $import->id)->get();
        $this->assertCount(1, $entries, 'only the real dash-coded entry should be detected - the C1070 model number header must never become a candidate');
        $this->assertSame('C-1001', $entries[0]->normalized_code);
        $this->assertSame('C-1001', $entries[0]->code);
    }

    public function test_normalizes_whitespace_variants_of_the_same_dash_code(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [
            1 => "C-2801 first.\n",
            2 => "C - 2801 second occurrence, different page.\n",
            3 => "C-  2801 third occurrence.\n",
        ]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $codes = MaintenanceKnowledgeEntry::where('import_id', $import->id)->pluck('normalized_code')->unique();
        $this->assertSame(['C-2801'], $codes->values()->all());
    }

    // --- 5. multiple codes on one page ---

    public function test_multiple_distinct_codes_on_one_page_each_produce_a_candidate(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [
            1 => "C-1001\nCause:\nFirst fixture cause.\nAction:\nFirst fixture action.\n\nC-1002\nCause:\nSecond fixture cause.\nAction:\nSecond fixture action.",
        ]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $codes = MaintenanceKnowledgeEntry::where('import_id', $import->id)->pluck('normalized_code')->sort()->values();
        $this->assertSame(['C-1001', 'C-1002'], $codes->all());
    }

    // --- 6. repeated code on the same page deduplicates ---

    public function test_the_same_code_repeated_on_one_page_deduplicates_to_a_single_candidate(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [
            1 => "C-1001 mentioned once.\nSome filler text.\nC-1001 mentioned again on the same page.",
        ]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $this->assertSame(1, MaintenanceKnowledgeEntry::where('import_id', $import->id)->count());
    }

    // --- 7. bounded multi-page context (code spanning adjacent pages) ---

    public function test_a_code_near_the_end_of_a_page_spans_into_the_next_page_when_no_new_code_starts_it(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $longFiller = str_repeat('filler ', 40);
        $this->completedExtraction($f['document'], [
            1 => $longFiller."C-3001\nCause:\n",
            2 => 'Fixture action continues here with no new code at the top of this page.',
            3 => 'C-9999 this page starts a brand new code and must not be swallowed by page 1.',
        ]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->where('normalized_code', 'C-3001')->firstOrFail();
        $this->assertSame(1, $entry->source_page_start);
        $this->assertSame(2, $entry->source_page_end, 'continuation must extend exactly one adjacent page, never further');
        $this->assertSame('1-2', $entry->page_reference);
    }

    // --- 8/9. chunk processing and processing progress ---

    public function test_chunk_processing_dispatches_the_next_chunk_and_reports_real_progress(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $pages = [];
        for ($i = 1; $i <= 5; $i++) {
            $pages[$i] = "Page $i filler with no code.";
        }
        $this->completedExtraction($f['document'], $pages);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        Queue::assertPushed(ProcessMaintenanceKnowledgeJob::class, fn ($job) => $job->importId === $import->id && $job->fromPage === 1);

        $result = app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 2);
        $this->assertSame(2, $result['to_page']);
        $this->assertFalse($result['done']);
        $this->assertSame(2, $import->fresh()->pages_processed);

        $result = app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 3, 2);
        $this->assertSame(4, $result['to_page']);
        $this->assertFalse($result['done']);

        $result = app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 5, 2);
        $this->assertSame(5, $result['to_page']);
        $this->assertTrue($result['done']);
        $this->assertSame(5, $import->fresh()->pages_processed);
    }

    // --- 10. interrupted chunk can safely resume (re-running the same chunk range is safe) ---

    public function test_rerunning_the_same_chunk_range_does_not_duplicate_candidates_or_progress(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => "C-1001\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 1);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 1); // simulates a retried/duplicated chunk job

        $this->assertSame(1, MaintenanceKnowledgeEntry::where('import_id', $import->id)->count());
        $this->assertSame(1, $import->fresh()->candidate_count);
    }

    // --- 11. full rerun (startProcessing called twice) is idempotent ---

    public function test_calling_start_processing_twice_for_the_same_extraction_reuses_the_same_import(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => "C-1001\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $first = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        $second = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MaintenanceDocumentImport::where('document_id', $f['document']->id)->count());
    }

    // --- 12. a new extraction is processed as a separate, distinguishable run ---

    public function test_a_new_extraction_gets_its_own_distinct_import(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => 'no code here'], now()->subMinutes(10));

        Queue::fake();
        $firstImport = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);

        // A second, later extraction for the same document.
        MaintenanceDocumentPage::where('document_id', $f['document']->id)->delete();
        $this->completedExtraction($f['document'], [1 => 'C-5001 second extraction content'], now());
        $secondImport = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);

        $this->assertNotSame($firstImport->id, $secondImport->id);
        $this->assertNotSame($firstImport->extraction_id, $secondImport->extraction_id);
    }

    // --- 13. an already-reviewed candidate is never silently overwritten by reprocessing ---

    public function test_reprocessing_never_overwrites_an_already_approved_candidate(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => "C-1001\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->firstOrFail();
        $entry->update(['status' => 'APPROVED', 'approved_by' => $user->id, 'title' => 'Human-edited title']);

        // Re-run the same chunk (as a genuine reprocessing attempt would).
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry->refresh();
        $this->assertSame('APPROVED', $entry->status);
        $this->assertSame('Human-edited title', $entry->title, 'reprocessing must never overwrite human-reviewed content');
        $this->assertSame(1, MaintenanceKnowledgeEntry::where('import_id', $import->id)->count());
    }

    // --- 14. existing error-code collision classified correctly (NEW / EXISTING / POTENTIAL_UPDATE) ---

    public function test_collision_status_is_new_when_no_matching_error_code_exists(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => "C-1001\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->firstOrFail();
        $this->assertSame('NEW', $entry->collision_status);
    }

    public function test_collision_status_is_existing_when_a_matching_error_code_already_has_content(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        MachineErrorCode::create(['account_id' => $f['home']->id, 'code' => 'C-1001', 'title' => 'Already documented', 'manufacturer_description' => 'Already has real content.']);
        $this->completedExtraction($f['document'], [1 => "C-1001\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->firstOrFail();
        $this->assertSame('EXISTING', $entry->collision_status);
    }

    public function test_collision_status_is_potential_update_when_matching_code_has_no_content_yet_and_evidence_is_high(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        MachineErrorCode::create(['account_id' => $f['home']->id, 'code' => 'C-1001', 'title' => 'Stub only, never filled in']);
        $this->completedExtraction($f['document'], [1 => "C-1001\nCause:\nFixture cause.\nAction:\nFixture action."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->firstOrFail();
        $this->assertSame('HIGH', $entry->evidence);
        $this->assertSame('POTENTIAL_UPDATE', $entry->collision_status);
    }

    // --- 15/16. candidates remain draft/review before publication, never auto-published ---

    public function test_candidates_start_as_draft_and_are_never_auto_published(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => "C-1001\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->firstOrFail();
        $this->assertSame('DRAFT', $entry->status);
        $this->assertNull($entry->published_at);
    }

    // --- 17/18/19. publishing reuses the existing publish service, creates a document reference, retains page provenance ---

    public function test_publishing_an_approved_candidate_reuses_the_existing_publish_service_and_retains_provenance(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [7 => "C-1001\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);
        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->firstOrFail();
        $this->assertSame(7, $entry->source_page_start);

        $entry->update(['status' => 'APPROVED', 'approved_by' => $user->id]);
        $result = app(MaintenanceKnowledgePublishService::class)->publish($entry, $user);

        $this->assertNotNull($result['errorCode']);
        $this->assertSame('C-1001', $result['errorCode']->code);
        $this->assertNotNull($result['reference']);
        $this->assertSame($f['document']->id, $result['reference']->document_id);
        $this->assertSame(7, $result['reference']->page_number, 'published reference must retain the candidate\'s real source page, not a default');
    }

    // --- 20. tenant isolation ---

    public function test_a_foreign_account_member_cannot_start_processing_for_another_accounts_document(): void
    {
        $f = $this->fixture();
        $foreignUser = $this->member($f['foreign']);
        $this->completedExtraction($f['document'], [1 => 'no code']);

        $this->actingAs($foreignUser)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/process-knowledge")
            ->assertStatus(403);
    }

    // --- 21. unauthorized mutation rejected (operator without management capability) ---

    public function test_a_member_without_management_capability_cannot_start_processing(): void
    {
        $f = $this->fixture();
        $viewer = $this->member($f['home'], 'viewer');
        $this->completedExtraction($f['document'], [1 => 'no code']);

        $this->actingAs($viewer)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/process-knowledge")
            ->assertStatus(403);
    }

    // --- 22/23. audit lifecycle, and no raw page text ever reaches the audit log ---

    public function test_audit_records_processing_lifecycle_without_any_raw_page_text(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $secretFixtureProse = 'This exact sentence must never appear in governance_audit_logs.';
        $this->completedExtraction($f['document'], [1 => "C-1001\n$secretFixtureProse\nCause:\nFixture.\nAction:\nFixture."]);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        (new ProcessMaintenanceKnowledgeJob($import->id, 1, 100))->handle(app(MaintenanceKnowledgeProcessingService::class));

        $actions = DB::table('governance_audit_logs')->where('target_id', $import->id)->pluck('action');
        $this->assertContains('knowledge_processing_started', $actions->all());
        $this->assertContains('knowledge_processing_completed', $actions->all());

        $allMetadata = DB::table('governance_audit_logs')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString($secretFixtureProse, $allMetadata);
    }

    // --- 24. queue dispatch targets the real database/default queue ---

    public function test_start_processing_dispatches_onto_the_configured_queue(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => 'no code']);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);

        Queue::assertPushed(ProcessMaintenanceKnowledgeJob::class, fn ($job) => $job->importId === $import->id);
    }

    // --- 25. queue failure behavior: a permanently-failed job marks the import FAILED, never stranded PROCESSING ---

    public function test_a_permanently_failed_chunk_job_marks_the_import_failed_not_stuck_processing(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => 'no code']);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);

        (new ProcessMaintenanceKnowledgeJob($import->id, 1, 100))->failed(new \RuntimeException('simulated permanent failure'));

        $this->assertSame('FAILED', $import->fresh()->status);
        $this->assertNotNull($import->fresh()->processing_completed_at);
    }

    // --- 26/27. processing completion transitions to REVIEW, and truthfully completes even with zero candidates ---

    public function test_processing_completes_to_review_even_with_zero_candidates(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $this->completedExtraction($f['document'], [1 => 'nothing to detect here', 2 => 'still nothing']);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        (new ProcessMaintenanceKnowledgeJob($import->id, 1, 100))->handle(app(MaintenanceKnowledgeProcessingService::class));

        $import->refresh();
        $this->assertSame('REVIEW', $import->status);
        $this->assertSame(0, $import->candidate_count);
        $this->assertNotNull($import->processing_completed_at);
    }

    // --- V1.6.1: real Production run failed with a MySQL "Incorrect string value:
    // '\xE2'" on description - a byte-boundary UTF-8 truncation bug in
    // PdfKnowledgeCandidateDetector, not a charset/schema problem. This proves the
    // fix end to end through the real chunk-processing/persistence path (not just
    // the detector in isolation - see PdfKnowledgeCandidateDetectorUtf8Test for
    // that), so CI's MySQL job (not just local/CI sqlite) validates the actual
    // INSERT succeeds against a real utf8mb4 column.

    public function test_a_candidate_with_multibyte_unicode_near_the_context_boundary_persists_successfully(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $emDash = "\u{2014}";
        $prefix = str_repeat($emDash, 150); // matches the real failure's shape: a multi-byte run straddling the +/-220-char radius
        $this->completedExtraction($f['document'], [1 => $prefix.'C-9001 trailing filler text after the match continues here.']);

        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        $entry = MaintenanceKnowledgeEntry::where('import_id', $import->id)->firstOrFail();
        $this->assertSame('C-9001', $entry->normalized_code);
        $this->assertTrue(mb_check_encoding($entry->description, 'UTF-8'));
    }

    // ==================================================
    // V1.6.1 - evidence filter / server-side pagination (Section E/F)
    // ==================================================

    /** Builds a real import with one entry of each evidence level via the real detector, not hand-crafted rows. */
    private function importWithMixedEvidence(array $f, User $user): MaintenanceDocumentImport
    {
        $this->completedExtraction($f['document'], [
            1 => "C-1001\nCause:\nFixture.\nAction:\nFixture.", // HIGH
            2 => 'Troubleshooting'."\n".'C-1002 nearby but no further explanation appears anywhere on this page.', // MEDIUM (weak marker only, no strong marker substring anywhere)
            3 => '2.1.1 C-1003.......................................... 42', // LOW (TOC-shaped)
        ]);
        Queue::fake();
        $import = app(MaintenanceKnowledgeProcessingService::class)->startProcessing($f['document'], $user);
        app(MaintenanceKnowledgeProcessingService::class)->processChunk($import->fresh(), 1, 100);

        return $import->fresh();
    }

    public function test_evidence_high_filter_returns_only_high_rows(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);

        $response = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=HIGH")->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('HIGH', $rows[0]['evidence']);
        $this->assertSame(1, $response->json('data.total'));
    }

    public function test_evidence_medium_filter_returns_only_medium_rows(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);

        $response = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=MEDIUM")->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('MEDIUM', $rows[0]['evidence']);
    }

    public function test_evidence_low_filter_returns_only_low_rows(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);

        $response = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=LOW")->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame('LOW', $rows[0]['evidence']);
    }

    public function test_invalid_evidence_filter_value_is_rejected(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);

        $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=NOT_A_REAL_LEVEL")
            ->assertStatus(422);
    }

    public function test_evidence_filter_composes_with_status_filter(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);
        MaintenanceKnowledgeEntry::where('import_id', $import->id)->where('evidence', 'HIGH')->update(['status' => 'APPROVED', 'approved_by' => $user->id]);

        $onlyHigh = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=HIGH&status=APPROVED")->assertOk();
        $this->assertSame(1, $onlyHigh->json('data.total'));

        $mismatched = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=LOW&status=APPROVED")->assertOk();
        $this->assertSame(0, $mismatched->json('data.total'));
    }

    public function test_evidence_filter_tenant_isolation(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home']);
        $foreignUser = $this->member($f['foreign']);
        $import = $this->importWithMixedEvidence($f, $owner);

        $this->actingAs($foreignUser)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=HIGH")
            ->assertStatus(404);
    }

    public function test_entries_pagination_metadata_reflects_the_filtered_set_not_the_whole_import(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);

        $response = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?evidence=LOW&per_page=1")->assertOk();
        $this->assertSame(1, $response->json('data.total'), 'total must reflect the LOW-only filtered count (1), not all 3 entries');
        $this->assertSame(1, $response->json('data.last_page'));
    }

    public function test_existing_collision_and_status_filters_still_work_through_the_new_paginated_endpoint(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);

        $newOnly = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?collision_status=NEW")->assertOk();
        $this->assertSame(3, $newOnly->json('data.total'), 'all 3 fixture entries have no matching machine_error_codes row, so all are NEW');

        $draftOnly = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}/entries?status=DRAFT")->assertOk();
        $this->assertSame(3, $draftOnly->json('data.total'));
    }

    public function test_show_exposes_a_real_evidence_summary_aggregate_not_an_embedded_entries_array(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $import = $this->importWithMixedEvidence($f, $user);

        $response = $this->actingAs($user)->getJson("/api/v1/maintenance/document-imports/{$import->id}")->assertOk();
        $this->assertSame(['HIGH' => 1, 'MEDIUM' => 1, 'LOW' => 1], $response->json('data.evidence_summary'));
        $this->assertArrayNotHasKey('entries', $response->json('data'));
    }
}
