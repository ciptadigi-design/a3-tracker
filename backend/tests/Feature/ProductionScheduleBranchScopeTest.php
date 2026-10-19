<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ProductionScheduleException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2.1 - Jadwal Operasional respects existing branch assignments: the Settings
 * payload only lists assigned branches, and every schedule endpoint enforces the
 * same scope server-side (no reliance on frontend filtering).
 */
class ProductionScheduleBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private array $branch = [];

    private array $machine = [];

    private array $user = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $model = MachineModel::create(['manufacturer_id' => Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta'])->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        foreach (['tuparev' => 'Tuparev', 'graha' => 'Graha', 'cianjur' => 'Cianjur'] as $key => $name) {
            $this->branch[$key] = Branch::create(['account_id' => $this->account->id, 'code' => strtoupper($key), 'name' => $name, 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
            $this->machine[$key] = Machine::create(['account_id' => $this->account->id, 'branch_id' => $this->branch[$key]->id, 'machine_model_id' => $model->id, 'machine_code' => strtoupper($key).'-01', 'display_name' => $name.' 1', 'status' => 'active']);
        }
        $this->user = [
            'single' => $this->member('admin', ['tuparev']),
            'multi' => $this->member('admin', ['tuparev', 'graha']),
            'owner' => $this->member('owner', []),
            'none' => $this->member('admin', []),
            'technician' => $this->member('technician', ['tuparev']),
        ];
    }

    private function member(string $role, array $branches): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $this->account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        foreach ($branches as $key) {
            AccountMembershipBranch::create(['account_id' => $this->account->id, 'membership_id' => $membership->id, 'branch_id' => $this->branch[$key]->id, 'is_active' => true]);
        }

        return $user;
    }

    private function settingsBranchCodes(string $who): array
    {
        $rows = $this->actingAs($this->user[$who])->getJson("/api/v1/accounts/{$this->account->id}/settings")->assertOk()->json('data.branches');

        return collect($rows)->pluck('code')->sort()->values()->all();
    }

    private function week(): array
    {
        return collect(range(0, 6))->map(fn ($d) => ['day_of_week' => $d, 'is_operating' => $d >= 1 && $d <= 5, 'start_time' => $d >= 1 && $d <= 5 ? '08:00' : null, 'end_time' => $d >= 1 && $d <= 5 ? '17:00' : null, 'crosses_midnight' => false])->all();
    }

    public function test_single_branch_account_only_receives_its_assigned_branch(): void
    {
        $this->assertSame(['TUPAREV'], $this->settingsBranchCodes('single'));
    }

    public function test_multi_branch_account_only_receives_assigned_branches(): void
    {
        $this->assertSame(['GRAHA', 'TUPAREV'], $this->settingsBranchCodes('multi'));
    }

    public function test_organization_wide_owner_receives_every_branch_without_username_special_casing(): void
    {
        $this->assertSame(['CIANJUR', 'GRAHA', 'TUPAREV'], $this->settingsBranchCodes('owner'));
    }

    public function test_account_with_no_assigned_branch_receives_no_branches_and_cannot_use_any_schedule_endpoint(): void
    {
        $this->assertSame([], $this->settingsBranchCodes('none'));
        $none = $this->actingAs($this->user['none']);
        foreach (['tuparev', 'graha'] as $key) {
            $none->getJson("/api/v1/operations/branches/{$this->branch[$key]->id}/production-schedule")->assertForbidden();
            $none->putJson("/api/v1/operations/branches/{$this->branch[$key]->id}/production-schedule", ['days' => $this->week()])->assertForbidden();
            $none->getJson("/api/v1/branches/{$this->branch[$key]->id}/machines")->assertForbidden();
        }
    }

    public function test_branch_restricted_user_cannot_read_or_write_unauthorized_branch_schedules(): void
    {
        $single = $this->actingAs($this->user['single']);
        $url = fn (string $key) => "/api/v1/operations/branches/{$this->branch[$key]->id}/production-schedule";

        $single->putJson($url('tuparev'), ['days' => $this->week()])->assertCreated();
        $single->getJson($url('tuparev'))->assertOk()->assertJsonCount(7, 'data');
        foreach (['graha', 'cianjur'] as $key) {
            $single->getJson($url($key))->assertForbidden();
            $single->putJson($url($key), ['days' => $this->week()])->assertForbidden();
            $single->getJson("/api/v1/branches/{$this->branch[$key]->id}/machines")->assertForbidden();
        }
        $this->assertDatabaseMissing('branch_production_schedules', ['branch_id' => $this->branch['graha']->id]);
    }

    public function test_multi_branch_user_reaches_exactly_the_assigned_branches(): void
    {
        $multi = $this->actingAs($this->user['multi']);
        foreach (['tuparev', 'graha'] as $key) {
            $multi->putJson("/api/v1/operations/branches/{$this->branch[$key]->id}/production-schedule", ['days' => $this->week()])->assertCreated();
        }
        $multi->putJson("/api/v1/operations/branches/{$this->branch['cianjur']->id}/production-schedule", ['days' => $this->week()])->assertForbidden();
    }

    public function test_switching_branches_returns_each_branchs_own_schedule_only(): void
    {
        $multi = $this->actingAs($this->user['multi']);
        $tuparev = $this->week();
        $graha = collect($this->week())->map(fn ($d) => $d['day_of_week'] === 1 ? ['day_of_week' => 1, 'is_operating' => true, 'start_time' => '06:00', 'end_time' => '14:00', 'crosses_midnight' => false] : $d)->all();
        $multi->putJson("/api/v1/operations/branches/{$this->branch['tuparev']->id}/production-schedule", ['days' => $tuparev])->assertCreated();
        $multi->putJson("/api/v1/operations/branches/{$this->branch['graha']->id}/production-schedule", ['days' => $graha])->assertCreated();

        $monday = fn (string $key) => collect($multi->getJson("/api/v1/operations/branches/{$this->branch[$key]->id}/production-schedule")->assertOk()->json('data'))->firstWhere('day_of_week', 1);
        $this->assertSame('08:00', substr($monday('tuparev')['start_time'], 0, 5));
        $this->assertSame('06:00', substr($monday('graha')['start_time'], 0, 5));
    }

    public function test_machine_override_endpoints_are_scoped_to_assigned_branches(): void
    {
        $single = $this->actingAs($this->user['single']);
        $own = "/api/v1/operations/machines/{$this->machine['tuparev']->id}/production-schedule-override";
        $other = "/api/v1/operations/machines/{$this->machine['graha']->id}/production-schedule-override";

        $single->putJson($own, ['days' => $this->week()])->assertCreated();
        $single->getJson($other)->assertForbidden();
        $single->putJson($other, ['days' => $this->week()])->assertForbidden();
        $single->deleteJson($other)->assertForbidden();
        $this->assertDatabaseMissing('machine_production_schedule_overrides', ['machine_id' => $this->machine['graha']->id]);
    }

    public function test_date_exceptions_are_scoped_to_assigned_branches_and_machines_of_that_branch(): void
    {
        $single = $this->actingAs($this->user['single']);
        $own = "/api/v1/operations/branches/{$this->branch['tuparev']->id}/production-schedule/exceptions";
        $other = "/api/v1/operations/branches/{$this->branch['graha']->id}/production-schedule/exceptions";
        $payload = ['exception_date' => '2026-10-20', 'type' => 'holiday', 'is_operating' => false];

        $created = $single->postJson($own, $payload)->assertCreated()->json('data');
        $single->getJson("$other?year=2026&month=10")->assertForbidden();
        $single->postJson($other, $payload)->assertForbidden();
        // A foreign branch's machine cannot be smuggled into an authorized branch's exception.
        $single->postJson($own, ['exception_date' => '2026-10-21'] + $payload + ['machine_id' => $this->machine['graha']->id])->assertNotFound();

        $foreign = ProductionScheduleException::create(['account_id' => $this->account->id, 'branch_id' => $this->branch['graha']->id, 'exception_date' => '2026-10-22', 'type' => 'holiday', 'is_operating' => false, 'created_by' => $this->user['owner']->id]);
        $single->deleteJson("/api/v1/operations/production-schedule/exceptions/{$foreign->id}")->assertForbidden();
        $this->assertDatabaseHas('production_schedule_exceptions', ['id' => $foreign->id]);
        $single->deleteJson("/api/v1/operations/production-schedule/exceptions/{$created['id']}")->assertOk();
    }

    public function test_view_permission_is_still_separate_from_schedule_management(): void
    {
        $technician = $this->actingAs($this->user['technician']);
        $url = "/api/v1/operations/branches/{$this->branch['tuparev']->id}/production-schedule";
        $technician->getJson($url)->assertOk();
        $technician->putJson($url, ['days' => $this->week()])->assertForbidden();
    }

    public function test_owner_can_manage_every_branch(): void
    {
        $owner = $this->actingAs($this->user['owner']);
        foreach (['tuparev', 'graha', 'cianjur'] as $key) {
            $owner->putJson("/api/v1/operations/branches/{$this->branch[$key]->id}/production-schedule", ['days' => $this->week()])->assertCreated();
        }
    }
}
