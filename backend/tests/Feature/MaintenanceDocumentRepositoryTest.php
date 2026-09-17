<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\Manufacturer;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maintenance V1.2 - Document Repository. Reuses the same global-or-owned catalog
 * authorization (AccountAccessResolver::canManageCatalogScope) machine_error_codes/
 * maintenance_documents already used in V1 - no new capability, no new RBAC.
 */
class MaintenanceDocumentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $home->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'bizhub PRESS C1070', 'machine_category' => 'digital_a3']);
        $errorCode = MachineErrorCode::create(['account_id' => $home->id, 'machine_model_id' => $model->id, 'code' => 'C-3101', 'title' => 'Image adjustment error', 'severity' => 'critical']);
        $foreignErrorCode = MachineErrorCode::create(['account_id' => $foreign->id, 'machine_model_id' => $model->id, 'code' => 'C-9999', 'title' => 'Foreign-only code', 'severity' => 'warning']);

        return compact('home', 'foreign', 'branch', 'manufacturer', 'model', 'errorCode', 'foreignErrorCode');
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

    private function superuser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $user->id, 'role' => 'superuser', 'is_active' => true]);

        return $user;
    }

    // --- CRUD ---

    public function test_home_owner_can_create_read_update_and_delete_a_tenant_document(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $created = $this->actingAs($owner)->postJson('/api/v1/maintenance/documents', [
            'account_id' => $f['home']->id,
            'manufacturer_id' => $f['manufacturer']->id,
            'machine_model_id' => $f['model']->id,
            'title' => 'Konica Minolta C1070 Service Manual',
            'document_type' => 'SERVICE_MANUAL',
            'file_path' => '/private/manuals/km-c1070.pdf',
            'file_name' => 'km-c1070.pdf',
            'file_size' => 1048576,
            'mime_type' => 'application/pdf',
            'status' => 'DRAFT',
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $created['status']);

        $this->actingAs($owner)->getJson("/api/v1/maintenance/documents/{$created['id']}")->assertOk()->assertJsonPath('data.title', 'Konica Minolta C1070 Service Manual');

        $updated = $this->actingAs($owner)->patchJson("/api/v1/maintenance/documents/{$created['id']}", [
            'title' => 'Konica Minolta C1070 Service Manual (rev 2)',
            'status' => 'PUBLISHED',
        ])->assertOk()->json('data');
        $this->assertSame('PUBLISHED', $updated['status']);
        $this->assertTrue((bool) MaintenanceDocument::find($created['id'])->is_active, 'status=PUBLISHED must keep the legacy is_active flag in sync');

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$created['id']}")->assertNoContent();
        $this->assertDatabaseMissing('maintenance_documents', ['id' => $created['id']]);
    }

    public function test_only_pdf_mime_type_is_accepted(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->postJson('/api/v1/maintenance/documents', [
            'account_id' => $f['home']->id,
            'title' => 'Bad file',
            'mime_type' => 'image/png',
        ])->assertStatus(422)->assertJsonValidationErrors(['mime_type']);
    }

    public function test_default_status_for_a_new_document_is_draft(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $created = $this->actingAs($owner)->postJson('/api/v1/maintenance/documents', [
            'account_id' => $f['home']->id,
            'title' => 'Untitled draft manual',
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $created['status']);
    }

    // --- Tenant isolation ---

    public function test_foreign_account_member_cannot_see_home_owned_document(): void
    {
        $f = $this->fixture();
        $doc = MaintenanceDocument::create(['account_id' => $f['home']->id, 'title' => 'Home-only manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $attacker = $this->member($f['foreign'], 'owner');

        $list = $this->actingAs($attacker)->getJson('/api/v1/maintenance/documents')->assertOk()->json('data');
        $this->assertFalse(collect($list)->contains('id', $doc->id));
        $this->actingAs($attacker)->getJson("/api/v1/maintenance/documents/{$doc->id}")->assertNotFound();
    }

    public function test_foreign_account_owner_cannot_mutate_home_owned_document(): void
    {
        $f = $this->fixture();
        $doc = MaintenanceDocument::create(['account_id' => $f['home']->id, 'title' => 'Home-only manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->patchJson("/api/v1/maintenance/documents/{$doc->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->actingAs($attacker)->deleteJson("/api/v1/maintenance/documents/{$doc->id}")->assertForbidden();
    }

    // --- Global document visibility ---

    public function test_global_document_is_visible_to_every_tenant_but_only_mutable_by_platform(): void
    {
        $f = $this->fixture();
        $superuser = $this->superuser();
        $global = $this->actingAs($superuser)->postJson('/api/v1/maintenance/documents', [
            'title' => 'Konica Minolta Global Service Bulletin',
            'status' => 'PUBLISHED',
        ])->assertCreated()->json('data');

        $homeOwner = $this->member($f['home'], 'owner', $f['branch']);
        $foreignOwner = $this->member($f['foreign'], 'owner');
        $this->assertTrue(collect($this->actingAs($homeOwner)->getJson('/api/v1/maintenance/documents')->json('data'))->contains('id', $global['id']));
        $this->assertTrue(collect($this->actingAs($foreignOwner)->getJson('/api/v1/maintenance/documents')->json('data'))->contains('id', $global['id']));

        $this->actingAs($homeOwner)->patchJson("/api/v1/maintenance/documents/{$global['id']}", ['title' => 'Hijacked'])->assertForbidden();
        $this->actingAs($superuser)->patchJson("/api/v1/maintenance/documents/{$global['id']}", ['title' => 'Updated bulletin'])->assertOk();
    }

    // --- Machine model relation ---

    public function test_document_list_can_be_filtered_by_machine_model(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $otherModel = MachineModel::create(['manufacturer_id' => $f['manufacturer']->id, 'model_code' => 'C4080', 'name' => 'AccurioPress C4080']);
        MaintenanceDocument::create(['account_id' => $f['home']->id, 'machine_model_id' => $f['model']->id, 'title' => 'C1070 manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        MaintenanceDocument::create(['account_id' => $f['home']->id, 'machine_model_id' => $otherModel->id, 'title' => 'C4080 manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        $res = $this->actingAs($owner)->getJson('/api/v1/maintenance/documents?machine_model_id='.$f['model']->id)->assertOk()->json('data');
        $this->assertCount(1, $res);
        $this->assertSame('C1070 manual', $res[0]['title']);
    }

    public function test_document_can_be_searched_by_title_manufacturer_and_document_type(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        MaintenanceDocument::create(['account_id' => $f['home']->id, 'manufacturer_id' => $f['manufacturer']->id, 'document_type' => 'SERVICE_MANUAL', 'title' => 'Konica Minolta C1070 Service Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        MaintenanceDocument::create(['account_id' => $f['home']->id, 'document_type' => 'USER_MANUAL', 'title' => 'Fiber Laser User Guide', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        $this->actingAs($owner)->getJson('/api/v1/maintenance/documents?search=Fiber')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Fiber Laser User Guide');
        $this->actingAs($owner)->getJson('/api/v1/maintenance/documents?manufacturer_id='.$f['manufacturer']->id)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson('/api/v1/maintenance/documents?document_type=USER_MANUAL')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Fiber Laser User Guide');
    }

    // --- Capability restriction ---

    public function test_operator_and_technician_can_read_but_not_mutate_documents(): void
    {
        $f = $this->fixture();
        MaintenanceDocument::create(['account_id' => $f['home']->id, 'title' => 'Readable manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        foreach (['operator', 'technician'] as $role) {
            $user = $this->member($f['home'], $role, $f['branch']);
            $this->actingAs($user)->getJson('/api/v1/maintenance/documents')->assertOk();
            $this->actingAs($user)->postJson('/api/v1/maintenance/documents', ['account_id' => $f['home']->id, 'title' => 'Forged'])->assertForbidden();
        }
    }

    // --- Audit logging ---

    public function test_document_mutations_write_governance_audit_rows(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $doc = $this->actingAs($owner)->postJson('/api/v1/maintenance/documents', ['account_id' => $f['home']->id, 'title' => 'Audited manual'])->json('data');
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.created', 'target_type' => 'maintenance_document', 'target_id' => $doc['id'], 'account_id' => $f['home']->id]);

        $this->actingAs($owner)->patchJson("/api/v1/maintenance/documents/{$doc['id']}", ['title' => 'Renamed manual'])->assertOk();
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.updated', 'target_type' => 'maintenance_document', 'target_id' => $doc['id']]);

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$doc['id']}")->assertNoContent();
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_document.deleted', 'target_type' => 'maintenance_document', 'target_id' => $doc['id']]);
    }

    // --- Error code reference ---

    public function test_owner_can_link_a_document_to_an_error_code_and_it_appears_on_the_error_code_listing(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $doc = MaintenanceDocument::create(['account_id' => $f['home']->id, 'title' => 'Konica C1070 Service Manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        $reference = $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$doc->id}/references", [
            'machine_error_code_id' => $f['errorCode']->id,
            'page_number' => 1234,
            'section_title' => 'Image Adjustment Error',
        ])->assertCreated()->json('data');

        $codes = $this->actingAs($owner)->getJson('/api/v1/maintenance/error-codes')->assertOk()->json('data');
        $row = collect($codes)->firstWhere('id', $f['errorCode']->id);
        $this->assertCount(1, $row['document_references']);
        $this->assertSame(1234, $row['document_references'][0]['page_number']);
        $this->assertSame('Konica C1070 Service Manual', $row['document_references'][0]['document']['title']);

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$doc->id}/references/{$reference['id']}")->assertNoContent();
    }

    public function test_a_global_document_cannot_reference_another_tenants_private_error_code(): void
    {
        $f = $this->fixture();
        $superuser = $this->superuser();
        $global = MaintenanceDocument::create(['title' => 'Global manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);

        $this->actingAs($superuser)->postJson("/api/v1/maintenance/documents/{$global->id}/references", [
            'machine_error_code_id' => $f['errorCode']->id,
        ])->assertStatus(422);
    }

    public function test_document_cannot_be_deleted_while_referenced(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $doc = MaintenanceDocument::create(['account_id' => $f['home']->id, 'title' => 'Referenced manual', 'file_reference' => 'x', 'status' => 'PUBLISHED', 'is_active' => true]);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/documents/{$doc->id}/references", ['machine_error_code_id' => $f['errorCode']->id])->assertCreated();

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$doc->id}")->assertStatus(409);
    }
}
