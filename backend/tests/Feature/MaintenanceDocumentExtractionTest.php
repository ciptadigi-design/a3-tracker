<?php

namespace Tests\Feature;

use App\Jobs\ExtractMaintenanceDocumentJob;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentExtraction;
use App\Models\MaintenanceDocumentPage;
use App\Models\User;
use App\Services\DocumentExtractionService;
use App\Services\DocumentStorageService;
use App\Services\PdfExtraction\ExtractedPdfDocument;
use App\Services\PdfExtraction\PdfExtractionErrorCode;
use App\Services\PdfExtraction\PdfExtractionException;
use App\Services\PdfExtraction\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Maintenance V1.5 - PDF Knowledge Extraction Foundation. Turns an uploaded
 * document's stored PDF (V1.4's DocumentStorageService) into structured per-page
 * text (maintenance_document_pages), tracked through a lifecycle record
 * (maintenance_document_extractions), via a queue job (ExtractMaintenanceDocumentJob).
 * No AI/knowledge processing here - extraction only.
 *
 * Two test styles are used deliberately:
 *  - HTTP-level tests for authorization/validation/conflict behavior use
 *    Queue::fake() so the job never actually runs (these don't need a real PDF).
 *  - Job-processing tests (lifecycle, page storage, failure, large-PDF) call
 *    ExtractMaintenanceDocumentJob::handle() directly against a real, minimal,
 *    hand-built multi-page PDF written straight to the faked storage disk -
 *    this exercises the real Smalot\PdfParser extraction path end to end, which
 *    Queue::fake() would otherwise skip entirely. Testing via the real HTTP
 *    endpoint under phpunit.xml's QUEUE_CONNECTION=sync was deliberately avoided
 *    for the failure case: Laravel's SyncQueue re-throws a job's exception into
 *    the dispatching call after invoking failed(), which would turn the intended
 *    "extraction marked FAILED" business outcome into a 500 response instead of
 *    a clean assertion - calling handle() directly keeps that behavior isolated
 *    and precisely testable.
 */
class MaintenanceDocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentStorageService::DISK);
    }

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $home->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $document = MaintenanceDocument::create(['account_id' => $home->id, 'title' => 'Konica C1070 Service Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        return compact('home', 'foreign', 'branch', 'document');
    }

    private function member(Account $account, string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        if ($branch) {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    /** Attaches a real stored PDF to a document, exactly matching what a successful V1.4 upload leaves behind - without re-testing upload() itself (already covered by MaintenanceDocumentStorageTest). */
    private function attachStoredPdf(MaintenanceDocument $doc, string $bytes): void
    {
        $path = DocumentStorageService::DIRECTORY.'/'.$doc->id.'.pdf';
        Storage::disk(DocumentStorageService::DISK)->put($path, $bytes);
        $doc->update(['storage_disk' => DocumentStorageService::DISK, 'file_path' => $path, 'file_name' => 'manual.pdf', 'file_size' => strlen($bytes), 'mime_type' => 'application/pdf', 'uploaded_at' => now()]);
    }

    /** Hand-builds a minimal but genuinely valid multi-page PDF (real xref/trailer, real per-page content streams) so extraction tests exercise the real Smalot\PdfParser parsing path rather than a mocked one. */
    private function buildFixturePdf(array $pageTexts, bool $secured = false): string
    {
        $objects = [];
        $n = count($pageTexts);
        $fontObjNum = 3 + $n * 2;
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (3 + $i * 2).' 0 R';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count $n >>";
        for ($i = 0; $i < $n; $i++) {
            $pageObjNum = 3 + $i * 2;
            $contentObjNum = $pageObjNum + 1;
            $objects[$pageObjNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] /Resources << /Font << /F1 $fontObjNum 0 R >> >> /Contents $contentObjNum 0 R >>";
            $stream = 'BT /F1 12 Tf 10 250 Td ('.$pageTexts[$i].') Tj ET';
            $objects[$contentObjNum] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
        }
        $objects[$fontObjNum] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }
        $xrefStart = strlen($out);
        $totalObjs = $fontObjNum + 1;
        $out .= "xref\n0 $totalObjs\n0000000000 65535 f \n";
        for ($i = 1; $i < $totalObjs; $i++) {
            $out .= str_pad((string) ($offsets[$i] ?? 0), 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }
        // Smalot\PdfParser\Parser::parseContent() throws its "Secured pdf file are
        // currently not supported." exception purely from finding a `/Encrypt N G R`
        // entry in the trailer dictionary (RawDataParser::getXrefData) - it never
        // even needs to dereference object N, so a reference to a made-up object
        // number is enough to exercise the real secured-PDF detection path.
        $encryptClause = $secured ? ' /Encrypt 999 0 R' : '';
        $out .= "trailer\n<< /Size $totalObjs /Root 1 0 R$encryptClause >>\nstartxref\n$xrefStart\n%%EOF";

        return $out;
    }

    // --- Create extraction (start) ---

    public function test_owner_can_start_an_extraction_for_a_document_with_a_stored_pdf(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello']));

        $data = $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/extract")
            ->assertCreated()->json('data');

        $this->assertSame('PENDING', $data['status']);
        $this->assertSame(0, $data['processed_pages']);
        Queue::assertPushed(ExtractMaintenanceDocumentJob::class, fn ($job) => $job->extractionId === $data['id']);
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document_extraction.requested', 'target_id' => $data['id'], 'account_id' => $f['home']->id]);
    }

    public function test_starting_extraction_without_a_stored_pdf_is_rejected(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/extract")->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_starting_extraction_while_one_is_already_running_is_rejected(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello']));
        MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PROCESSING']);

        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/extract")->assertStatus(409);
        Queue::assertNothingPushed();
    }

    // --- Tenant isolation / authorization ---

    public function test_foreign_account_member_cannot_start_extraction_on_a_home_owned_document(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello']));
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/extract")->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_operator_without_management_capability_cannot_start_extraction(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello']));
        $operator = $this->member($f['home'], 'operator', $f['branch']);

        $this->actingAs($operator)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/extract")->assertForbidden();
    }

    public function test_foreign_account_member_gets_404_reading_extraction_status_or_pages(): void
    {
        $f = $this->fixture();
        MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'COMPLETED', 'total_pages' => 1, 'processed_pages' => 1]);
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/extraction")->assertNotFound();
        $this->actingAs($attacker)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/pages")->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $f = $this->fixture();

        $this->postJson("/api/v1/maintenance/documents/{$f['document']->id}/extract")->assertUnauthorized();
        $this->getJson("/api/v1/maintenance/documents/{$f['document']->id}/extraction")->assertUnauthorized();
        $this->getJson("/api/v1/maintenance/documents/{$f['document']->id}/pages")->assertUnauthorized();
    }

    // --- Extraction status endpoint ---

    public function test_status_endpoint_returns_the_most_recent_extraction_attempt(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        // created_at isn't mass-assignable (framework-managed), so it's forced after
        // creation - otherwise both rows would land at the same auto-set "now" and
        // ordering between them would be undefined.
        $older = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'FAILED', 'error_message' => 'first attempt failed']);
        $older->forceFill(['created_at' => now()->subHour()])->save();
        $newer = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PROCESSING']);

        $data = $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/extraction")->assertOk()->json('data');

        $this->assertSame($newer->id, $data['id']);
        $this->assertNotEquals($older->id, $data['id']);
    }

    /**
     * V1.5.2 hotfix: this used to assert `data: null`, which crashed DocumentDetail
     * for pre-V1.5 (V1.4) documents that were uploaded before extraction existed and
     * never got a row - src/lib/api/apiClient.js's unwrapData() uses `payload?.data
     * ?? payload`, and `??` can't distinguish "legitimately null" from "missing",
     * so it returned the whole `{data: null}` envelope instead of `null`, and the
     * frontend then destructured a `status` that was never there. An explicit NONE
     * state (not a persisted row - extraction stays optional, no fake row is ever
     * created) removes that ambiguity at the contract level instead.
     */
    public function test_status_endpoint_returns_explicit_none_state_when_no_extraction_has_ever_been_started(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/extraction")
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'NONE']]);

        $this->assertSame(0, MaintenanceDocumentExtraction::count(), 'NONE is a response-only state - no extraction row is ever created for it.');
    }

    /** Regression: a V1.4 document with a stored PDF but no extraction row (uploaded before V1.5 existed) must not crash the status endpoint or fabricate a row. */
    public function test_status_endpoint_returns_none_for_a_pre_v1_5_document_with_a_stored_pdf_and_no_extraction(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->attachStoredPdf($f['document'], '%PDF-1.4 pre-v1.5 upload, never extracted');

        $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/extraction")
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'NONE']]);

        $this->assertSame(0, MaintenanceDocumentExtraction::count());
    }

    // --- Job processing: lifecycle, page storage, audit ---

    public function test_job_processes_a_pdf_page_by_page_and_completes(): void
    {
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello Page One', 'Hello Page Two', 'Hello Page Three']));
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame('COMPLETED', $extraction->status);
        $this->assertSame(3, $extraction->total_pages);
        $this->assertSame(3, $extraction->processed_pages);
        $this->assertNotNull($extraction->started_at);
        $this->assertNotNull($extraction->completed_at);

        $pages = MaintenanceDocumentPage::where('document_id', $f['document']->id)->orderBy('page_number')->get();
        $this->assertCount(3, $pages);
        $this->assertSame(1, $pages[0]->page_number);
        $this->assertStringContainsString('Hello Page One', $pages[0]->raw_text);
        $this->assertStringContainsString('Hello Page Two', $pages[1]->raw_text);
        $this->assertStringContainsString('Hello Page Three', $pages[2]->raw_text);

        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document_extraction.started', 'target_id' => $extraction->id, 'account_id' => $f['home']->id]);
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document_extraction.completed', 'target_id' => $extraction->id, 'account_id' => $f['home']->id]);
    }

    /**
     * V1.5.5 - the one real behavior change this milestone makes: a
     * Standard-Security-Handler /V4/R4/AESV2, empty-user-password encrypted
     * PDF (previously always FAILED/UNSUPPORTED_PDF_SECURITY, see V1.5.3/
     * V1.5.4) now completes successfully end to end through the exact same
     * lifecycle/persistence/audit code every other document already uses -
     * DocumentExtractionService and ExtractMaintenanceDocumentJob are
     * completely unmodified for this to work; only the bound PdfTextExtractor
     * (AppServiceProvider) changed. Uses the committed sanitized fixture
     * (tests/Fixtures/pdf-security/, see its README) - no Konica content.
     */
    public function test_supported_aesv2_encrypted_pdf_completes_successfully_through_the_real_pipeline(): void
    {
        $f = $this->fixture();
        $fixtureBytes = file_get_contents(__DIR__.'/../Fixtures/pdf-security/encrypted-r4-aes128-empty-user-password.pdf');
        $this->attachStoredPdf($f['document'], $fixtureBytes);
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        // SecuredExtractionMemoryGuard's fixed reserve is calibrated against
        // memory_limit + current PHP process usage together, not file size
        // alone - deep in the full test suite this same PHP process has
        // already accumulated far more than the ~20MB fresh-bootstrap
        // baseline it was calibrated for, from hundreds of prior tests'
        // autoloading and fixtures. That accumulated usage is real for THIS
        // process but has nothing to do with what a real queue worker (which
        // only ever processes this document) would see, so - matching the
        // existing ini_set pattern immediately below in this same file -
        // pin memory_limit to a known, realistic worker value (Production's
        // actual 2048M) so this test's outcome is deterministic regardless of
        // suite run order or accumulated PHPUnit process memory.
        $previousLimit = ini_set('memory_limit', '2048M');
        $this->assertNotFalse($previousLimit, 'ini_set(memory_limit, 2048M) must succeed on any CI/local environment for this test to be meaningful.');

        try {
            (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));
        } finally {
            ini_set('memory_limit', $previousLimit);
        }

        $extraction->refresh();
        $this->assertSame('COMPLETED', $extraction->status);
        $this->assertNull($extraction->error_code);
        $this->assertSame(1, $extraction->total_pages);
        $this->assertSame(1, $extraction->processed_pages);

        $page = MaintenanceDocumentPage::where('document_id', $f['document']->id)->where('page_number', 1)->first();
        $this->assertNotNull($page);
        $this->assertStringContainsString('A3 Tracker PDF Security Fixture', $page->raw_text);
        $this->assertStringContainsString('Error Code Example: C-0000', $page->raw_text);

        // The stored PDF DocumentStorageService manages is never touched by
        // decryption - confirms V1.5.5's "never modify the original uploaded
        // PDF" requirement holds through the full real pipeline, not just the
        // extractor in isolation.
        $this->assertSame($fixtureBytes, Storage::disk($f['document']->storage_disk)->get($f['document']->file_path));
    }

    public function test_re_running_extraction_upserts_the_same_page_rows_instead_of_duplicating_them(): void
    {
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Version One']));
        $first = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);
        (new ExtractMaintenanceDocumentJob($first->id))->handle(app(DocumentExtractionService::class));

        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Version Two, replaced']));
        $second = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);
        (new ExtractMaintenanceDocumentJob($second->id))->handle(app(DocumentExtractionService::class));

        $pages = MaintenanceDocumentPage::where('document_id', $f['document']->id)->get();
        $this->assertCount(1, $pages);
        $this->assertStringContainsString('Version Two, replaced', $pages[0]->raw_text);
    }

    // --- Failed extraction handling ---
    //
    // V1.5.3: every classified failure (PdfExtractionErrorCode) is PERMANENT except
    // EXTRACTION_RUNTIME_FAILURE - see PdfExtractionErrorCode::isPermanent(). A
    // permanent failure's handle() call does NOT re-throw (it calls $this->fail()
    // instead, a no-op here since these tests call handle() directly rather than
    // through a real queue worker - see this class's docblock), so these tests
    // assert the terminal row state directly instead of expecting a throw.

    public function test_secured_pdf_maps_to_unsupported_pdf_security_and_does_not_retry(): void
    {
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello'], secured: true));
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        // No throw: UNSUPPORTED_PDF_SECURITY is permanent, so handle() does not
        // re-throw for queue retry/backoff to act on.
        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertSame('UNSUPPORTED_PDF_SECURITY', $extraction->error_code);
        $this->assertNotNull($extraction->completed_at);
        $this->assertStringContainsString('security/encryption format', $extraction->error_message);
        $this->assertStringNotContainsString(storage_path(), $extraction->error_message, 'error_message must never leak a filesystem path.');
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document_extraction.failed', 'target_id' => $extraction->id]);
    }

    public function test_job_marks_extraction_failed_and_classifies_a_corrupt_pdf(): void
    {
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], "not a real pdf\n%PDF-ish but broken, no valid xref");
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertSame('INVALID_OR_CORRUPT_PDF', $extraction->error_code);
        $this->assertNotNull($extraction->error_message);
        $this->assertNotNull($extraction->completed_at);
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document_extraction.failed', 'target_id' => $extraction->id]);
    }

    public function test_job_marks_extraction_failed_when_the_stored_file_is_missing_from_disk(): void
    {
        $f = $this->fixture();
        // storage_disk/file_path point at a file that was never actually written to
        // the fake disk - simulates the file having disappeared between upload and
        // extraction (e.g. a manual storage-side issue).
        $f['document']->update(['storage_disk' => DocumentStorageService::DISK, 'file_path' => DocumentStorageService::DIRECTORY.'/missing.pdf', 'file_name' => 'missing.pdf']);
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertSame('FILE_MISSING', $extraction->error_code);
        $this->assertNotNull($extraction->completed_at);
    }

    public function test_a_file_too_large_for_the_workers_memory_limit_is_classified_as_resource_limit_and_does_not_retry(): void
    {
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello']));
        // DocumentExtractionService's pre-flight guard is judged against the
        // document's file_size metadata, not the actual bytes on the fake disk - so
        // setting it huge is enough to exceed the threshold without needing an
        // actual huge fixture file. But the threshold itself is relative to
        // ini_get('memory_limit'), which varies by environment (this machine's PHP
        // CLI default is 128M; CI's runner image ships PHP CLI with memory_limit=-1,
        // i.e. unlimited, which made this test pass locally and fail on CI - the
        // guard correctly has nothing to guard against there). ini_set() to a fixed,
        // known value first so the assertion is deterministic everywhere: PHP only
        // refuses ini_set('memory_limit', ...) *below* the process's current usage,
        // and 512M is safely above what a PHPUnit process actually uses, on any
        // environment, unlimited or not.
        $previousLimit = ini_set('memory_limit', '512M');
        $this->assertNotFalse($previousLimit, 'ini_set(memory_limit, 512M) must succeed on any CI/local environment for this test to be meaningful.');
        $f['document']->update(['file_size' => 5 * 1024 * 1024 * 1024]);
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        try {
            (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));
        } finally {
            ini_set('memory_limit', $previousLimit);
        }

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertSame('RESOURCE_LIMIT', $extraction->error_code);
        $this->assertNotNull($extraction->completed_at);
    }

    public function test_an_unclassified_runtime_failure_still_retries_and_keeps_normal_backoff_semantics(): void
    {
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello']));
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        $this->app->bind(PdfTextExtractor::class, function () {
            return new class implements PdfTextExtractor
            {
                public function extract(string $absolutePath): ExtractedPdfDocument
                {
                    // An unclassified failure (not a PdfExtractionException) - e.g. a
                    // transient DB/connection error unrelated to the PDF itself.
                    throw new \RuntimeException('simulated transient failure');
                }
            };
        });

        try {
            (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));
            $this->fail('Expected an unclassified failure to re-throw so queue retry/backoff still applies.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(PdfExtractionException::class, $e);
            $this->assertSame(PdfExtractionErrorCode::EXTRACTION_RUNTIME_FAILURE, $e->errorCode);
        }

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertSame('EXTRACTION_RUNTIME_FAILURE', $extraction->error_code);
    }

    public function test_error_response_never_exposes_a_filesystem_path_or_stack_trace(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello'], secured: true));
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);
        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $data = $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/extraction")->assertOk()->json('data');

        $this->assertSame('FAILED', $data['status']);
        $this->assertSame('UNSUPPORTED_PDF_SECURITY', $data['error_code']);
        $this->assertStringNotContainsString(storage_path(), $data['error_message']);
        $this->assertStringNotContainsString('.php', $data['error_message']);
        $this->assertStringNotContainsString('Stack trace', $data['error_message']);
    }

    public function test_a_successful_retry_after_a_transient_failure_clears_the_stale_error_code(): void
    {
        $f = $this->fixture();
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['Hello']));
        $extraction = MaintenanceDocumentExtraction::create([
            'document_id' => $f['document']->id,
            'status' => 'FAILED',
            'error_code' => 'EXTRACTION_RUNTIME_FAILURE',
            'error_message' => 'Extraction failed due to an unexpected error. It will be retried automatically.',
            'completed_at' => now(),
        ]);

        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame('COMPLETED', $extraction->status);
        $this->assertNull($extraction->error_code);
        $this->assertNull($extraction->error_message);
    }

    public function test_failed_method_is_a_safety_net_when_handle_never_ran(): void
    {
        $f = $this->fixture();
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        (new ExtractMaintenanceDocumentJob($extraction->id))->failed(new \RuntimeException('job could not be deserialized (internal detail, never shown to a user)'));

        $extraction->refresh();
        $this->assertSame('FAILED', $extraction->status);
        $this->assertSame('EXTRACTION_RUNTIME_FAILURE', $extraction->error_code);
        $this->assertNotNull($extraction->completed_at);
        $this->assertStringNotContainsString('internal detail', $extraction->error_message, 'The safety net must classify into a safe message, never store the raw exception text.');
    }

    // --- Large PDF compatibility ---

    // A genuine 100-250MB PDF is impractical inside a fast unit suite. This instead
    // proves the mechanism that matters for large files scales correctly: many pages
    // processed and persisted one at a time (never accumulated into one in-memory
    // string/array), with progress tracked incrementally to completion.
    public function test_a_pdf_with_many_pages_is_processed_completely_one_page_at_a_time(): void
    {
        $f = $this->fixture();
        $texts = [];
        for ($i = 1; $i <= 60; $i++) {
            $texts[] = "Page content number $i";
        }
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf($texts));
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);

        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $extraction->refresh();
        $this->assertSame('COMPLETED', $extraction->status);
        $this->assertSame(60, $extraction->total_pages);
        $this->assertSame(60, $extraction->processed_pages);
        $this->assertSame(60, MaintenanceDocumentPage::where('document_id', $f['document']->id)->count());
        $first = MaintenanceDocumentPage::where('document_id', $f['document']->id)->where('page_number', 1)->first();
        $last = MaintenanceDocumentPage::where('document_id', $f['document']->id)->where('page_number', 60)->first();
        $this->assertStringContainsString('Page content number 1', $first->raw_text);
        $this->assertStringContainsString('Page content number 60', $last->raw_text);
    }

    // --- Pages endpoint: pagination ---

    public function test_pages_endpoint_paginates_extracted_content_instead_of_returning_everything_at_once(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['One', 'Two', 'Three', 'Four', 'Five']));
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);
        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $page1 = $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/pages?per_page=2")->assertOk()->json('data');
        $this->assertCount(2, $page1['data']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(3, $page1['last_page']);
        $this->assertSame(1, $page1['data'][0]['page_number']);

        $page2 = $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/pages?per_page=2&page=2")->assertOk()->json('data');
        $this->assertSame(3, $page2['data'][0]['page_number']);
    }

    public function test_pages_endpoint_caps_per_page_at_fifty(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $data = $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$f['document']->id}/pages?per_page=500")->assertOk()->json('data');
        $this->assertSame(50, $data['per_page']);
    }

    // --- Cascade delete ---

    public function test_deleting_a_document_cascades_its_extraction_and_page_records(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->attachStoredPdf($f['document'], $this->buildFixturePdf(['One']));
        $extraction = MaintenanceDocumentExtraction::create(['document_id' => $f['document']->id, 'status' => 'PENDING']);
        (new ExtractMaintenanceDocumentJob($extraction->id))->handle(app(DocumentExtractionService::class));

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$f['document']->id}")->assertNoContent();

        $this->assertDatabaseMissing('maintenance_document_extractions', ['id' => $extraction->id]);
        $this->assertDatabaseMissing('maintenance_document_pages', ['document_id' => $f['document']->id]);
    }
}
