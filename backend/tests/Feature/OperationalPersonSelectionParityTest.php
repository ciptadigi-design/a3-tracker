<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\OperationalPersonEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalPersonSelectionParityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'OPS', 'name' => 'Operations', 'status' => 'active']);
        $tuparev = Branch::create(['account_id' => $account->id, 'code' => 'TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $graha = Branch::create(['account_id' => $account->id, 'code' => 'GRH', 'name' => 'Graha', 'is_active' => true]);
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $user->id, 'role' => 'superuser', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta', 'is_active' => true]);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070', 'is_active' => true]);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $tuparev->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-TUP-A3-01', 'display_name' => 'C1070', 'status' => 'active']);

        return compact('account', 'tuparev', 'graha', 'user', 'machine');
    }

    public function test_assignment_write_is_visible_to_daily_with_the_canonical_capability_payload(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Counter Operator', 'linked_user_id' => null, 'is_active' => true]);

        $this->actingAs($f['user'])->postJson("/api/v1/operational-people/{$person->id}/branches/{$f['tuparev']->id}", ['is_active' => true, 'can_record_counter' => true])
            ->assertCreated()
            ->assertJsonPath('data.person_id', (string) $person->id)
            ->assertJsonPath('data.branch_id', $f['tuparev']->id)
            ->assertJsonPath('data.can_record_counter', true);

        $this->actingAs($f['user'])->getJson("/api/v1/branches/{$f['tuparev']->id}/operational-people")
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $person->id)
            ->assertJsonPath('data.0.linked_user_id', null)
            ->assertJsonPath('data.0.operational_person_branches.0.branch_id', $f['tuparev']->id)
            ->assertJsonPath('data.0.operational_person_branches.0.is_active', true)
            ->assertJsonPath('data.0.operational_person_branches.0.can_record_counter', true);

        $this->assertDatabaseHas('operational_person_branches', ['person_id' => $person->id, 'branch_id' => $f['tuparev']->id, 'is_active' => true, 'can_record_counter' => true]);
        $this->assertSame((string) $person->id, (string) app(OperationalPersonEligibilityService::class)->forMachine($f['machine'])->first()?->id);
    }

    public function test_unassigned_inactive_and_other_branch_people_are_hidden(): void
    {
        $f = $this->fixture();
        $unassigned = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Unassigned', 'is_active' => true]);
        $other = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Graha Only', 'is_active' => true]);
        $inactive = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Inactive', 'is_active' => false]);
        OperationalPersonBranch::create(['account_id' => $f['account']->id, 'person_id' => $other->id, 'branch_id' => $f['graha']->id, 'is_active' => true, 'can_record_counter' => true]);
        OperationalPersonBranch::create(['account_id' => $f['account']->id, 'person_id' => $inactive->id, 'branch_id' => $f['tuparev']->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->actingAs($f['user'])->getJson("/api/v1/branches/{$f['tuparev']->id}/operational-people")
            ->assertOk()
            ->assertJsonMissing(['id' => $unassigned->id])
            ->assertJsonMissing(['id' => $other->id])
            ->assertJsonMissing(['id' => $inactive->id]);
    }

    public function test_branch_member_without_counter_capability_is_pic_eligible_but_not_daily_operator_eligible(): void
    {
        $f = $this->fixture();
        $pic = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'PIC Only', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $f['account']->id, 'person_id' => $pic->id, 'branch_id' => $f['tuparev']->id, 'is_active' => true, 'can_record_counter' => false]);

        $this->actingAs($f['user'])->getJson("/api/v1/branches/{$f['tuparev']->id}/operational-people")
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $pic->id)
            ->assertJsonPath('data.0.operational_person_branches.0.can_record_counter', false);

        $this->assertSame((string) $pic->id, (string) app(OperationalPersonEligibilityService::class)->eligibleForBranch($f['tuparev'], $pic->id, false)?->id);
        $this->assertNull(app(OperationalPersonEligibilityService::class)->eligibleForBranch($f['tuparev'], $pic->id, true));
        $this->assertTrue(app(OperationalPersonEligibilityService::class)->forMachine($f['machine'])->isEmpty());
    }

    public function test_settings_and_daily_share_assignment_shape_without_requiring_an_auth_user_for_person(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Operational Identity', 'linked_user_id' => null, 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $f['account']->id, 'person_id' => $person->id, 'branch_id' => $f['tuparev']->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.people.0.id', (string) $person->id)
            ->assertJsonPath('data.people.0.linked_user_id', null)
            ->assertJsonPath('data.people.0.operational_person_branches.0.branch_id', $f['tuparev']->id)
            ->assertJsonPath('data.people.0.operational_person_branches.0.branches.name', 'Tuparev');

        $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/operational-people?per_page=100")
            ->assertOk()
            ->assertJsonPath('data.data.0.id', (string) $person->id)
            ->assertJsonPath('data.data.0.operational_person_branches.0.branch_id', $f['tuparev']->id);
    }
}
