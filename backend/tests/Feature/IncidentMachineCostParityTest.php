<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\User;
use App\Services\MachineCostService;
use App\Services\OperationalIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IncidentMachineCostParityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'INC', 'name' => 'Incidents', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'A', 'name' => 'Branch A', 'timezone' => 'Asia/Jakarta']);
        $other = $account->branches()->create(['code' => 'B', 'name' => 'Branch B', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        foreach ([$branch, $other] as $b) {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $b->id, 'is_active' => true]);
        }
        $manufacturer = Manufacturer::create(['code' => 'M', 'name' => 'Maker']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'X', 'name' => 'Model X']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A1', 'display_name' => 'A1']);
        $person = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Operator', 'is_active' => true]);
        $pic = OperationalPerson::create(['account_id' => $account->id, 'name' => 'PIC', 'is_active' => true]);
        foreach ([$person, $pic] as $p) {
            OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $p->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return compact('account', 'branch', 'other', 'user', 'machine', 'person', 'pic');
    }

    private function createIncident(array $f, array $overrides = [])
    {
        return app(OperationalIncidentService::class)->create($f['user'], $f['account'], $f['branch'], array_merge([
            'client_request_id' => (string) Str::uuid(), 'occurred_at' => '2026-08-31T10:00:00+07:00', 'category' => 'kualitas', 'incident_type' => 'human', 'description' => 'Damaged output', 'machine_id' => $f['machine']->id, 'operator_person_id' => $f['person']->id, 'responsible_person_id' => $f['pic']->id, 'material_loss' => 10000, 'service_loss' => 0, 'penalty_multiplier' => 3,
        ], $overrides));
    }

    public function test_operator_pic_snapshots_and_idempotency(): void
    {
        $f = $this->fixture();
        $request = (string) Str::uuid();
        $one = $this->createIncident($f, ['client_request_id' => $request]);
        $two = $this->createIncident($f, ['client_request_id' => $request]);
        $this->assertSame((string) $one->id, (string) $two->id);
        $this->assertSame($f['person']->id, $one->operator_person_id);
        $this->assertSame($f['pic']->id, $one->responsible_person_id);
        $this->assertSame('Operator', $one->operator_name_snapshot);
        $this->assertSame('PIC', $one->responsible_name_snapshot);
        $f['person']->update(['name' => 'Renamed']);
        $f['pic']->update(['name' => 'Archived']);
        $this->assertSame('Operator', $one->fresh()->operator_name_snapshot);
        $this->assertSame('PIC', $one->fresh()->responsible_name_snapshot);
    }

    public function test_branch_eligibility_fails_closed(): void
    {
        $f = $this->fixture();
        $this->expectException(ValidationException::class);
        app(OperationalIncidentService::class)->create($f['user'], $f['account'], $f['other'], ['client_request_id' => (string) Str::uuid(), 'occurred_at' => now(), 'category' => 'kualitas', 'incident_type' => 'human', 'description' => 'Invalid', 'operator_person_id' => $f['person']->id]);
    }

    public function test_machine_cost_incident_and_stored_assessed_loss_are_exactly_once(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['base_amount' => 10000, 'assessed_loss' => 30000]);
        $this->assertSame('30000.00', app(OperationalIncidentService::class)->effectiveLoss($incident));
        $summary = app(MachineCostService::class)->period($f['machine'], '2026-08-31', '2026-08-31');
        $this->assertSame('30000.00', $summary['error_waste_cost']);
        $this->assertNull($summary['machine_cost_per_click']);
    }
}
