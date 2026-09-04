<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComponentProjectionParityTest extends TestCase
{
    use RefreshDatabase;

    private function c1070Fixture(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'Konica C1070']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'C1070 canonical']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-TUP-A3-01', 'display_name' => 'Konica C1070', 'status' => 'active']);

        for ($index = 0; $index < 28; $index++) {
            $component = ComponentCatalog::create(['code' => sprintf('C1070-%02d', $index + 1), 'name' => sprintf('C1070 Component %02d', $index + 1)]);
            $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $component->id, 'slot_code' => sprintf('SLOT-%02d', $index + 1), 'display_order' => $index, 'baseline_expected_clicks' => 100000]);
            $assignment = MachineComponent::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'component_id' => $component->id, 'profile_slot_id' => $slot->id, 'slot_code' => $slot->slot_code, 'source_type' => 'inherited', 'status' => 'configured', 'display_order' => $index, 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 100000]);
            $historyCount = $index < 19 ? 2 : 1;
            $lastRemoved = null;
            for ($history = 0; $history < $historyCount; $history++) {
                $installed = 1000000 + ($index * 1000) + ($history * 100);
                $lastRemoved = $installed + 50000;
                ComponentLifecycle::create(['machine_component_id' => $assignment->id, 'installed_counter' => $installed, 'removed_counter' => $lastRemoved, 'actual_usage' => 50000, 'status' => 'closed', 'source' => 'legacy_import']);
            }

            // Rows 0-10 (11 of the 28) mirror the real Tuparev BASELINE_KNOWN rows: a status='unknown'
            // lifecycle carrying a real installed_counter derived from this machine's own prior closed
            // chain, but no factual installation date (started_at stays null — never fabricated). Rows
            // 11-27 (17 of the 28) intentionally get no current lifecycle row: zero evidence.
            if ($index < 11) {
                ComponentLifecycle::create(['machine_component_id' => $assignment->id, 'installed_counter' => $lastRemoved, 'removed_counter' => null, 'started_at' => null, 'status' => 'unknown', 'source' => 'reconciliation_baseline']);
            }
        }

        CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => CounterType::where('code', 'total_impressions')->value('id'), 'reading_value' => 1441597, 'observed_at' => '2026-09-01T06:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'client_request_id' => 'f0000000-0000-4000-8000-000000000001']);

        return compact('account', 'branch', 'user', 'machine');
    }

    public function test_28_configured_slots_and_47_closed_lifecycles_are_returned_without_a_fake_active_lifecycle(): void
    {
        $fixture = $this->c1070Fixture();
        $response = $this->actingAs($fixture['user'])->getJson("/api/v1/machines/{$fixture['machine']->id}/components")->assertOk();
        $rows = collect($response->json('data'));

        $this->assertCount(28, $rows);
        $this->assertSame(58, $rows->sum(fn ($row) => count($row['lifecycles'])));
        $this->assertSame(47, $rows->flatMap(fn ($row) => $row['lifecycles'])->where('status', 'closed')->count());
        $this->assertSame(11, $rows->flatMap(fn ($row) => $row['lifecycles'])->where('status', 'unknown')->count());
        $this->assertSame(0, $rows->flatMap(fn ($row) => $row['lifecycles'])->where('status', 'active')->count());

        // ACTIVE_INITIALIZED = 0, BASELINE_KNOWN = 11, UNINITIALIZED_UNKNOWN = 17.
        $this->assertSame(0, $rows->where('configuration_state', 'INITIALIZED')->count());
        $this->assertSame(11, $rows->where('configuration_state', 'BASELINE_KNOWN')->count());
        $this->assertSame(17, $rows->where('configuration_state', 'UNKNOWN')->count());
        $this->assertTrue($rows->every(fn ($row) => (float) $row['latest_effective_counter'] === 1441597.0));

        // The 11 BASELINE_KNOWN rows surface their real installed_counter and never a fabricated
        // start date or health estimate.
        $baselineRows = $rows->where('configuration_state', 'BASELINE_KNOWN');
        $this->assertCount(11, $baselineRows);
        $this->assertTrue($baselineRows->every(fn ($row) => $row['baseline_installed_counter'] !== null));
        $this->assertTrue($baselineRows->every(function ($row) {
            $baselineLifecycle = collect($row['lifecycles'])->firstWhere('id', $row['baseline_lifecycle_id']);

            return $baselineLifecycle !== null && $baselineLifecycle['started_at'] === null;
        }));

        // The 17 UNINITIALIZED_UNKNOWN rows have zero current lifecycle evidence and no baseline counter.
        $unknownRows = $rows->where('configuration_state', 'UNKNOWN');
        $this->assertCount(17, $unknownRows);
        $this->assertTrue($unknownRows->every(fn ($row) => $row['baseline_installed_counter'] === null));
    }

    public function test_a_status_active_lifecycle_with_a_real_started_at_is_still_classified_initialized(): void
    {
        $fixture = $this->c1070Fixture();
        $assignment = MachineComponent::where('machine_id', $fixture['machine']->id)->orderBy('display_order')->first();
        ComponentLifecycle::create(['machine_component_id' => $assignment->id, 'installed_counter' => 1400000, 'removed_counter' => null, 'started_at' => '2026-08-01T00:00:00Z', 'status' => 'active', 'source' => 'manual']);

        $response = $this->actingAs($fixture['user'])->getJson("/api/v1/machines/{$fixture['machine']->id}/components")->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $assignment->id);

        $this->assertSame('INITIALIZED', $row['configuration_state']);
    }

    public function test_machine_component_projection_enforces_account_and_branch_isolation(): void
    {
        $fixture = $this->c1070Fixture();
        $otherAccount = Account::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $otherBranch = Branch::create(['account_id' => $otherAccount->id, 'code' => 'OTHER', 'name' => 'Other', 'is_active' => true]);
        $otherMachine = Machine::create(['account_id' => $otherAccount->id, 'branch_id' => $otherBranch->id, 'machine_model_id' => $fixture['machine']->machine_model_id, 'machine_code' => 'OTHER-01', 'display_name' => 'Other', 'status' => 'active']);

        $this->actingAs($fixture['user'])->getJson("/api/v1/machines/{$otherMachine->id}/components")->assertForbidden();
    }
}
