<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\DowntimeEvent;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\MaintenanceAction;
use App\Models\MaintenanceTicket;
use App\Models\Manufacturer;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\User;
use App\Services\MaintenanceTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaintenanceActivityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'OTHER', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $otherBranch = Branch::create(['account_id' => $account->id, 'code' => 'SECOND', 'name' => 'Second', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $foreignBranch = Branch::create(['account_id' => $foreign->id, 'code' => 'FRGN', 'name' => 'Foreign', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $model = MachineModel::create(['manufacturer_id' => Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta'])->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-01', 'display_name' => 'A3-01', 'status' => 'active']);
        $owner = $this->member($account, 'owner');
        $andi = $this->person($account, 'Andi', $branch);
        $siti = $this->person($account, 'Siti', $branch);
        $otherBranchPerson = $this->person($account, 'Budi Cabang', $otherBranch);
        $foreignPerson = $this->person($foreign, 'Asing', $foreignBranch);
        $inactive = $this->person($account, 'Nonaktif', $branch, false);

        return compact('account', 'foreign', 'branch', 'otherBranch', 'machine', 'owner', 'andi', 'siti', 'otherBranchPerson', 'foreignPerson', 'inactive');
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

    private function person(Account $account, string $name, Branch $branch, bool $active = true): OperationalPerson
    {
        $p = OperationalPerson::create(['account_id' => $account->id, 'name' => $name, 'is_active' => $active]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $p->id, 'branch_id' => $branch->id, 'is_active' => true, 'can_record_counter' => false]);

        return $p;
    }

    private function ticket(array $f, array $o = []): MaintenanceTicket
    {
        return MaintenanceTicket::create($o + [
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id,
            'title' => 'Hasil kotor', 'status' => 'IN_PROGRESS', 'type' => 'breakdown', 'reported_by' => $f['owner']->id,
        ]);
    }

    private function done(array $over = []): array
    {
        return $over + ['status' => 'DONE', 'resolution_cause' => 'Drum kotor', 'resolution_action' => 'Drum dibersihkan', 'resolution_test_result' => 'normal'];
    }

    public function test_activities_record_distinct_performers_in_chronological_append_only_history(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $url = "/api/v1/maintenance/tickets/{$t->id}/actions";

        $this->actingAs($f['owner'])->postJson($url, ['performed_at' => '2026-10-19 14:58:00', 'action_description' => 'Pemeriksaan hasil cetak', 'findings' => 'Ditemukan bercak.', 'operator_person_id' => $f['andi']->id])->assertCreated()
            ->assertJsonPath('data.operator_name', 'Andi')->assertJsonPath('data.performed_by', $f['owner']->id)->assertJsonPath('data.ticket_id', $t->id);
        $this->actingAs($f['owner'])->postJson($url, ['performed_at' => '2026-10-19 15:25:00', 'action_description' => 'Pemeriksaan drum', 'findings' => 'Drum perlu dibersihkan.', 'external_technician_name' => 'Budi', 'vendor_name' => 'Konica Minolta'])->assertCreated()
            ->assertJsonPath('data.operator_person_id', null)->assertJsonPath('data.vendor_name', 'Konica Minolta');
        $this->actingAs($f['owner'])->postJson($url, ['performed_at' => '2026-10-19 15:40:00', 'action_description' => 'Test print ulang', 'operator_person_id' => $f['siti']->id, 'external_technician_name' => 'Budi'])->assertCreated()
            ->assertJsonPath('data.operator_name', 'Siti')->assertJsonPath('data.external_technician_name', 'Budi');

        $shown = $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/tickets/{$t->id}")->assertOk()->json('data.actions');
        $this->assertCount(3, $shown);
        $this->assertSame(['Andi', null, 'Siti'], collect($shown)->sortBy('performed_at')->pluck('operator_name')->values()->all());
    }

    public function test_activity_inherits_ticket_responsible_people_when_no_performer_is_sent(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['internal_pic_person_id' => $f['andi']->id, 'internal_pic_name' => 'Andi', 'external_technician_name' => 'Budi', 'external_vendor_name' => 'KM']);
        $this->actingAs($f['owner'])->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'Cek'])->assertCreated()
            ->assertJsonPath('data.operator_name', 'Andi')->assertJsonPath('data.vendor_name', 'KM');
    }

    public function test_performer_must_be_active_and_assigned_to_the_ticket_branch_and_account(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        foreach (['otherBranchPerson', 'foreignPerson', 'inactive'] as $key) {
            $this->actingAs($f['owner'])->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'x', 'operator_person_id' => $f[$key]->id])
                ->assertStatus(422)->assertJsonValidationErrors('operator_person_id');
            $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$t->id}/responsible", ['internal_pic_person_id' => $f[$key]->id])
                ->assertStatus(422)->assertJsonValidationErrors('internal_pic_person_id');
        }
        $this->assertSame(0, MaintenanceAction::count());
    }

    public function test_responsible_can_be_internal_external_both_or_cleared_without_accounts(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $users = User::count();
        $url = "/api/v1/maintenance/tickets/{$t->id}/responsible";

        $this->actingAs($f['owner'])->patchJson($url, ['internal_pic_person_id' => $f['andi']->id, 'external_technician_name' => 'Budi', 'external_vendor_name' => 'Konica Minolta'])->assertOk();
        $t->refresh();
        $this->assertSame([(string) $f['andi']->id, 'Andi', 'Budi', 'Konica Minolta'], [(string) $t->internal_pic_person_id, $t->internal_pic_name, $t->external_technician_name, $t->external_vendor_name]);
        $this->actingAs($f['owner'])->patchJson($url, ['internal_pic_person_id' => $f['andi']->id])->assertOk();
        $this->assertNull($t->refresh()->external_technician_name);
        $this->actingAs($f['owner'])->patchJson($url, [])->assertOk();
        $this->assertNull($t->refresh()->internal_pic_person_id);
        $this->assertSame($users, User::count());
    }

    public function test_cross_account_and_cross_branch_users_are_rejected(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $stranger = $this->member($f['foreign'], 'owner');
        $wrongBranch = $this->member($f['account'], 'admin', $f['otherBranch']);
        foreach ([$stranger, $wrongBranch] as $user) {
            $this->actingAs($user)->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'x'])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/v1/maintenance/tickets/{$t->id}/responsible", [])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", $this->done())->assertForbidden();
        }
        $this->assertSame('IN_PROGRESS', $t->refresh()->status);
    }

    public function test_activity_history_is_append_only(): void
    {
        $f = $this->fixture();
        $a = app(MaintenanceTicketService::class)->recordAction($this->ticket($f), ['action_description' => 'Cek', 'performed_by' => $f['owner']->id]);
        try {
            $a->update(['action_description' => 'Diubah']);
            $this->fail('update should be rejected');
        } catch (\LogicException) {
        }
        try {
            $a->delete();
            $this->fail('delete should be rejected');
        } catch (\LogicException) {
        }
        $this->assertSame('Cek', $a->fresh()->action_description);
    }

    public function test_closed_tickets_reject_new_activities_and_responsible_changes(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['status' => 'DONE']);
        $this->actingAs($f['owner'])->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'x'])->assertStatus(409);
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$t->id}/responsible", [])->assertStatus(409);
    }

    public function test_breakdown_done_requires_a_complete_resolution_and_rolls_back_cleanly(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $url = "/api/v1/maintenance/tickets/{$t->id}/status";

        $this->actingAs($f['owner'])->patchJson($url, ['status' => 'DONE'])->assertStatus(422)->assertJsonValidationErrors(['resolution_cause', 'resolution_action', 'resolution_test_result']);
        $this->actingAs($f['owner'])->patchJson($url, $this->done(['resolution_action' => '  ']))->assertStatus(422)->assertJsonValidationErrors('resolution_action');
        $this->actingAs($f['owner'])->patchJson($url, $this->done(['resolution_test_result' => 'bogus']))->assertStatus(422);
        $t->refresh();
        $this->assertSame('IN_PROGRESS', $t->status);
        $this->assertNull($t->resolved_at);
        $this->assertNull($t->resolution_cause);
    }

    public function test_belum_normal_blocks_breakdown_completion_but_normal_and_monitoring_complete(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $url = "/api/v1/maintenance/tickets/{$t->id}/status";
        $this->actingAs($f['owner'])->patchJson($url, $this->done(['resolution_test_result' => 'not_normal']))->assertStatus(422)->assertJsonValidationErrors('resolution_test_result');
        $this->assertSame('IN_PROGRESS', $t->refresh()->status);

        $this->actingAs($f['owner'])->patchJson($url, $this->done(['resolution_test_result' => 'monitoring', 'resolution_notes' => 'Pantau 3 hari']))->assertOk()->assertJsonPath('data.status', 'DONE');
        $t->refresh();
        $this->assertSame([$f['owner']->id, 'monitoring', 'Pantau 3 hari'], [$t->resolved_by, $t->resolution_test_result, $t->resolution_notes]);
        $this->assertNotNull($t->resolved_at);

        $n = $this->ticket($f);
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$n->id}/status", $this->done())->assertOk();
        $this->assertSame('normal', $n->refresh()->resolution_test_result);
    }

    public function test_no_activity_entry_is_required_before_done(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", $this->done())->assertOk();
        $this->assertSame(0, MaintenanceAction::count());
    }

    public function test_preventive_and_inspection_tickets_keep_resolution_optional(): void
    {
        $f = $this->fixture();
        foreach (['preventive', 'inspection'] as $type) {
            $t = $this->ticket($f, ['type' => $type]);
            $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", ['status' => 'DONE'])->assertOk();
            $this->assertNull($t->refresh()->resolution_cause);
        }
        $t = $this->ticket($f, ['type' => 'preventive']);
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", ['status' => 'DONE', 'resolution_cause' => 'Jadwal'])->assertStatus(422);
        $this->assertSame('IN_PROGRESS', $t->refresh()->status);
    }

    public function test_legacy_done_tickets_stay_done_and_render_without_resolution(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f, ['status' => 'DONE', 'resolved_at' => now()->subDay()]);
        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/tickets/{$t->id}")->assertOk()
            ->assertJsonPath('data.status', 'DONE')->assertJsonPath('data.resolution_cause', null)->assertJsonPath('data.resolver', null);
    }

    public function test_ticket_and_downtime_lifecycles_stay_independent(): void
    {
        $f = $this->fixture();
        $started = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'hasil_kotor', 'description' => 'Noda', 'client_request_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $ticketId = $started['maintenance_ticket']['id'];
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$ticketId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$ticketId}/status", $this->done())->assertOk();
        $event = DowntimeEvent::findOrFail($started['id']);
        $this->assertTrue($event->isActive());
        $startAt = $event->actual_start_at->toIso8601String();

        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$started['id']}/end", [])->assertOk();
        $this->assertSame('DONE', MaintenanceTicket::findOrFail($ticketId)->status);
        $this->assertSame($startAt, $event->refresh()->actual_start_at->toIso8601String());
    }

    // --- Operator-role maintenance workflow (real operator capability context) ---

    public function test_operator_can_run_the_maintenance_workflow_on_an_authorized_ticket(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['account'], 'operator', $f['branch']);
        $t = $this->ticket($f, ['status' => 'OPEN']);

        $this->actingAs($operator)->getJson("/api/v1/maintenance/tickets/{$t->id}")->assertOk();
        $this->actingAs($operator)->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->actingAs($operator)->patchJson("/api/v1/maintenance/tickets/{$t->id}/responsible", ['internal_pic_person_id' => $f['andi']->id])->assertOk();
        $this->actingAs($operator)->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'Pembersihan'])->assertCreated()
            ->assertJsonPath('data.performed_by', $operator->id)->assertJsonPath('data.operator_name', 'Andi');
        $this->actingAs($operator)->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", ['status' => 'DONE'])->assertStatus(422);
        $this->actingAs($operator)->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", $this->done(['resolution_test_result' => 'not_normal']))->assertStatus(422);
        $this->assertSame('IN_PROGRESS', $t->refresh()->status);
        $this->actingAs($operator)->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", $this->done())->assertOk();
        $this->assertSame($operator->id, $t->refresh()->resolved_by);
    }

    public function test_operator_is_confined_to_authorized_branch_and_account(): void
    {
        $f = $this->fixture();
        $otherBranchOperator = $this->member($f['account'], 'operator', $f['otherBranch']);
        $foreignOperator = $this->member($f['foreign'], 'operator');
        $t = $this->ticket($f);
        foreach ([$otherBranchOperator, $foreignOperator] as $user) {
            $this->actingAs($user)->getJson("/api/v1/maintenance/tickets/{$t->id}")->assertForbidden();
            $this->actingAs($user)->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'x'])->assertForbidden();
            $this->actingAs($user)->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", $this->done())->assertForbidden();
            $this->actingAs($user)->patchJson("/api/v1/maintenance/tickets/{$t->id}/responsible", [])->assertForbidden();
        }
        $operator = $this->member($f['account'], 'operator', $f['branch']);
        $this->actingAs($operator)->patchJson("/api/v1/maintenance/tickets/{$t->id}/responsible", ['internal_pic_person_id' => $f['otherBranchPerson']->id])->assertStatus(422);
    }

    public function test_technician_admin_and_owner_keep_the_workflow_capabilities(): void
    {
        $f = $this->fixture();
        foreach (['technician', 'admin', 'owner'] as $role) {
            $user = $this->member($f['account'], $role, $f['branch']);
            $t = $this->ticket($f);
            $this->actingAs($user)->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'x'])->assertCreated();
            $this->actingAs($user)->patchJson("/api/v1/maintenance/tickets/{$t->id}/responsible", ['internal_pic_person_id' => $f['siti']->id])->assertOk();
            $this->actingAs($user)->patchJson("/api/v1/maintenance/tickets/{$t->id}/status", $this->done())->assertOk();
            $c = $this->ticket($f);
            $this->actingAs($user)->patchJson("/api/v1/maintenance/tickets/{$c->id}/status", ['status' => 'CANCELLED'])->assertOk();
        }
    }

    // --- Auditable activity correction ---

    private function original(array $f, MaintenanceTicket $t): array
    {
        return $this->actingAs($f['owner'])->postJson("/api/v1/maintenance/tickets/{$t->id}/actions", ['action_description' => 'Mengganti drum', 'operator_person_id' => $f['andi']->id])->assertCreated()->json('data');
    }

    public function test_correction_is_a_new_referencing_row_that_leaves_the_original_intact(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $orig = $this->original($f, $t);
        $admin = $this->member($f['account'], 'admin', $f['branch']);

        $c = $this->actingAs($admin)->postJson("/api/v1/maintenance/tickets/{$t->id}/actions/{$orig['id']}/corrections", ['action_description' => 'Membersihkan drum', 'correction_reason' => 'Kesalahan penulisan tindakan'])
            ->assertCreated()->json('data');
        $this->assertSame($orig['id'], $c['corrects_action_id']);
        $this->assertSame('Kesalahan penulisan tindakan', $c['correction_reason']);
        $this->assertSame($admin->id, $c['performed_by']);
        $this->assertNotNull($c['performed_at']);
        $this->assertSame('Andi', $c['operator_name']);
        $this->assertSame('Mengganti drum', MaintenanceAction::find($orig['id'])->action_description);
        $this->assertSame(2, MaintenanceAction::count());
        $shown = $this->actingAs($admin)->getJson("/api/v1/maintenance/tickets/{$t->id}")->assertOk()->json('data.actions');
        $this->assertCount(2, $shown);
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_action.corrected']);
    }

    public function test_correction_requires_a_reason_and_an_original_activity_on_the_same_ticket(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $other = $this->ticket($f);
        $orig = $this->original($f, $t);
        $url = fn ($ticket, $id) => "/api/v1/maintenance/tickets/{$ticket->id}/actions/{$id}/corrections";

        $this->actingAs($f['owner'])->postJson($url($t, $orig['id']), ['action_description' => 'x'])->assertStatus(422)->assertJsonValidationErrors('correction_reason');
        $this->actingAs($f['owner'])->postJson($url($t, $orig['id']), ['action_description' => 'x', 'correction_reason' => '  '])->assertStatus(422);
        $this->actingAs($f['owner'])->postJson($url($other, $orig['id']), ['action_description' => 'x', 'correction_reason' => 'salah tiket'])->assertNotFound();

        $c = $this->actingAs($f['owner'])->postJson($url($t, $orig['id']), ['action_description' => 'ok', 'correction_reason' => 'typo'])->assertCreated()->json('data');
        $this->actingAs($f['owner'])->postJson($url($t, $c['id']), ['action_description' => 'again', 'correction_reason' => 'typo'])->assertStatus(422);
        $this->assertSame(2, MaintenanceAction::count());
    }

    public function test_correction_is_confined_by_account_and_branch_and_validates_performers(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $orig = $this->original($f, $t);
        $body = ['action_description' => 'x', 'correction_reason' => 'typo'];
        foreach ([$this->member($f['foreign'], 'owner'), $this->member($f['account'], 'admin', $f['otherBranch'])] as $user) {
            $this->actingAs($user)->postJson("/api/v1/maintenance/tickets/{$t->id}/actions/{$orig['id']}/corrections", $body)->assertForbidden();
        }
        $this->actingAs($f['owner'])->postJson("/api/v1/maintenance/tickets/{$t->id}/actions/{$orig['id']}/corrections", $body + ['operator_person_id' => $f['foreignPerson']->id])->assertStatus(422);
        $this->assertSame(1, MaintenanceAction::count());
    }

    public function test_correction_leaves_ticket_status_and_downtime_untouched_and_works_on_closed_tickets(): void
    {
        $f = $this->fixture();
        $started = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'hasil_kotor', 'description' => 'Noda', 'client_request_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $ticketId = $started['maintenance_ticket']['id'];
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$ticketId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
        $orig = $this->actingAs($f['owner'])->postJson("/api/v1/maintenance/tickets/{$ticketId}/actions", ['action_description' => 'Mengganti drum'])->assertCreated()->json('data');
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$ticketId}/status", $this->done())->assertOk();
        $ticketBefore = MaintenanceTicket::find($ticketId)->only(['status', 'resolved_at', 'resolution_cause']);
        $eventBefore = DowntimeEvent::find($started['id'])->only(['actual_start_at', 'actual_end_at']);

        $this->actingAs($f['owner'])->postJson("/api/v1/maintenance/tickets/{$ticketId}/actions/{$orig['id']}/corrections", ['action_description' => 'Membersihkan drum', 'correction_reason' => 'typo'])->assertCreated();

        $this->assertEquals($ticketBefore, MaintenanceTicket::find($ticketId)->only(['status', 'resolved_at', 'resolution_cause']));
        $this->assertEquals($eventBefore, DowntimeEvent::find($started['id'])->only(['actual_start_at', 'actual_end_at']));
    }

    public function test_operator_can_correct_on_authorized_ticket_and_original_rows_cannot_be_deleted(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $orig = $this->original($f, $t);
        $operator = $this->member($f['account'], 'operator', $f['branch']);
        $this->actingAs($operator)->postJson("/api/v1/maintenance/tickets/{$t->id}/actions/{$orig['id']}/corrections", ['action_description' => 'Membersihkan drum', 'correction_reason' => 'typo'])->assertCreated();
        $this->expectException(\LogicException::class);
        MaintenanceAction::find($orig['id'])->delete();
    }

    public function test_phase_3_2_migrations_roll_back_and_reapply_without_losing_existing_rows(): void
    {
        $f = $this->fixture();
        $t = $this->ticket($f);
        $action = app(MaintenanceTicketService::class)->recordAction($t, ['action_description' => 'Cek', 'performed_by' => $f['owner']->id]);
        $corrections = require database_path('migrations/2026_10_20_000200_add_maintenance_action_corrections.php');
        $performers = require database_path('migrations/2026_10_20_000100_add_maintenance_activity_performers_and_resolution.php');

        // SQLite rebuilds tables for column drops; MySQL alters in place. Disabling FKs only affects that rebuild.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        $corrections->down();
        $performers->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('maintenance_actions', 'corrects_action_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('maintenance_tickets', 'resolution_cause'));
        $this->assertSame(1, MaintenanceTicket::count());
        $this->assertSame('Cek', \Illuminate\Support\Facades\DB::table('maintenance_actions')->where('id', $action->id)->value('action_description'));

        $performers->up();
        $corrections->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('maintenance_actions', 'corrects_action_id'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('maintenance_tickets', 'resolved_by'));
        $this->assertSame(1, MaintenanceAction::count());
    }
}
