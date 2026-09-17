<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceDocumentImport;
use App\Models\MaintenanceKnowledgeEntry;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maintenance V1.3 - PDF Knowledge Import Foundation. Manual entry only (no OCR/AI).
 * Reuses the same global-or-owned catalog authorization every prior Maintenance
 * catalog resource (documents, error codes, solutions) already used - no new
 * capability, no new RBAC.
 */
class MaintenanceKnowledgeImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $home->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'bizhub PRESS C1070']);
        $otherModel = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C4080', 'name' => 'AccurioPress C4080']);
        $document = MaintenanceDocument::create(['account_id' => $home->id, 'title' => 'Konica C1070 Service Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $foreignDocument = MaintenanceDocument::create(['account_id' => $foreign->id, 'title' => 'Foreign-only manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        return compact('home', 'foreign', 'branch', 'manufacturer', 'model', 'otherModel', 'document', 'foreignDocument');
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

    // --- Create import session ---

    public function test_owner_can_create_an_import_session_for_their_own_document(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $import = $this->actingAs($owner)->postJson('/api/v1/maintenance/document-imports', [
            'document_id' => $f['document']->id,
            'machine_model_id' => $f['model']->id,
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $import['status']);
        $this->assertSame('MANUAL_ENTRY', $import['import_type']);
    }

    // --- Tenant isolation ---

    public function test_foreign_account_owner_cannot_create_an_import_for_a_home_owned_document(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->postJson('/api/v1/maintenance/document-imports', ['document_id' => $f['document']->id])->assertForbidden();
    }

    public function test_foreign_account_member_cannot_list_or_view_a_home_owned_import(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $import = MaintenanceDocumentImport::create(['document_id' => $f['document']->id, 'status' => 'DRAFT', 'import_type' => 'MANUAL_ENTRY', 'created_by' => $owner->id]);

        $attacker = $this->member($f['foreign'], 'owner');
        $list = $this->actingAs($attacker)->getJson('/api/v1/maintenance/document-imports')->assertOk()->json('data');
        $this->assertFalse(collect($list)->contains('id', $import->id));
        $this->actingAs($attacker)->getJson("/api/v1/maintenance/document-imports/{$import->id}")->assertNotFound();
    }

    // --- Machine model restriction ---

    public function test_import_cannot_reference_a_machine_model_outside_the_documents_scope(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $foreignModel = MachineModel::create(['account_id' => $f['foreign']->id, 'manufacturer_id' => $f['manufacturer']->id, 'model_code' => 'PRIVATE', 'name' => 'Foreign private model']);

        $this->actingAs($owner)->postJson('/api/v1/maintenance/document-imports', [
            'document_id' => $f['document']->id,
            'machine_model_id' => $foreignModel->id,
        ])->assertStatus(422);
    }

    // --- Add knowledge entry ---

    public function test_owner_can_add_a_knowledge_entry_and_import_advances_to_processing(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $import = $this->actingAs($owner)->postJson('/api/v1/maintenance/document-imports', ['document_id' => $f['document']->id, 'machine_model_id' => $f['model']->id])->json('data');

        $entry = $this->actingAs($owner)->postJson("/api/v1/maintenance/document-imports/{$import['id']}/entries", [
            'knowledge_type' => 'ERROR_CODE',
            'code' => 'C-3101',
            'title' => 'Image adjustment error',
            'severity' => 'critical',
            'description' => 'Occurs during registration.',
            'operator_solution' => 'Restart the machine.',
            'technician_solution' => 'Recalibrate the registration sensor per section 4.2.',
            'page_reference' => '1234',
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $entry['status']);

        $this->assertSame('PROCESSING', MaintenanceDocumentImport::find($import['id'])->status);
    }

    public function test_operator_and_technician_cannot_create_imports_or_entries(): void
    {
        $f = $this->fixture();
        $import = MaintenanceDocumentImport::create(['document_id' => $f['document']->id, 'status' => 'DRAFT', 'import_type' => 'MANUAL_ENTRY']);
        foreach (['operator', 'technician'] as $role) {
            $user = $this->member($f['home'], $role, $f['branch']);
            $this->actingAs($user)->postJson('/api/v1/maintenance/document-imports', ['document_id' => $f['document']->id])->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/maintenance/document-imports/{$import->id}/entries", ['knowledge_type' => 'WARNING', 'title' => 'x'])->assertForbidden();
        }
        // Read access remains open to every active member.
        $reader = $this->member($f['home'], 'operator', $f['branch']);
        $this->actingAs($reader)->getJson('/api/v1/maintenance/document-imports')->assertOk();
    }

    // --- Edit draft entry ---

    public function test_draft_entry_can_be_edited_but_a_reviewed_entry_cannot(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $import = MaintenanceDocumentImport::create(['document_id' => $f['document']->id, 'status' => 'PROCESSING', 'import_type' => 'MANUAL_ENTRY']);
        $entry = MaintenanceKnowledgeEntry::create(['import_id' => $import->id, 'knowledge_type' => 'WARNING', 'title' => 'Draft warning', 'status' => 'DRAFT']);

        $this->actingAs($owner)->patchJson("/api/v1/maintenance/knowledge-entries/{$entry->id}", ['title' => 'Updated warning'])->assertOk()->assertJsonPath('data.title', 'Updated warning');

        $this->actingAs($owner)->patchJson("/api/v1/maintenance/knowledge-entries/{$entry->id}", ['status' => 'APPROVED'])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->actingAs($owner)->patchJson("/api/v1/maintenance/knowledge-entries/{$entry->id}", ['title' => 'Should not be allowed'])->assertStatus(409);
    }

    public function test_entry_status_transitions_follow_the_defined_workflow(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $import = MaintenanceDocumentImport::create(['document_id' => $f['document']->id, 'status' => 'PROCESSING', 'import_type' => 'MANUAL_ENTRY']);
        $entry = MaintenanceKnowledgeEntry::create(['import_id' => $import->id, 'knowledge_type' => 'WARNING', 'title' => 'x', 'status' => 'REJECTED']);

        $this->actingAs($owner)->patchJson("/api/v1/maintenance/knowledge-entries/{$entry->id}", ['status' => 'APPROVED'])->assertStatus(409);
    }

    // --- Publish flow: error code creation, solution creation, document reference creation ---

    public function test_publishing_an_approved_error_code_entry_creates_error_code_solution_and_reference(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $import = $this->actingAs($owner)->postJson('/api/v1/maintenance/document-imports', ['document_id' => $f['document']->id, 'machine_model_id' => $f['model']->id])->json('data');
        $entry = $this->actingAs($owner)->postJson("/api/v1/maintenance/document-imports/{$import['id']}/entries", [
            'knowledge_type' => 'ERROR_CODE',
            'code' => 'C-3101',
            'title' => 'Image adjustment error',
            'severity' => 'critical',
            'description' => 'Occurs during registration.',
            'operator_solution' => 'Restart the machine.',
            'technician_solution' => 'Recalibrate the registration sensor per section 4.2.',
            'page_reference' => 'p. 1234',
        ])->json('data');

        $this->actingAs($owner)->postJson("/api/v1/maintenance/knowledge-entries/{$entry['id']}/publish")->assertStatus(409);

        $this->actingAs($owner)->patchJson("/api/v1/maintenance/knowledge-entries/{$entry['id']}", ['status' => 'APPROVED'])->assertOk();

        $result = $this->actingAs($owner)->postJson("/api/v1/maintenance/knowledge-entries/{$entry['id']}/publish")->assertOk()->json('data');
        $this->assertNotNull($result['error_code']);
        $this->assertSame('C-3101', $result['error_code']['code']);
        $this->assertSame($f['home']->id, $result['error_code']['account_id']);
        $this->assertNotNull($result['solution']);
        $this->assertTrue($result['solution']['requires_technician']);
        $this->assertNotNull($result['reference']);
        $this->assertSame(1234, $result['reference']['page_number']);

        $errorCode = MachineErrorCode::where('account_id', $f['home']->id)->where('code', 'C-3101')->first();
        $this->assertNotNull($errorCode);
        $this->assertDatabaseHas('maintenance_error_solutions', ['machine_error_code_id' => $errorCode->id, 'requires_technician' => true]);
        $this->assertDatabaseHas('maintenance_document_references', ['document_id' => $f['document']->id, 'machine_error_code_id' => $errorCode->id]);

        // Idempotency: cannot publish the same entry twice.
        $this->actingAs($owner)->postJson("/api/v1/maintenance/knowledge-entries/{$entry['id']}/publish")->assertStatus(409);

        // The import completed (its only entry was published).
        $this->assertSame('PUBLISHED', MaintenanceDocumentImport::find($import['id'])->status);
    }

    public function test_republishing_the_same_code_updates_the_existing_error_code_instead_of_duplicating(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $existing = MachineErrorCode::create(['account_id' => $f['home']->id, 'machine_model_id' => $f['model']->id, 'code' => 'C-3101', 'title' => 'Old title', 'severity' => 'warning']);

        $import = MaintenanceDocumentImport::create(['document_id' => $f['document']->id, 'machine_model_id' => $f['model']->id, 'status' => 'PROCESSING', 'import_type' => 'MANUAL_ENTRY']);
        $entry = MaintenanceKnowledgeEntry::create(['import_id' => $import->id, 'knowledge_type' => 'ERROR_CODE', 'code' => 'C-3101', 'title' => 'Updated title', 'severity' => 'critical', 'status' => 'APPROVED']);

        $this->actingAs($owner)->postJson("/api/v1/maintenance/knowledge-entries/{$entry->id}/publish")->assertOk();

        $this->assertSame(1, MachineErrorCode::where('account_id', $f['home']->id)->where('code', 'C-3101')->count());
        $this->assertSame('Updated title', $existing->fresh()->title);
        $this->assertSame('critical', $existing->fresh()->severity);
    }

    public function test_an_uncoded_entry_publishes_without_creating_an_error_code(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $import = MaintenanceDocumentImport::create(['document_id' => $f['document']->id, 'status' => 'PROCESSING', 'import_type' => 'MANUAL_ENTRY']);
        $entry = MaintenanceKnowledgeEntry::create(['import_id' => $import->id, 'knowledge_type' => 'PM_SCHEDULE', 'title' => 'Replace fuser every 100k', 'status' => 'APPROVED']);

        $result = $this->actingAs($owner)->postJson("/api/v1/maintenance/knowledge-entries/{$entry->id}/publish")->assertOk()->json('data');
        $this->assertNull($result['error_code']);
        $this->assertNotNull(MaintenanceKnowledgeEntry::find($entry->id)->published_at);
    }

    // --- Audit log ---

    public function test_import_creation_entry_creation_and_publish_all_write_governance_audit_rows(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $import = $this->actingAs($owner)->postJson('/api/v1/maintenance/document-imports', ['document_id' => $f['document']->id, 'machine_model_id' => $f['model']->id])->json('data');
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document_import.created', 'target_id' => $import['id']]);

        $entry = $this->actingAs($owner)->postJson("/api/v1/maintenance/document-imports/{$import['id']}/entries", ['knowledge_type' => 'ERROR_CODE', 'code' => 'C-1', 'title' => 'x'])->json('data');
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_knowledge_entry.created', 'target_id' => $entry['id']]);

        $this->actingAs($owner)->patchJson("/api/v1/maintenance/knowledge-entries/{$entry['id']}", ['status' => 'APPROVED'])->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/maintenance/knowledge-entries/{$entry['id']}/publish")->assertOk();

        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'machine_error_code.published_from_import', 'account_id' => $f['home']->id]);
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_knowledge_entry.published', 'target_id' => $entry['id']]);
    }
}
