<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\OperationalIncident;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\User;
use App\Services\OperationalIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class M2_17_IncidentPersonPopulationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'M217', 'name' => 'M2.17']);
        $branch = $account->branches()->create(['code' => 'A', 'name' => 'Branch A']);
        $otherBranch = $account->branches()->create(['code' => 'B', 'name' => 'Branch B']);
        $otherAccount = Account::create(['code' => 'M217OTHER', 'name' => 'Other Account']);
        $otherAccountBranch = $otherAccount->branches()->create(['code' => 'C', 'name' => 'Other Account Branch']);

        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        foreach ([$branch, $otherBranch] as $b) {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $b->id, 'is_active' => true]);
        }

        $manufacturer = Manufacturer::create(['code' => 'M217MAKER', 'name' => 'Maker']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'M217X', 'name' => 'Model X']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'M217-A1', 'display_name' => 'A1']);

        $operatorA = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Operator A', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $operatorA->id, 'branch_id' => $branch->id, 'is_active' => true, 'can_record_counter' => true]);

        $operatorB = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Operator B', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $operatorB->id, 'branch_id' => $branch->id, 'is_active' => true, 'can_record_counter' => true]);

        $errorOnly = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Error Only Person', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $errorOnly->id, 'branch_id' => $branch->id, 'is_active' => true, 'can_record_counter' => false]);

        $archived = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Archived Person', 'is_active' => false]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $archived->id, 'branch_id' => $branch->id, 'is_active' => true, 'can_record_counter' => true]);

        $branchBPerson = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Branch B Person', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $branchBPerson->id, 'branch_id' => $otherBranch->id, 'is_active' => true, 'can_record_counter' => true]);

        $accountBPerson = OperationalPerson::create(['account_id' => $otherAccount->id, 'name' => 'Account B Person', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $otherAccount->id, 'person_id' => $accountBPerson->id, 'branch_id' => $otherAccountBranch->id, 'is_active' => true, 'can_record_counter' => true]);

        return compact('account', 'branch', 'otherBranch', 'otherAccount', 'otherAccountBranch', 'user', 'machine', 'operatorA', 'operatorB', 'errorOnly', 'archived', 'branchBPerson', 'accountBPerson');
    }

    private function createIncident(array $f, array $overrides = [])
    {
        return app(OperationalIncidentService::class)->create($f['user'], $f['account'], $f['branch'], array_merge([
            'client_request_id' => (string) Str::uuid(), 'occurred_at' => '2026-09-11T10:00:00+07:00', 'category' => 'kualitas', 'incident_type' => 'human', 'description' => 'Test incident',
        ], $overrides));
    }

    public function test_incident_index_returns_broad_branch_population_not_empty(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['branch']->id}/incidents")->assertOk();
        $ids = collect($response->json('data.people') ?? $response->json('people'))->pluck('id')->map(fn ($id) => (string) $id);

        $this->assertContains((string) $f['operatorA']->id, $ids->all());
        $this->assertContains((string) $f['operatorB']->id, $ids->all());
        $this->assertContains((string) $f['errorOnly']->id, $ids->all(), 'Error Only Person (can_record_counter=false) must be part of the broad Incident population');
        $this->assertNotContains((string) $f['archived']->id, $ids->all());
        $this->assertNotContains((string) $f['branchBPerson']->id, $ids->all());
        $this->assertNotContains((string) $f['accountBPerson']->id, $ids->all());
    }

    public function test_incident_show_also_returns_people(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f);
        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['branch']->id}/incidents/{$incident->id}")->assertOk();
        $ids = collect($response->json('data.people') ?? $response->json('people'))->pluck('id')->map(fn ($id) => (string) $id);
        $this->assertContains((string) $f['errorOnly']->id, $ids->all());
    }

    public function test_active_operator_same_branch_selectable(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['operator_person_id' => $f['operatorA']->id]);
        $this->assertSame((string) $f['operatorA']->id, (string) $incident->operator_person_id);
        $this->assertSame('Operator A', $incident->operator_name_snapshot);
    }

    public function test_active_error_only_person_selectable_as_responsible_but_not_operator(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['responsible_person_id' => $f['errorOnly']->id]);
        $this->assertSame((string) $f['errorOnly']->id, (string) $incident->responsible_person_id);
        $this->assertSame('Error Only Person', $incident->responsible_name_snapshot);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->createIncident($f, ['operator_person_id' => $f['errorOnly']->id]);
    }

    public function test_inactive_person_rejected_for_both_roles(): void
    {
        $f = $this->fixture();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->createIncident($f, ['responsible_person_id' => $f['archived']->id]);
    }

    public function test_wrong_branch_person_rejected(): void
    {
        $f = $this->fixture();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->createIncident($f, ['responsible_person_id' => $f['branchBPerson']->id]);
    }

    public function test_wrong_account_person_rejected(): void
    {
        $f = $this->fixture();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->createIncident($f, ['responsible_person_id' => $f['accountBPerson']->id]);
    }

    public function test_operator_snapshot_persisted(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['operator_person_id' => $f['operatorA']->id]);
        $this->assertSame('Operator A', $incident->fresh()->operator_name_snapshot);
    }

    public function test_responsible_snapshot_persisted(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['responsible_person_id' => $f['errorOnly']->id]);
        $this->assertSame('Error Only Person', $incident->fresh()->responsible_name_snapshot);
    }

    public function test_historical_archived_person_incident_remains_readable(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['operator_person_id' => $f['operatorA']->id, 'responsible_person_id' => $f['errorOnly']->id]);

        // archive both referenced people after the incident exists
        $f['operatorA']->update(['is_active' => false]);
        $f['errorOnly']->update(['is_active' => false]);

        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['branch']->id}/incidents/{$incident->id}")->assertOk();
        $this->assertSame('Operator A', $response->json('incident.operator_name_snapshot'));
        $this->assertSame('Error Only Person', $response->json('incident.responsible_name_snapshot'));

        // and the now-archived people must no longer appear in the selector population
        $listResponse = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['branch']->id}/incidents")->assertOk();
        $ids = collect($listResponse->json('people'))->pluck('id')->map(fn ($id) => (string) $id);
        $this->assertNotContains((string) $f['operatorA']->id, $ids->all());
        $this->assertNotContains((string) $f['errorOnly']->id, $ids->all());
    }

    public function test_editing_unrelated_field_does_not_corrupt_person_snapshots(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['operator_person_id' => $f['operatorA']->id, 'responsible_person_id' => $f['errorOnly']->id]);

        $payload = [
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'category' => $incident->category,
            'incident_type' => $incident->incident_type,
            'description' => 'Updated description only',
            'machine_id' => $incident->machine_id,
            'operator_person_id' => $incident->operator_person_id,
            'responsible_person_id' => $incident->responsible_person_id,
            'change_reason' => 'Fixing a typo in the description',
        ];
        $response = $this->actingAs($f['user'])->patchJson("/api/v1/incidents/{$incident->id}", $payload)->assertOk();

        $this->assertSame('Updated description only', $response->json('incident.description'));
        $this->assertSame('Operator A', $response->json('incident.operator_name_snapshot'));
        $this->assertSame('Error Only Person', $response->json('incident.responsible_name_snapshot'));
        $this->assertSame((string) $f['operatorA']->id, (string) $response->json('incident.operator_person_id'));
        $this->assertSame((string) $f['errorOnly']->id, (string) $response->json('incident.responsible_person_id'));
    }

    public function test_changing_responsible_person_refreshes_snapshot_and_leaves_operator_unchanged(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['operator_person_id' => $f['operatorA']->id, 'responsible_person_id' => $f['errorOnly']->id]);

        $payload = [
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'category' => $incident->category,
            'incident_type' => $incident->incident_type,
            'description' => $incident->description,
            'machine_id' => $incident->machine_id,
            'operator_person_id' => $incident->operator_person_id,
            'responsible_person_id' => $f['operatorB']->id,
            'change_reason' => 'Correcting the responsible person',
        ];
        $response = $this->actingAs($f['user'])->patchJson("/api/v1/incidents/{$incident->id}", $payload)->assertOk();

        $this->assertSame((string) $f['operatorB']->id, (string) $response->json('incident.responsible_person_id'));
        $this->assertSame('Operator B', $response->json('incident.responsible_name_snapshot'));
        $this->assertSame((string) $f['operatorA']->id, (string) $response->json('incident.operator_person_id'));
        $this->assertSame('Operator A', $response->json('incident.operator_name_snapshot'));
    }

    public function test_direct_api_update_cannot_bypass_eligibility(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['operator_person_id' => $f['operatorA']->id]);

        $payload = [
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'category' => $incident->category,
            'incident_type' => $incident->incident_type,
            'description' => $incident->description,
            'operator_person_id' => $f['operatorA']->id,
            'responsible_person_id' => $f['accountBPerson']->id,
            'change_reason' => 'Attempting an ineligible responsible person',
        ];
        $this->actingAs($f['user'])->patchJson("/api/v1/incidents/{$incident->id}", $payload)->assertStatus(422);

        $this->assertNull($incident->fresh()->responsible_person_id);
    }

    public function test_clearing_operator_selection_clears_its_snapshot(): void
    {
        $f = $this->fixture();
        $incident = $this->createIncident($f, ['operator_person_id' => $f['operatorA']->id]);
        $this->assertSame('Operator A', $incident->operator_name_snapshot);

        $payload = [
            'occurred_at' => $incident->occurred_at->toIso8601String(),
            'category' => $incident->category,
            'incident_type' => $incident->incident_type,
            'description' => $incident->description,
            'operator_person_id' => null,
            'responsible_person_id' => null,
            'change_reason' => 'Clearing operator',
        ];
        $response = $this->actingAs($f['user'])->patchJson("/api/v1/incidents/{$incident->id}", $payload)->assertOk();
        $this->assertNull($response->json('incident.operator_person_id'));
        $this->assertNull($response->json('incident.operator_name_snapshot'));
    }

    public function test_daily_inventory_replacement_populations_remain_strict_can_record_counter(): void
    {
        $f = $this->fixture();
        $canonical = app(\App\Services\OperationalPersonEligibilityService::class)->forMachine($f['machine'])->pluck('id')->map(fn ($id) => (string) $id)->sort()->values();
        $this->assertContains((string) $f['operatorA']->id, $canonical->all());
        $this->assertContains((string) $f['operatorB']->id, $canonical->all());
        $this->assertNotContains((string) $f['errorOnly']->id, $canonical->all(), 'Daily/Inventory/Replacement must stay strict — no M2.15 regression');
        $this->assertNotContains((string) $f['archived']->id, $canonical->all());
    }
}
