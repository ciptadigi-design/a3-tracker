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
use Tests\TestCase;

/**
 * P0 security regression coverage: a member assigned to only one branch
 * (e.g. Tuparev) must never see, or be able to reach, another branch's
 * (e.g. Graha) data - neither via the branch list the frontend selector
 * renders, nor via direct API calls against the other branch's resources.
 */
class BranchAuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private function twoBranchAccount(): array
    {
        $account = Account::create(['code' => 'KNC', 'name' => 'Konica Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $tuparev = $account->branches()->create(['code' => 'TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $graha = $account->branches()->create(['code' => 'GRH', 'name' => 'Graha', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $tuparevMachine = Machine::create(['account_id' => $account->id, 'branch_id' => $tuparev->id, 'machine_model_id' => $model->id, 'machine_code' => 'TUP-A3-01', 'display_name' => 'Tuparev C1070', 'status' => 'active']);
        $grahaMachine = Machine::create(['account_id' => $account->id, 'branch_id' => $graha->id, 'machine_model_id' => $model->id, 'machine_code' => 'GRH-A3', 'display_name' => 'Graha C1070', 'status' => 'active']);

        return compact('account', 'tuparev', 'graha', 'tuparevMachine', 'grahaMachine');
    }

    private function memberOnlyOn(Account $account, \App\Models\Branch $branch, string $role = 'technician'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);

        return compact('user', 'membership');
    }

    private function branchIds($response): array
    {
        return collect($response->json('data.data') ?? $response->json('data'))->pluck('id')->all();
    }

    public function test_superuser_sees_all_branches(): void
    {
        $g = $this->twoBranchAccount();
        $u = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $u->id, 'role' => 'superuser', 'is_active' => true]);

        $res = $this->actingAs($u)->getJson('/api/v1/accounts/'.$g['account']->id.'/branches?per_page=50')->assertOk();
        $ids = $this->branchIds($res);
        $this->assertContains($g['tuparev']->id, $ids);
        $this->assertContains($g['graha']->id, $ids);
    }

    public function test_tuparev_only_member_branch_list_excludes_graha(): void
    {
        $g = $this->twoBranchAccount();
        $m = $this->memberOnlyOn($g['account'], $g['tuparev']);

        $res = $this->actingAs($m['user'])->getJson('/api/v1/accounts/'.$g['account']->id.'/branches?per_page=50')->assertOk();
        $ids = $this->branchIds($res);
        $this->assertContains($g['tuparev']->id, $ids);
        $this->assertNotContains($g['graha']->id, $ids);
    }

    public function test_graha_only_member_branch_list_excludes_tuparev(): void
    {
        $g = $this->twoBranchAccount();
        $m = $this->memberOnlyOn($g['account'], $g['graha']);

        $res = $this->actingAs($m['user'])->getJson('/api/v1/accounts/'.$g['account']->id.'/branches?per_page=50')->assertOk();
        $ids = $this->branchIds($res);
        $this->assertContains($g['graha']->id, $ids);
        $this->assertNotContains($g['tuparev']->id, $ids);
    }

    public function test_multi_branch_member_sees_both_branches(): void
    {
        $g = $this->twoBranchAccount();
        $m = $this->memberOnlyOn($g['account'], $g['tuparev']);
        AccountMembershipBranch::create(['account_id' => $g['account']->id, 'membership_id' => $m['membership']->id, 'branch_id' => $g['graha']->id, 'is_active' => true]);

        $res = $this->actingAs($m['user'])->getJson('/api/v1/accounts/'.$g['account']->id.'/branches?per_page=50')->assertOk();
        $ids = $this->branchIds($res);
        $this->assertContains($g['tuparev']->id, $ids);
        $this->assertContains($g['graha']->id, $ids);
    }

    public function test_no_branch_member_fails_closed_not_crash(): void
    {
        $g = $this->twoBranchAccount();
        $m = $this->memberOnlyOn($g['account'], $g['tuparev']);
        // Owner revoked every branch assignment (e.g. via Settings -> Members).
        AccountMembershipBranch::where('membership_id', $m['membership']->id)->update(['is_active' => false]);

        $res = $this->actingAs($m['user'])->getJson('/api/v1/accounts/'.$g['account']->id.'/branches?per_page=50')->assertOk();
        $this->assertSame([], $this->branchIds($res));
    }

    public function test_tuparev_only_member_direct_access_to_graha_is_denied(): void
    {
        $g = $this->twoBranchAccount();
        $m = $this->memberOnlyOn($g['account'], $g['tuparev']);
        $this->actingAs($m['user']);

        // Branch-scoped machine list for Graha.
        $this->getJson('/api/v1/branches/'.$g['graha']->id.'/machines')->assertForbidden();
        // Direct machine detail for GRH-A3.
        $this->getJson('/api/v1/machines/'.$g['grahaMachine']->id)->assertForbidden();
        // Counters for the Graha machine.
        $this->getJson('/api/v1/machines/'.$g['grahaMachine']->id.'/counters')->assertForbidden();
        $this->getJson('/api/v1/machines/'.$g['grahaMachine']->id.'/counters/period?from=2026-08-01&to=2026-08-31')->assertForbidden();
        // Machine Cost for the Graha machine.
        $this->getJson('/api/v1/machines/'.$g['grahaMachine']->id.'/cost?period_start=2026-08-01&period_end=2026-08-31')->assertForbidden();
        // Components for the Graha machine.
        $this->getJson('/api/v1/machines/'.$g['grahaMachine']->id.'/components')->assertForbidden();
        // Inventory workspace for Graha.
        $this->getJson('/api/v1/accounts/'.$g['account']->id.'/branches/'.$g['graha']->id.'/inventory')->assertForbidden();
        // Incidents list/detail for Graha.
        $this->getJson('/api/v1/accounts/'.$g['account']->id.'/branches/'.$g['graha']->id.'/incidents')->assertForbidden();
        // Reports scoped to Graha branch.
        $this->getJson('/api/v1/reports?account_id='.$g['account']->id.'&branch_id='.$g['graha']->id.'&period_start=2026-08-01&period_end=2026-08-31')->assertForbidden();
        // Reports scoped to the Graha machine WITHOUT branch_id - the bypass this audit found and closed.
        $this->getJson('/api/v1/reports?account_id='.$g['account']->id.'&machine_id='.$g['grahaMachine']->id.'&period_start=2026-08-01&period_end=2026-08-31')->assertForbidden();
    }

    public function test_graha_only_member_direct_access_to_tuparev_is_denied(): void
    {
        $g = $this->twoBranchAccount();
        $m = $this->memberOnlyOn($g['account'], $g['graha']);
        $this->actingAs($m['user']);

        $this->getJson('/api/v1/branches/'.$g['tuparev']->id.'/machines')->assertForbidden();
        $this->getJson('/api/v1/machines/'.$g['tuparevMachine']->id)->assertForbidden();
        $this->getJson('/api/v1/machines/'.$g['tuparevMachine']->id.'/counters')->assertForbidden();
        $this->getJson('/api/v1/machines/'.$g['tuparevMachine']->id.'/cost?period_start=2026-08-01&period_end=2026-08-31')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$g['account']->id.'/branches/'.$g['tuparev']->id.'/inventory')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$g['account']->id.'/branches/'.$g['tuparev']->id.'/incidents')->assertForbidden();
        $this->getJson('/api/v1/reports?account_id='.$g['account']->id.'&machine_id='.$g['tuparevMachine']->id.'&period_start=2026-08-01&period_end=2026-08-31')->assertForbidden();
    }

    public function test_tuparev_only_member_can_access_own_branch_data(): void
    {
        $g = $this->twoBranchAccount();
        $m = $this->memberOnlyOn($g['account'], $g['tuparev']);
        $this->actingAs($m['user']);

        $this->getJson('/api/v1/branches/'.$g['tuparev']->id.'/machines')->assertOk();
        $this->getJson('/api/v1/machines/'.$g['tuparevMachine']->id)->assertOk();
        $this->getJson('/api/v1/reports?account_id='.$g['account']->id.'&machine_id='.$g['tuparevMachine']->id.'&period_start=2026-08-01&period_end=2026-08-31')->assertOk();
    }

    public function test_incident_read_endpoints_reject_unrelated_account_members(): void
    {
        $g = $this->twoBranchAccount();
        $other = Account::create(['code' => 'OTH', 'name' => 'Other Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $u = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $other->id, 'user_id' => $u->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);

        $this->actingAs($u)->getJson('/api/v1/accounts/'.$g['account']->id.'/branches/'.$g['tuparev']->id.'/incidents')->assertForbidden();
    }

}
