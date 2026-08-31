<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalReportParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_endpoint_is_scoped_and_returns_canonical_contract(): void
    {
        $account = Account::create(['code' => 'RPT', 'name' => 'Reports', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'M', 'name' => 'Maker']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'X', 'name' => 'Model']);
        Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A1', 'display_name' => 'A1', 'status' => 'active']);

        $this->actingAs($user)->getJson('/api/v1/reports?account_id='.$account->id.'&branch_id='.$branch->id.'&period_start=2026-08-01&period_end=2026-08-31')
            ->assertOk()->assertJsonStructure(['period', 'scope', 'overview', 'machine_cost', 'daily_clicks', 'counter', 'operator_activity', 'incidents', 'replacements', 'inventory_consumption'])
            ->assertJsonPath('overview.total_clicks', 0);
    }
}
