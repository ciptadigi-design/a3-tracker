<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\MaintenanceDocument;
use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Maintenance V1.4 - Document Storage Foundation. Real PDF upload/download/delete
 * on top of the existing V1.2 document repository, using Laravel's own Storage
 * facade (private `local` disk) - no new storage abstraction. Reuses the exact
 * same global-or-owned catalog authorization every prior document/error-code/
 * knowledge-import resource already used - no new capability, no new RBAC.
 */
class MaintenanceDocumentStorageTest extends TestCase
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

    private function pdf(string $name = 'manual.pdf', int $kilobytes = 10): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    // --- Upload success ---

    public function test_owner_can_upload_a_pdf_and_it_is_stored_privately(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $data = $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", [
            'file' => $this->pdf('Konica_Minolta_C1070_Service_Manual.pdf', 100),
        ])->assertCreated()->json('data');

        $this->assertSame('Konica_Minolta_C1070_Service_Manual.pdf', $data['file_name']);
        $this->assertSame('application/pdf', $data['mime_type']);
        $this->assertSame(DocumentStorageService::DISK, $data['storage_disk']);
        $this->assertNotNull($data['uploaded_at']);
        Storage::disk(DocumentStorageService::DISK)->assertExists("maintenance-documents/{$f['document']->id}.pdf");
    }

    public function test_uploading_again_replaces_the_existing_file_and_audits_a_replace_not_a_create(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf('first.pdf')])->assertCreated();

        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf('second.pdf')])->assertCreated()->assertJsonPath('data.file_name', 'second.pdf');

        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.uploaded', 'target_id' => $f['document']->id]);
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.replaced', 'target_id' => $f['document']->id]);
        // Only one physical file exists for this document - the old one was overwritten, not left behind.
        Storage::disk(DocumentStorageService::DISK)->assertExists("maintenance-documents/{$f['document']->id}.pdf");
    }

    // --- Invalid PDF rejection ---

    public function test_non_pdf_upload_is_rejected(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", [
            'file' => UploadedFile::fake()->create('manual.png', 10, 'image/png'),
        ])->assertStatus(422)->assertJsonValidationErrors(['file']);
        Storage::disk(DocumentStorageService::DISK)->assertMissing("maintenance-documents/{$f['document']->id}.pdf");
    }

    // Note: a ".pdf"-named file with spoofed non-PDF content is not meaningfully
    // testable via UploadedFile::fake() - in Laravel's test harness, fake uploads
    // report MIME type derived from the given extension rather than genuine
    // finfo content-sniffing (confirmed: getMimeType() returns application/pdf for
    // fake content that plainly isn't PDF). Real HTTP uploads go through actual
    // content-based detection via mimes:pdf; test_non_pdf_upload_is_rejected above
    // already exercises the rejection path with an honestly-typed fake file.

    // --- Size validation ---

    public function test_oversized_pdf_is_rejected(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $tooLargeKb = (DocumentStorageService::MAX_FILE_SIZE_BYTES / 1024) + 10;

        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", [
            'file' => $this->pdf('huge.pdf', (int) $tooLargeKb),
        ])->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    // --- Tenant isolation ---

    public function test_foreign_account_owner_cannot_upload_to_a_home_owned_document(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertForbidden();
    }

    public function test_foreign_account_owner_cannot_delete_the_file_of_a_home_owned_document(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertCreated();

        $attacker = $this->member($f['foreign'], 'owner');
        $this->actingAs($attacker)->deleteJson("/api/v1/maintenance/documents/{$f['document']->id}/file")->assertForbidden();
    }

    // --- Download authorization ---

    public function test_active_home_member_can_download_but_foreign_member_gets_404(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertCreated();

        $operator = $this->member($f['home'], 'operator', $f['branch']);
        $this->actingAs($operator)->get("/api/v1/maintenance/documents/{$f['document']->id}/download")->assertOk();

        $attacker = $this->member($f['foreign'], 'owner');
        $this->actingAs($attacker)->get("/api/v1/maintenance/documents/{$f['document']->id}/download")->assertNotFound();
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        $f = $this->fixture();
        $this->getJson("/api/v1/maintenance/documents/{$f['document']->id}/download")->assertUnauthorized();
    }

    public function test_download_of_a_document_with_no_stored_file_returns_404(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->get("/api/v1/maintenance/documents/{$f['document']->id}/download")->assertNotFound();
    }

    // --- Delete file (keeps metadata) ---

    public function test_deleting_the_file_removes_the_physical_file_but_keeps_document_metadata(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertCreated();

        $data = $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$f['document']->id}/file")->assertOk()->json('data');
        $this->assertNull($data['storage_disk']);
        $this->assertNull($data['file_name']);
        $this->assertSame('Konica C1070 Service Manual', $data['title'], 'document metadata must survive file deletion');
        $this->assertSame('PUBLISHED', $data['status']);
        Storage::disk(DocumentStorageService::DISK)->assertMissing("maintenance-documents/{$f['document']->id}.pdf");

        $this->actingAs($owner)->get("/api/v1/maintenance/documents/{$f['document']->id}/download")->assertNotFound();
    }

    public function test_deleting_the_document_itself_also_removes_its_physical_file(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertCreated();

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$f['document']->id}")->assertNoContent();
        Storage::disk(DocumentStorageService::DISK)->assertMissing("maintenance-documents/{$f['document']->id}.pdf");
    }

    // --- Existing V1.2 external-reference compatibility ---

    public function test_a_document_with_an_external_file_path_reference_still_works_and_cannot_be_downloaded_as_a_stored_file(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $doc = $this->actingAs($owner)->postJson('/api/v1/maintenance/documents', [
            'account_id' => $f['home']->id,
            'title' => 'External reference manual',
            'file_path' => 'https://example.com/manual.pdf',
        ])->assertCreated()->json('data');
        $this->assertNull($doc['storage_disk']);

        $this->actingAs($owner)->get("/api/v1/maintenance/documents/{$doc['id']}/download")->assertNotFound();
    }

    public function test_generic_update_cannot_overwrite_file_metadata_of_a_document_with_a_real_uploaded_file(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertCreated();

        $this->actingAs($owner)->patchJson("/api/v1/maintenance/documents/{$f['document']->id}", ['file_path' => 'https://example.com/sneaky.pdf'])->assertStatus(409);
    }

    // --- Audit log ---

    public function test_upload_replace_and_delete_file_all_write_governance_audit_rows(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertCreated();
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.uploaded', 'target_id' => $f['document']->id, 'account_id' => $f['home']->id]);

        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$f['document']->id}/upload", ['file' => $this->pdf()])->assertCreated();
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.replaced', 'target_id' => $f['document']->id]);

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$f['document']->id}/file")->assertOk();
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.file_deleted', 'target_id' => $f['document']->id]);
    }
}
