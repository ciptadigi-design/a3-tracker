<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MachineClickTargetAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithMachine(string $code = 'CTA'): array
    {
        $account = Account::create(['code' => $code, 'name' => "$code Account", 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $manufacturer = Manufacturer::firstOrCreate(['code' => 'KM'], ['name' => 'Konica Minolta']);
        $model = MachineModel::firstOrCreate(['model_code' => "$code-C1070"], ['manufacturer_id' => $manufacturer->id, 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => "$code-A3-01", 'display_name' => 'C1070', 'status' => 'active']);

        return compact('account', 'branch', 'machine');
    }

    private function member(Account $account, \App\Models\Branch $branch, string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);

        return $user;
    }

    public function test_owner_can_read_and_write_the_monthly_target(): void
    {
        $f = $this->accountWithMachine();
        $owner = $this->member($f['account'], $f['branch'], 'owner');

        $this->actingAs($owner)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 500000, 'client_request_id' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.monthly_target', 500000);

        $this->actingAs($owner)->getJson("/api/v1/machines/{$f['machine']->id}/click-target?year=2026&month=9")
            ->assertOk()
            ->assertJsonPath('data.monthly_target', 500000);
    }

    public function test_admin_can_write_the_monthly_target(): void
    {
        $f = $this->accountWithMachine();
        $admin = $this->member($f['account'], $f['branch'], 'admin');

        $this->actingAs($admin)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 1000, 'client_request_id' => (string) Str::uuid()])
            ->assertCreated();
    }

    public function test_operator_can_read_but_not_write_the_monthly_target(): void
    {
        $f = $this->accountWithMachine();
        $operator = $this->member($f['account'], $f['branch'], 'operator');

        $this->actingAs($operator)->getJson("/api/v1/machines/{$f['machine']->id}/click-target?year=2026&month=9")->assertOk();
        $this->actingAs($operator)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 1000, 'client_request_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_technician_cannot_write_the_monthly_target(): void
    {
        $f = $this->accountWithMachine();
        $technician = $this->member($f['account'], $f['branch'], 'technician');

        $this->actingAs($technician)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 1000, 'client_request_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_platform_superuser_bypasses_the_operator_role_restriction(): void
    {
        // Branch-scoped machine access still requires an account membership (matching
        // the existing BranchAccessResolver/MachineAccessResolver convention used by
        // Machine Cost), but the account.canManageOperational role check is bypassed
        // for a platform superuser even when their own membership role is 'operator'.
        $f = $this->accountWithMachine();
        $superuser = $this->member($f['account'], $f['branch'], 'operator');
        PlatformUserPrivilege::create(['user_id' => $superuser->id, 'role' => 'superuser', 'is_active' => true]);

        $this->actingAs($superuser)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 1000, 'client_request_id' => (string) Str::uuid()])->assertCreated();
    }

    public function test_cross_account_direct_machine_id_is_denied(): void
    {
        $f1 = $this->accountWithMachine('CTA');
        $f2 = $this->accountWithMachine('CTB');
        $memberOfOne = $this->member($f1['account'], $f1['branch'], 'owner');

        $this->actingAs($memberOfOne)->getJson("/api/v1/machines/{$f2['machine']->id}/click-target?year=2026&month=9")->assertForbidden();
        $this->actingAs($memberOfOne)->putJson("/api/v1/machines/{$f2['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 1000, 'client_request_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_cross_branch_member_is_denied(): void
    {
        $account = Account::create(['code' => 'CTC', 'name' => 'CTC Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branchA = $account->branches()->create(['code' => 'A', 'name' => 'A', 'is_active' => true]);
        $branchB = $account->branches()->create(['code' => 'B', 'name' => 'B', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'CTC-C1070', 'name' => 'C1070']);
        $machineB = Machine::create(['account_id' => $account->id, 'branch_id' => $branchB->id, 'machine_model_id' => $model->id, 'machine_code' => 'CTC-B-01', 'display_name' => 'C1070', 'status' => 'active']);
        // 'owner' sees every branch in the account by design (BranchAccessResolver
        // owner bypass) - use a non-owner role to prove branch-level scoping.
        $memberOfA = $this->member($account, $branchA, 'technician');

        $this->actingAs($memberOfA)->getJson("/api/v1/machines/{$machineB->id}/click-target?year=2026&month=9")->assertForbidden();
    }

    public function test_unknown_machine_id_fails_closed(): void
    {
        $f = $this->accountWithMachine();
        $owner = $this->member($f['account'], $f['branch'], 'owner');

        $this->actingAs($owner)->getJson('/api/v1/machines/00000000-0000-4000-8000-000000000000/click-target?year=2026&month=9')->assertNotFound();
    }

    public function test_target_revision_history_is_recorded_on_change(): void
    {
        $f = $this->accountWithMachine();
        $owner = $this->member($f['account'], $f['branch'], 'owner');

        $this->actingAs($owner)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 100000, 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($owner)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 150000, 'reason' => 'Seasonal demand', 'client_request_id' => (string) Str::uuid()])->assertCreated();

        $history = $this->actingAs($owner)->getJson("/api/v1/machines/{$f['machine']->id}/click-target/history")->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame(150000, $history[0]['new_target']);
        $this->assertSame(100000, $history[0]['previous_target']);
        $this->assertSame('Seasonal demand', $history[0]['reason']);
        $this->assertNull($history[1]['previous_target']);
    }

    public function test_duplicate_client_request_id_is_idempotent_not_a_second_revision(): void
    {
        $f = $this->accountWithMachine();
        $owner = $this->member($f['account'], $f['branch'], 'owner');
        $requestId = (string) Str::uuid();

        $this->actingAs($owner)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 100000, 'client_request_id' => $requestId])->assertCreated();
        $this->actingAs($owner)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 100000, 'client_request_id' => $requestId])->assertOk();

        $history = $this->actingAs($owner)->getJson("/api/v1/machines/{$f['machine']->id}/click-target/history")->json('data');
        $this->assertCount(1, $history);
    }

    public function test_zero_target_is_rejected(): void
    {
        $f = $this->accountWithMachine();
        $owner = $this->member($f['account'], $f['branch'], 'owner');

        $this->actingAs($owner)->putJson("/api/v1/machines/{$f['machine']->id}/click-target", ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 0, 'client_request_id' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monthly_click_target']);
    }

    public function test_calendar_exception_create_and_remove(): void
    {
        $f = $this->accountWithMachine();
        $owner = $this->member($f['account'], $f['branch'], 'owner');

        $created = $this->actingAs($owner)->postJson("/api/v1/machines/{$f['machine']->id}/click-target/calendar-exceptions", ['calendar_date' => '2026-09-10', 'exception_type' => 'planned_maintenance', 'notes' => 'Drum swap', 'client_request_id' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.exception_type', 'planned_maintenance')
            ->json('data');

        $this->actingAs($owner)->getJson("/api/v1/machines/{$f['machine']->id}/click-target/calendar-exceptions?year=2026&month=9")->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($owner)->deleteJson("/api/v1/calendar-exceptions/{$created['id']}")->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/machines/{$f['machine']->id}/click-target/calendar-exceptions?year=2026&month=9")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_operator_cannot_create_calendar_exceptions(): void
    {
        $f = $this->accountWithMachine();
        $operator = $this->member($f['account'], $f['branch'], 'operator');

        $this->actingAs($operator)->postJson("/api/v1/machines/{$f['machine']->id}/click-target/calendar-exceptions", ['calendar_date' => '2026-09-10', 'exception_type' => 'other', 'client_request_id' => (string) Str::uuid()])->assertForbidden();
    }
}
