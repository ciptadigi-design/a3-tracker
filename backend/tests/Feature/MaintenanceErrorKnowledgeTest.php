<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceErrorSolution;
use App\Models\Manufacturer;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maintenance V1.1 - Error Knowledge Base: solution_summary on machine_error_codes
 * plus ordered maintenance_error_solutions steps. Reuses the exact same
 * global-or-owned catalog authorization (AccountAccessResolver::canManageCatalogScope)
 * machine_error_codes already used in V1 - no new capability, no new RBAC.
 */
class MaintenanceErrorKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $home->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'A3', 'name' => 'A3', 'machine_category' => 'digital_a3']);
        $machine = Machine::create(['account_id' => $home->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'HOME-A3-01', 'display_name' => 'Home A3', 'status' => 'active']);
        $ownedCode = MachineErrorCode::create(['account_id' => $home->id, 'machine_model_id' => $model->id, 'code' => 'C-2801', 'title' => 'Fuser unit error', 'severity' => 'critical', 'solution_summary' => 'Restart machine and check registration sensor condition.']);
        $ownedCode2 = MachineErrorCode::create(['account_id' => $home->id, 'machine_model_id' => $model->id, 'code' => 'C-2802', 'title' => 'Second home-owned code', 'severity' => 'warning']);
        $globalCode = MachineErrorCode::create(['machine_model_id' => $model->id, 'code' => 'C-9000', 'title' => 'Global paper jam', 'severity' => 'warning']);
        $foreignCode = MachineErrorCode::create(['account_id' => $foreign->id, 'machine_model_id' => $model->id, 'code' => 'C-1111', 'title' => 'Foreign-only code', 'severity' => 'info']);

        return compact('home', 'foreign', 'branch', 'manufacturer', 'model', 'machine', 'ownedCode', 'ownedCode2', 'globalCode', 'foreignCode');
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

    // --- solution_summary is additive and readable ---

    public function test_error_codes_listing_includes_solution_summary_and_ordered_solutions(): void
    {
        $f = $this->fixture();
        MaintenanceErrorSolution::create(['machine_error_code_id' => $f['ownedCode']->id, 'step_number' => 2, 'instruction' => 'Check registration sensor.']);
        MaintenanceErrorSolution::create(['machine_error_code_id' => $f['ownedCode']->id, 'step_number' => 1, 'instruction' => 'Power cycle the machine.']);
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $res = $this->actingAs($owner)->getJson('/api/v1/maintenance/error-codes')->assertOk()->json('data');
        $ownedRow = collect($res)->firstWhere('id', $f['ownedCode']->id);
        $this->assertSame('Restart machine and check registration sensor condition.', $ownedRow['solution_summary']);
        $this->assertSame([1, 2], array_column($ownedRow['solutions'], 'step_number'));
        $this->assertSame('Power cycle the machine.', $ownedRow['solutions'][0]['instruction']);
    }

    // --- Read access: operators and technicians can view/search (no capability gate, matches V1 read behavior) ---

    public function test_operator_and_technician_can_read_and_search_error_codes(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['home'], 'operator', $f['branch']);
        $technician = $this->member($f['home'], 'technician', $f['branch']);

        $this->actingAs($operator)->getJson('/api/v1/maintenance/error-codes?search=fuser')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'C-2801');
        $this->actingAs($technician)->getJson('/api/v1/maintenance/error-codes?search=C-9000')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'C-9000');
    }

    // --- Tenant isolation: cross-account membership never sees another tenant's owned codes ---

    public function test_foreign_account_member_cannot_see_home_owned_error_code(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $codes = $this->actingAs($attacker)->getJson('/api/v1/maintenance/error-codes')->assertOk()->json('data');
        $this->assertFalse(collect($codes)->contains('id', $f['ownedCode']->id));
        $this->assertTrue(collect($codes)->contains('id', $f['globalCode']->id), 'global code must remain visible to every tenant');
        $this->assertTrue(collect($codes)->contains('id', $f['foreignCode']->id));
    }

    // --- Authorization: only catalog-scope-authorized users (owner/admin for owned, Superuser for global) can mutate ---

    public function test_operator_and_technician_cannot_add_a_solution_step(): void
    {
        $f = $this->fixture();
        foreach (['operator', 'technician'] as $role) {
            $user = $this->member($f['home'], $role, $f['branch']);
            $this->actingAs($user)->postJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'Do the thing.'])->assertForbidden();
        }
    }

    public function test_foreign_account_owner_cannot_mutate_home_owned_error_code(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->putJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}", ['title' => 'Hijacked title'])->assertForbidden();
        $this->actingAs($attacker)->postJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'x'])->assertForbidden();
    }

    public function test_home_owner_can_create_update_error_code_and_manage_solution_steps(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $updated = $this->actingAs($owner)->putJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}", [
            'title' => 'Fuser unit error (revised)',
            'severity' => 'critical',
            'solution_summary' => 'Updated summary.',
        ])->assertOk()->json('data');
        $this->assertSame('Updated summary.', $updated['solution_summary']);

        $step = $this->actingAs($owner)->postJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions", [
            'step_number' => 1,
            'instruction' => 'Power cycle the machine.',
            'requires_technician' => false,
        ])->assertCreated()->json('data');

        $this->actingAs($owner)->putJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions/{$step['id']}", [
            'step_number' => 1,
            'instruction' => 'Power cycle the machine, then wait 30 seconds.',
            'requires_technician' => true,
        ])->assertOk()->assertJsonPath('data.requires_technician', true);

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions/{$step['id']}")->assertNoContent();
        $this->assertDatabaseMissing('maintenance_error_solutions', ['id' => $step['id']]);
    }

    public function test_owner_cannot_mutate_a_platform_global_error_code(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->putJson("/api/v1/maintenance/error-codes/{$f['globalCode']->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/maintenance/error-codes/{$f['globalCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'x'])->assertForbidden();
    }

    public function test_platform_superuser_can_manage_a_global_error_code_solution_steps(): void
    {
        $f = $this->fixture();
        $superuser = $this->superuser();

        $this->actingAs($superuser)->postJson("/api/v1/maintenance/error-codes/{$f['globalCode']->id}/solutions", [
            'step_number' => 1,
            'instruction' => 'Open the front cover and clear the jam path.',
        ])->assertCreated();
    }

    // --- Solution step ordering: duplicate step numbers for the same code are rejected ---

    public function test_duplicate_step_number_on_same_error_code_is_rejected(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'First.'])->assertCreated();

        $this->actingAs($owner)->postJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'Also first?'])->assertStatus(409);
    }

    public function test_step_number_can_be_reused_across_different_error_codes(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $this->actingAs($owner)->postJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'Owned code step 1.']);

        $superuser = $this->superuser();
        $this->actingAs($superuser)->postJson("/api/v1/maintenance/error-codes/{$f['globalCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'Global code step 1.'])->assertCreated();
    }

    // --- A solution step id from a different error code cannot be updated/deleted through this one,
    //     even when the actor is authorized to manage both codes ---

    public function test_solution_step_scoped_to_its_own_error_code_only(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $step = MaintenanceErrorSolution::create(['machine_error_code_id' => $f['ownedCode']->id, 'step_number' => 1, 'instruction' => 'Owned step.']);

        $this->actingAs($owner)->putJson("/api/v1/maintenance/error-codes/{$f['ownedCode2']->id}/solutions/{$step->id}", ['step_number' => 1, 'instruction' => 'hijack'])->assertNotFound();
    }

    // --- Audit: mutations use the existing GovernanceAudit pattern ---

    public function test_solution_step_creation_writes_a_governance_audit_row(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $step = $this->actingAs($owner)->postJson("/api/v1/maintenance/error-codes/{$f['ownedCode']->id}/solutions", ['step_number' => 1, 'instruction' => 'Do the thing.'])->json('data');

        $this->assertDatabaseHas('governance_audit_logs', [
            'action' => 'maintenance_error_solution.created',
            'target_type' => 'maintenance_error_solution',
            'target_id' => $step['id'],
            'account_id' => $f['home']->id,
        ]);
    }

    // --- Ticket autofill data contract: the fields CreateTicketDialog needs to prefill are present ---

    public function test_error_code_payload_carries_everything_needed_to_autofill_a_ticket(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $row = collect($this->actingAs($owner)->getJson('/api/v1/maintenance/error-codes')->json('data'))->firstWhere('id', $f['ownedCode']->id);
        $this->assertSame('Fuser unit error', $row['title']);
        $this->assertSame('Restart machine and check registration sensor condition.', $row['solution_summary']);
        $this->assertArrayHasKey('operator_description', $row);
    }
}
