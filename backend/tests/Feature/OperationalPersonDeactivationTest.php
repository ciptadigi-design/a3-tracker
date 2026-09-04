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

class OperationalPersonDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'OPS', 'name' => 'Operations', 'status' => 'active']);
        $tuparev = Branch::create(['account_id' => $account->id, 'code' => 'TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $user->id, 'role' => 'superuser', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta', 'is_active' => true]);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070', 'is_active' => true]);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $tuparev->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-TUP-A3-01', 'display_name' => 'C1070', 'status' => 'active']);

        return compact('account', 'tuparev', 'user', 'machine');
    }

    public function test_saving_active_false_persists_false(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Marsya', 'is_active' => true]);

        $this->actingAs($f['user'])
            ->putJson("/api/v1/accounts/{$f['account']->id}/operational-people/{$person->id}", [
                'name' => 'Marsya',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('operational_people', ['id' => $person->id, 'is_active' => false]);
        $this->assertNotNull($person->fresh()->archived_at);
    }

    public function test_false_boolean_is_not_dropped_by_payload_handling(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Repeat Target', 'is_active' => true]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($f['user'])
                ->putJson("/api/v1/accounts/{$f['account']->id}/operational-people/{$person->id}", [
                    'name' => 'Repeat Target',
                    'is_active' => false,
                ])
                ->assertOk()
                ->assertJsonPath('data.is_active', false);

            $this->assertDatabaseHas('operational_people', ['id' => $person->id, 'is_active' => false]);

            // reactivate between attempts to prove the false write is never a no-op false-positive
            $person->update(['is_active' => true, 'archived_at' => null]);
        }
    }

    public function test_inactive_person_excluded_from_daily_counter_eligibility(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Operator', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $f['account']->id, 'person_id' => $person->id, 'branch_id' => $f['tuparev']->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->assertNotNull(app(OperationalPersonEligibilityService::class)->eligible($f['machine'], $person->id));

        $this->actingAs($f['user'])->putJson("/api/v1/accounts/{$f['account']->id}/operational-people/{$person->id}", [
            'name' => 'Operator',
            'is_active' => false,
        ])->assertOk();

        $this->assertNull(app(OperationalPersonEligibilityService::class)->eligible($f['machine'], $person->id));
    }

    public function test_removing_branch_assignment_does_not_imply_person_deactivation(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Branchless', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $f['account']->id, 'person_id' => $person->id, 'branch_id' => $f['tuparev']->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->actingAs($f['user'])->putJson("/api/v1/operational-people/{$person->id}/branches", [
            'assignments' => [],
        ])->assertOk();

        $this->assertTrue($person->fresh()->is_active);
        $this->assertDatabaseHas('operational_people', ['id' => $person->id, 'is_active' => true]);
    }

    public function test_person_deactivation_preserves_historical_assignment_rows(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Historical', 'is_active' => true]);
        $assignment = OperationalPersonBranch::create(['account_id' => $f['account']->id, 'person_id' => $person->id, 'branch_id' => $f['tuparev']->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->actingAs($f['user'])->putJson("/api/v1/accounts/{$f['account']->id}/operational-people/{$person->id}", [
            'name' => 'Historical',
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('operational_people', ['id' => $person->id, 'is_active' => false]);
        $this->assertDatabaseHas('operational_person_branches', ['id' => $assignment->id, 'person_id' => $person->id, 'branch_id' => $f['tuparev']->id]);
    }

    public function test_reactivation_works(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Comeback', 'is_active' => false, 'archived_at' => now()]);

        $this->actingAs($f['user'])->putJson("/api/v1/accounts/{$f['account']->id}/operational-people/{$person->id}", [
            'name' => 'Comeback',
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.archived_at', null);

        $this->assertDatabaseHas('operational_people', ['id' => $person->id, 'is_active' => true, 'archived_at' => null]);
    }

    public function test_list_projection_reflects_active_false_immediately_after_save(): void
    {
        $f = $this->fixture();
        $person = OperationalPerson::create(['account_id' => $f['account']->id, 'name' => 'Projected', 'is_active' => true]);

        $this->actingAs($f['user'])->putJson("/api/v1/accounts/{$f['account']->id}/operational-people/{$person->id}", [
            'name' => 'Projected',
            'is_active' => false,
        ])->assertOk();

        $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/operational-people?per_page=100")
            ->assertOk()
            ->assertJsonPath('data.data.0.id', (string) $person->id)
            ->assertJsonPath('data.data.0.is_active', false);
    }
}
