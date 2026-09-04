<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\ComponentReplacement;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\OperationalIncident;
use App\Models\User;
use App\Services\MachineCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineCostDailyTrendTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds a Production-equivalent August 2026 counter sequence: a run of
     * chained effective readings including one day with two readings, one
     * gap day with no activity at all, and one deliberate same-value (zero
     * delta) reading, mirroring the real Production shape closely enough to
     * prove the same code path against realistic evidence.
     */
    private function fixture(): array
    {
        $account = Account::create(['code' => 'MCT', 'name' => 'Machine Cost Trend', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-MAIN-A3-01', 'display_name' => 'C1070', 'timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $counterTypeId = CounterType::where('code', 'total_impressions')->value('id');

        // Chained readings: 07-31 (boundary, pre-period baseline), 08-01,
        // 08-02 (same value as previous -> zero delta), 08-03 twice same day,
        // then a gap (no reading on 08-04), then 08-05.
        $r0 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 1000, 'observed_at' => '2026-07-31T16:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'client_request_id' => 'b0000000-0000-4000-8000-000000000001']);
        $r1 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 1500, 'observed_at' => '2026-08-01T01:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r0->id, 'client_request_id' => 'b0000000-0000-4000-8000-000000000002']);
        $r2 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 1500, 'observed_at' => '2026-08-02T01:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r1->id, 'client_request_id' => 'b0000000-0000-4000-8000-000000000003']);
        $r3 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 1700, 'observed_at' => '2026-08-03T01:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r2->id, 'client_request_id' => 'b0000000-0000-4000-8000-000000000004']);
        $r4 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 1850, 'observed_at' => '2026-08-03T10:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r3->id, 'client_request_id' => 'b0000000-0000-4000-8000-000000000005']);
        // 08-04: deliberate gap, no reading at all.
        $r5 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 2200, 'observed_at' => '2026-08-05T01:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r4->id, 'client_request_id' => 'b0000000-0000-4000-8000-000000000006']);

        $catalog = ComponentCatalog::create(['code' => 'DRUM', 'name' => 'Drum unit', 'is_active' => true]);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'Default']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $catalog->id, 'slot_code' => 'DRUM-1', 'is_active' => true]);
        $machineComponent = MachineComponent::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'component_id' => $catalog->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'DRUM-1', 'source_type' => 'inherited', 'status' => 'configured']);
        $knownLifecycle = ComponentLifecycle::create(['machine_component_id' => $machineComponent->id, 'status' => 'closed']);
        $unknownLifecycle = ComponentLifecycle::create(['machine_component_id' => $machineComponent->id, 'status' => 'closed']);
        // Known-cost replacement on 08-02 (Jakarta) — same day as the zero-delta reading.
        ComponentReplacement::create(['account_id' => $account->id, 'machine_component_id' => $machineComponent->id, 'new_lifecycle_id' => $knownLifecycle->id, 'replaced_at' => '2026-08-02T02:00:00Z', 'inventory_source' => 'tracked', 'consumed_cost' => 150000, 'client_request_id' => \Illuminate\Support\Str::uuid()]);
        // Unknown-cost (external/untracked) replacement on 08-05.
        ComponentReplacement::create(['account_id' => $account->id, 'machine_component_id' => $machineComponent->id, 'new_lifecycle_id' => $unknownLifecycle->id, 'replaced_at' => '2026-08-05T02:00:00Z', 'inventory_source' => 'external', 'consumed_cost' => null, 'client_request_id' => \Illuminate\Support\Str::uuid()]);

        OperationalIncident::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_id' => $machine->id, 'occurred_at' => '2026-08-03T03:00:00Z', 'category' => 'kesesuaian', 'incident_type' => 'error', 'status' => 'open', 'material_loss' => 50000, 'service_loss' => 0, 'description' => 'Test incident for daily trend fixture', 'client_request_id' => \Illuminate\Support\Str::uuid()]);

        return compact('account', 'branch', 'user', 'machine');
    }

    public function test_august_sequence_produces_the_expected_total_period_clicks(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-05');
        // 08-01: 1500-1000=500, 08-02: 1500-1500=0, 08-03: (1700-1500)+(1850-1700)=350, 08-05: 2200-1850=350
        $this->assertSame(1200.0, $period['total_clicks']);
        $this->assertSame(1200.0, $period['period_clicks']);
    }

    public function test_daily_trend_is_non_empty_chronologically_ordered_and_sums_to_the_aggregate(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-05');
        $trend = $period['daily_trend'];

        $this->assertNotEmpty($trend);
        $dates = array_column($trend, 'operational_date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);

        $sumOfDailyClicks = array_sum(array_map(fn ($row) => $row['daily_clicks'] ?? 0, $trend));
        $this->assertEqualsWithDelta($period['total_clicks'], $sumOfDailyClicks, 0.001, 'AGGREGATE_DAILY_PARITY');

        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-05'], $dates);
    }

    public function test_multiple_counter_readings_on_the_same_calendar_day_are_aggregated_correctly(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-05');
        $byDate = collect($period['daily_trend'])->keyBy('operational_date');

        $this->assertSame(2, $byDate['2026-08-03']['counter_readings']);
        $this->assertSame(350.0, $byDate['2026-08-03']['daily_clicks']);
    }

    public function test_no_counter_activity_day_is_omitted_rather_than_fabricated(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-05');
        $dates = array_column($period['daily_trend'], 'operational_date');

        $this->assertNotContains('2026-08-04', $dates);
    }

    public function test_counter_reset_or_zero_delta_reading_never_goes_negative_and_matches_canonical_semantics(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-05');
        $byDate = collect($period['daily_trend'])->keyBy('operational_date');

        $this->assertSame(0.0, $byDate['2026-08-02']['daily_clicks']);
    }

    public function test_asia_jakarta_day_boundaries_are_respected(): void
    {
        $f = $this->fixture();
        // The 07-31T16:00Z baseline reading is 2026-07-31T23:00 Jakarta (still July), excluded from an August-only window.
        $julyPeriod = app(MachineCostService::class)->period($f['machine'], '2026-07-01', '2026-07-31');
        $this->assertEquals(0, $julyPeriod['total_clicks']);
        $this->assertEmpty(collect($julyPeriod['daily_trend'])->pluck('daily_clicks')->filter()->all());
    }

    public function test_first_reading_predecessor_outside_the_period_still_contributes_its_delta(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-01');
        // Predecessor (07-31 baseline, value 1000) is outside the window, but the
        // 08-01 row's delta must still be computed against it, not treated as a
        // fresh baseline with zero usage.
        $this->assertSame(500.0, $period['total_clicks']);
        $this->assertSame(500.0, $period['daily_trend'][0]['daily_clicks']);
    }

    public function test_cost_and_incident_events_are_attributed_to_their_operational_day(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-05');
        $byDate = collect($period['daily_trend'])->keyBy('operational_date');

        $this->assertSame(1, $byDate['2026-08-02']['component_events']);
        $this->assertSame(0, $byDate['2026-08-02']['unknown_cost_events']);
        $this->assertSame('150000.00', $byDate['2026-08-02']['known_daily_cost']);
        $this->assertSame('COMPLETE', $byDate['2026-08-02']['cost_evidence_status']);

        $this->assertSame(1, $byDate['2026-08-03']['error_waste_events']);
        $this->assertNotNull($byDate['2026-08-03']['known_daily_cost']);

        $this->assertSame(1, $byDate['2026-08-05']['unknown_cost_events']);
        $this->assertSame('PARTIAL', $byDate['2026-08-05']['cost_evidence_status']);
    }

    public function test_empty_period_returns_an_empty_trend_not_an_exception(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-01-01', '2026-01-31');
        $this->assertSame([], $period['daily_trend']);
        $this->assertEquals(0, $period['total_clicks']);
    }

    public function test_custom_period_transport_remains_strict_y_m_d(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])
            ->getJson("/api/v1/machines/{$f['machine']->id}/cost?period_start=2026-08-01T00:00:00Z&period_end=2026-08-05")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_start']);

        $this->actingAs($f['user'])
            ->getJson("/api/v1/machines/{$f['machine']->id}/cost?period_start=2026-08-01&period_end=2026-08-05")
            ->assertOk()
            ->assertJsonPath('total_clicks', 1200)
            ->assertJsonPath('daily_trend.0.operational_date', '2026-08-01');
    }
}
