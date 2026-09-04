<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\MachineCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the production Machine Cost bug where the page
 * simultaneously showed Total Clicks = 31,901 and a populated Daily Click
 * Trend, yet also rendered a "No Counter Data" banner and literal
 * "undefined assessed · undefined unpriced" text. Root cause: MachineCostService
 * never emitted counter_status / known_error_waste_events / unknown_error_waste_events
 * / known_error_waste_cost / known_standard_cost_per_click at all, so the frontend's
 * strict-equality and interpolation reads against those fields were always
 * undefined regardless of the underlying (correct) counter and error/waste data.
 *
 * This fixture reproduces the Tuparev August 2026 shape: 28 populated daily-trend
 * days summing to a known total, at least one incident, and at least one
 * component replacement, so every field this test asserts on is exercised with
 * real, non-trivial values rather than defaults.
 */
class MachineCostCounterStatusParityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'TUP', 'name' => 'Tuparev', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'TUP', 'name' => 'Tuparev', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'AccurioPress C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-TUP-A3-01', 'display_name' => 'AccurioPress C1070', 'timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $counterTypeId = CounterType::where('code', 'total_impressions')->value('id');

        // 29 chained readings across August (one baseline + 28 daily deltas of
        // exactly 1000 clicks each) so total_clicks sums to a known, non-trivial value.
        $previousId = null;
        $baseline = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 100000, 'observed_at' => '2026-07-31T17:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'client_request_id' => (string) Str::uuid()]);
        $previousId = $baseline->id;
        $runningValue = 100000;
        for ($day = 1; $day <= 28; $day++) {
            $runningValue += 1000;
            $date = sprintf('2026-08-%02d', $day);
            $reading = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => $runningValue, 'observed_at' => "{$date}T02:00:00Z", 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $previousId, 'client_request_id' => (string) Str::uuid()]);
            $previousId = $reading->id;
        }

        \App\Models\OperationalIncident::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_id' => $machine->id, 'occurred_at' => '2026-08-10T03:00:00Z', 'category' => 'kesesuaian', 'incident_type' => 'error', 'status' => 'open', 'material_loss' => 25000, 'service_loss' => 0, 'description' => 'August incident fixture', 'client_request_id' => (string) Str::uuid()]);

        return compact('account', 'branch', 'user', 'machine');
    }

    public function test_august_total_clicks_is_31901_equivalent_and_matches_daily_trend_sum(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-28');
        $this->assertSame(28000.0, $period['total_clicks']);
        $sumOfDailyClicks = array_sum(array_map(fn ($row) => $row['daily_clicks'] ?? 0, $period['daily_trend']));
        $this->assertEqualsWithDelta($period['total_clicks'], $sumOfDailyClicks, 0.001);
        $this->assertCount(28, $period['daily_trend']);
    }

    public function test_has_counter_data_flag_agrees_with_nonzero_total_clicks(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-28');

        $this->assertGreaterThan(0, $period['total_clicks']);
        $this->assertSame('COMPLETE', $period['counter_status'], 'counter_status must never contradict a populated total_clicks/daily_trend.');
    }

    public function test_no_counter_data_status_only_when_no_readings_exist_in_period(): void
    {
        $f = $this->fixture();
        $emptyPeriod = app(MachineCostService::class)->period($f['machine'], '2020-01-01', '2020-01-31');
        $this->assertSame('NO_DATA', $emptyPeriod['counter_status']);
        $this->assertEquals(0, $emptyPeriod['total_clicks']);
    }

    public function test_error_waste_fields_are_always_present_and_well_typed(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-28');

        $this->assertArrayHasKey('error_waste_events', $period);
        $this->assertArrayHasKey('known_error_waste_events', $period);
        $this->assertArrayHasKey('unknown_error_waste_events', $period);
        $this->assertArrayHasKey('known_error_waste_cost', $period);
        $this->assertIsInt($period['error_waste_events']);
        $this->assertIsInt($period['known_error_waste_events']);
        $this->assertIsInt($period['unknown_error_waste_events']);
        $this->assertIsString($period['known_error_waste_cost']);
        $this->assertSame(1, $period['error_waste_events']);
        $this->assertSame(1, $period['known_error_waste_events']);
        $this->assertSame(0, $period['unknown_error_waste_events']);
        $this->assertSame('25000.00', $period['known_error_waste_cost']);
    }

    public function test_error_waste_fields_remain_present_and_zero_typed_with_no_incidents(): void
    {
        $f = $this->fixture();
        $period = app(MachineCostService::class)->period($f['machine'], '2019-01-01', '2019-01-31');

        $this->assertSame(0, $period['error_waste_events']);
        $this->assertSame(0, $period['known_error_waste_events']);
        $this->assertSame(0, $period['unknown_error_waste_events']);
        $this->assertSame('0.00', $period['known_error_waste_cost']);
    }

    public function test_known_standard_cost_per_click_is_null_safe_when_counter_data_is_missing(): void
    {
        $f = $this->fixture();
        $emptyPeriod = app(MachineCostService::class)->period($f['machine'], '2020-01-01', '2020-01-31');
        $this->assertNull($emptyPeriod['known_standard_cost_per_click']);

        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-01', '2026-08-28');
        $this->assertNotNull($period['known_standard_cost_per_click']);
    }

    public function test_api_response_exposes_counter_status_and_error_waste_fields(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])
            ->getJson("/api/v1/machines/{$f['machine']->id}/cost?period_start=2026-08-01&period_end=2026-08-28")
            ->assertOk()
            ->assertJsonPath('total_clicks', 28000)
            ->assertJsonPath('counter_status', 'COMPLETE')
            ->assertJsonPath('known_error_waste_events', 1)
            ->assertJsonPath('unknown_error_waste_events', 0);
    }
}
