<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceKnowledge;
use App\Models\MaintenanceTicket;
use App\Models\Manufacturer;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Maintenance Module (asset-oriented: machine error knowledge, maintenance
 * tickets, action history, knowledge review loop). Mirrors the authorization
 * shape M2.18.1 already established for machine-component endpoints:
 * cross-account rejection, same-account wrong-role rejection, operator
 * policy-gated ticket creation, and tenant-scoped audit logging.
 */
class MaintenanceModuleTest extends TestCase
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
        $errorCode = MachineErrorCode::create(['machine_model_id' => $model->id, 'code' => 'C-2801', 'title' => 'Fuser unit error', 'category' => 'fuser', 'severity' => 'critical']);

        return compact('home', 'foreign', 'branch', 'manufacturer', 'model', 'machine', 'errorCode');
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

    // --- Tenant isolation: a foreign-account owner must never reach a home-account ticket ---

    public function test_cross_account_owner_cannot_create_ticket_on_foreign_machine(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'title' => 'Forged ticket',
        ])->assertForbidden();
    }

    public function test_cross_account_owner_cannot_view_ticket(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $ticket = $this->actingAs($owner)->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'title' => 'Paper jam',
            'error_code_id' => $f['errorCode']->id,
        ])->assertCreated()->json('data');

        $attacker = $this->member($f['foreign'], 'owner');
        $this->actingAs($attacker)->getJson("/api/v1/maintenance/tickets/{$ticket['id']}")->assertForbidden();
    }

    // --- Same-account wrong role: operator cannot create a ticket without policy delegation ---

    public function test_operator_cannot_create_ticket_without_policy_delegation(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['home'], 'operator', $f['branch']);

        $this->actingAs($operator)->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'title' => 'Streaks on print',
        ])->assertForbidden();
    }

    public function test_operator_can_create_ticket_when_policy_delegates(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['home'], 'operator', $f['branch']);
        DB::table('account_operational_permissions')->insert(['account_id' => $f['home']->id, 'operator_can_create_maintenance_ticket' => true]);

        $this->actingAs($operator)->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'title' => 'Streaks on print',
        ])->assertCreated();
    }

    public function test_operator_cannot_update_or_transition_ticket(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $ticket = $this->actingAs($owner)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'title' => 'Paper jam'])->json('data');

        $operator = $this->member($f['home'], 'operator', $f['branch']);
        $this->actingAs($operator)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'IN_PROGRESS'])->assertForbidden();
    }

    // --- Technician: can create tickets, update them, and record actions ---

    public function test_technician_can_create_update_and_progress_a_ticket_and_record_an_action(): void
    {
        $f = $this->fixture();
        $technician = $this->member($f['home'], 'technician', $f['branch']);

        $ticket = $this->actingAs($technician)->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'error_code_id' => $f['errorCode']->id,
            'title' => 'Fuser overheating',
            'priority' => 'high',
        ])->assertCreated()->json('data');
        $this->assertSame('OPEN', $ticket['status']);

        $this->actingAs($technician)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'IN_PROGRESS'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        $this->actingAs($technician)->postJson("/api/v1/maintenance/tickets/{$ticket['id']}/actions", [
            'action_description' => 'Replaced fuser unit',
            'result' => 'resolved',
        ])->assertCreated();

        $this->actingAs($technician)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'DONE'])
            ->assertOk()
            ->assertJsonPath('data.status', 'DONE');

        $this->assertNotNull(MaintenanceTicket::find($ticket['id'])->resolved_at);
    }

    public function test_technician_cannot_assign_a_ticket(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        $ticket = $this->actingAs($owner)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'title' => 'Paper jam'])->json('data');

        $technician = $this->member($f['home'], 'technician', $f['branch']);
        $assignee = $this->member($f['home'], 'technician', $f['branch']);
        $this->actingAs($technician)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/assign", ['assigned_to' => $assignee->id])->assertForbidden();
    }

    public function test_admin_can_assign_a_ticket(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);
        $ticket = $this->actingAs($admin)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'title' => 'Paper jam'])->json('data');
        $technician = $this->member($f['home'], 'technician', $f['branch']);

        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/assign", ['assigned_to' => $technician->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', $technician->id);
    }

    // --- Assignment must stay within the ticket's own tenant, not any user in the system ---

    public function test_admin_cannot_assign_a_ticket_to_a_user_outside_the_account(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);
        $ticket = $this->actingAs($admin)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'title' => 'Paper jam'])->json('data');
        $outsider = $this->member($f['foreign'], 'technician');

        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/assign", ['assigned_to' => $outsider->id])->assertStatus(422);
    }

    // --- Illegal ticket status transitions are rejected regardless of role ---

    public function test_ticket_cannot_transition_from_done_back_to_in_progress(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);
        $ticket = $this->actingAs($admin)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'title' => 'Paper jam'])->json('data');
        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'DONE'])->assertOk();

        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'IN_PROGRESS'])->assertStatus(409);
    }

    // --- Downtime is opened_at -> resolved_at only: no separate manually-settable window exists ---

    public function test_ticket_downtime_is_opened_at_to_resolved_at_with_no_manual_field(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);
        $created = $this->actingAs($admin)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'title' => 'Machine down'])
            ->assertCreated()
            ->json('data');
        // The API accepts no downtime/duration field at all - only real ticket create/resolve instants exist.
        $this->assertArrayNotHasKey('machine_down_at', $created);
        $this->assertArrayNotHasKey('machine_restored_at', $created);
        $this->assertNotNull($created['opened_at']);

        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$created['id']}/status", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$created['id']}/status", ['status' => 'DONE'])->assertOk();

        $ticket = MaintenanceTicket::find($created['id']);
        $this->assertNotNull($ticket->opened_at);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertTrue($ticket->resolved_at->greaterThanOrEqualTo($ticket->opened_at));
    }

    // --- Knowledge base catalog (error codes) follows the same global-or-owned catalog scope as ComponentCatalog ---

    public function test_owner_cannot_create_a_platform_global_error_code(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->postJson('/api/v1/maintenance/error-codes', [
            'account_id' => null,
            'machine_model_id' => $f['model']->id,
            'code' => 'C-9999',
            'title' => 'Global code attempt',
        ])->assertForbidden();
    }

    public function test_platform_superuser_can_create_a_global_error_code(): void
    {
        $f = $this->fixture();
        $superuser = $this->superuser();

        $this->actingAs($superuser)->postJson('/api/v1/maintenance/error-codes', [
            'account_id' => null,
            'machine_model_id' => $f['model']->id,
            'code' => 'C-9999',
            'title' => 'Global fuser code',
            'severity' => 'critical',
        ])->assertCreated();
    }

    public function test_owner_can_create_a_tenant_scoped_error_code(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->actingAs($owner)->postJson('/api/v1/maintenance/error-codes', [
            'account_id' => $f['home']->id,
            'machine_model_id' => $f['model']->id,
            'code' => 'INTERNAL-01',
            'title' => 'Internal-only jam code',
        ])->assertCreated();
    }

    // --- Knowledge review workflow: DRAFT -> REVIEW -> PUBLISHED, technician cannot review ---

    public function test_technician_can_submit_knowledge_but_cannot_review_it(): void
    {
        $f = $this->fixture();
        $technician = $this->member($f['home'], 'technician', $f['branch']);

        $entry = $this->actingAs($technician)->postJson('/api/v1/maintenance/knowledge', [
            'account_id' => $f['home']->id,
            'machine_model_id' => $f['model']->id,
            'error_code_id' => $f['errorCode']->id,
            'problem' => 'Fuser overheats after 20k prints',
            'solution' => 'Replace fuser unit and recalibrate',
        ])->assertCreated()->json('data');
        $this->assertSame('DRAFT', $entry['approval_status']);

        $this->actingAs($technician)->patchJson("/api/v1/maintenance/knowledge/{$entry['id']}/review", ['approval_status' => 'REVIEW'])->assertForbidden();
    }

    public function test_admin_can_move_knowledge_through_review_to_published(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);
        $entry = MaintenanceKnowledge::create(['account_id' => $f['home']->id, 'problem' => 'p', 'solution' => 's', 'approval_status' => 'DRAFT']);

        $this->actingAs($admin)->patchJson("/api/v1/maintenance/knowledge/{$entry->id}/review", ['approval_status' => 'REVIEW'])->assertOk()->assertJsonPath('data.approval_status', 'REVIEW');
        $this->actingAs($admin)->patchJson("/api/v1/maintenance/knowledge/{$entry->id}/review", ['approval_status' => 'PUBLISHED'])->assertOk()->assertJsonPath('data.approval_status', 'PUBLISHED');
        $this->assertNotNull(MaintenanceKnowledge::find($entry->id)->published_at);
    }

    public function test_knowledge_cannot_skip_review_and_publish_directly_from_draft(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);
        $entry = MaintenanceKnowledge::create(['account_id' => $f['home']->id, 'problem' => 'p', 'solution' => 's', 'approval_status' => 'DRAFT']);

        $this->actingAs($admin)->patchJson("/api/v1/maintenance/knowledge/{$entry->id}/review", ['approval_status' => 'PUBLISHED'])->assertStatus(409);
    }

    // --- Internal knowledge can never become global: account_id is required at the DB and API level ---

    public function test_knowledge_submission_requires_an_account_and_cannot_be_global(): void
    {
        $f = $this->fixture();
        $technician = $this->member($f['home'], 'technician', $f['branch']);

        $this->actingAs($technician)->postJson('/api/v1/maintenance/knowledge', [
            'problem' => 'Fuser overheats',
            'solution' => 'Replace fuser unit',
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);
    }

    public function test_operator_sees_only_published_knowledge_by_default(): void
    {
        $f = $this->fixture();
        MaintenanceKnowledge::create(['account_id' => $f['home']->id, 'problem' => 'draft problem', 'solution' => 's', 'approval_status' => 'DRAFT']);
        MaintenanceKnowledge::create(['account_id' => $f['home']->id, 'problem' => 'published problem', 'solution' => 's', 'approval_status' => 'PUBLISHED']);
        $operator = $this->member($f['home'], 'operator', $f['branch']);

        $res = $this->actingAs($operator)->getJson('/api/v1/maintenance/knowledge')->assertOk()->json('data');
        $this->assertCount(1, $res);
        $this->assertSame('published problem', $res[0]['problem']);
    }

    // --- Audit: ticket status transitions are recorded in governance_audit_logs ---

    public function test_ticket_status_change_writes_a_governance_audit_row(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);
        $ticket = $this->actingAs($admin)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'title' => 'Paper jam'])->json('data');

        $this->actingAs($admin)->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'IN_PROGRESS'])->assertOk();

        $this->assertDatabaseHas('governance_audit_logs', [
            'action' => 'maintenance_ticket.status_changed',
            'target_type' => 'maintenance_ticket',
            'target_id' => $ticket['id'],
            'account_id' => $f['home']->id,
        ]);
    }
}
