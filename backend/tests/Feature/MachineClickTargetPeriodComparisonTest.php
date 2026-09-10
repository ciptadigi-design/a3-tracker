<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\MachineClickTarget;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\MachineClickTargetProjectionService;
use App\Services\PeriodComparisonService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.14 - period-over-period comparison. Locked principle: current period
 * shifted exactly one calendar month backward, using EffectiveCounterSequence
 * as the only source of actual clicks for both sides.
 */
class MachineClickTargetPeriodComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $timezone = 'Asia/Jakarta'): array
    {
        $account = Account::create(['code' => 'CTG', 'name' => 'Click Target', 'default_timezone' => $timezone]);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => $timezone]);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-MAIN-A3-01', 'display_name' => 'C1070', 'timezone' => $timezone, 'status' => 'active']);

        return compact('account', 'branch', 'user', 'machine');
    }

    private function setTarget(array $f, int $year, int $month, int $target): MachineClickTarget
    {
        return MachineClickTarget::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id, 'target_year' => $year, 'target_month' => $month, 'monthly_click_target' => $target, 'created_by' => $f['user']->id, 'updated_by' => $f['user']->id]);
    }

    private function reading(array $f, float $value, string $observedAt, ?string $previousId = null): CounterReading
    {
        $counterTypeId = CounterType::where('code', 'total_impressions')->value('id');

        return CounterReading::create(['account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $counterTypeId, 'reading_value' => $value, 'observed_at' => $observedAt, 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $previousId, 'client_request_id' => (string) Str::uuid()]);
    }

    private function comparison(): PeriodComparisonService
    {
        return app(PeriodComparisonService::class);
    }

    private function projection(): MachineClickTargetProjectionService
    {
        return app(MachineClickTargetProjectionService::class);
    }

    // ---- shiftDateExact ---------------------------------------------------

    public function test_shift_date_exact_handles_ordinary_dates(): void
    {
        $this->assertSame('2026-08-10', $this->comparison()->shiftDateExact('2026-09-10', -1));
    }

    public function test_shift_date_exact_is_null_when_the_shifted_day_does_not_exist(): void
    {
        $this->assertNull($this->comparison()->shiftDateExact('2026-10-31', -1)); // September has no 31st
    }

    public function test_shift_date_exact_handles_leap_year_february(): void
    {
        // March 31 2027 (non-leap) shifted back has no Feb 31.
        $this->assertNull($this->comparison()->shiftDateExact('2027-03-31', -1));
        // Feb 29 2028 (leap) shifted forward a year has no Feb 29 2029.
        $this->assertNull($this->comparison()->shiftDateExact('2028-02-29', 12));
    }

    // ---- daily --------------------------------------------------------

    public function test_daily_normal_growth(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-09T17:00:00Z');
        $this->reading($f, 2120, '2026-08-10T01:00:00Z', $r0->id); // 2026-08-10 Jakarta = 2120
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 2450);

        $this->assertTrue($result['available']);
        $this->assertSame('OK', $result['comparison_status']);
        $this->assertSame('UP', $result['direction']);
        $this->assertSame(330.0, $result['delta_clicks']);
        $this->assertEqualsWithDelta(15.6, $result['delta_percentage'], 0.05);
    }

    public function test_daily_decline(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-09T17:00:00Z');
        $this->reading($f, 1500, '2026-08-10T01:00:00Z', $r0->id);
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 1000);

        $this->assertSame(-500.0, $result['delta_clicks']);
        $this->assertEqualsWithDelta(-33.3, $result['delta_percentage'], 0.05);
        $this->assertSame('DOWN', $result['direction']);
    }

    public function test_daily_current_zero_previous_positive_is_minus_100_percent(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-09T17:00:00Z');
        $this->reading($f, 1000, '2026-08-10T01:00:00Z', $r0->id);
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 0);

        $this->assertSame(-100.0, $result['delta_percentage']);
        $this->assertSame('DOWN', $result['direction']);
    }

    public function test_daily_both_zero_is_flat_not_infinity(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-09T17:00:00Z');
        $this->reading($f, 0, '2026-08-10T01:00:00Z', $r0->id); // evidence of exactly zero usage
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 0);

        $this->assertTrue($result['available']);
        $this->assertSame(0.0, $result['delta_percentage']);
        $this->assertSame('FLAT', $result['direction']);
        $this->assertSame('OK', $result['comparison_status']);
    }

    public function test_daily_base_zero_growth_is_not_infinity(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-09T17:00:00Z');
        $this->reading($f, 0, '2026-08-10T01:00:00Z', $r0->id);
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 500);

        $this->assertTrue($result['available']);
        $this->assertNull($result['delta_percentage']);
        $this->assertSame('UP', $result['direction']);
        $this->assertSame('BASE_ZERO', $result['comparison_status']);
        $this->assertSame(500.0, $result['delta_clicks']);
    }

    public function test_daily_missing_previous_evidence_is_unavailable_not_zero(): void
    {
        $f = $this->fixture();
        // No readings at all in August.
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 500);

        $this->assertFalse($result['available']);
        $this->assertSame('UNAVAILABLE', $result['comparison_status']);
        $this->assertSame('UNAVAILABLE', $result['direction']);
        $this->assertNull($result['delta_clicks']);
        $this->assertNull($result['delta_percentage']);
        $this->assertNull($result['previous_period']['clicks']);
    }

    public function test_daily_invalid_shifted_date_is_unavailable(): void
    {
        $f = $this->fixture();
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-10-31', 100);

        $this->assertFalse($result['available']);
        $this->assertNull($result['previous_period']);
    }

    // ---- range (week) ---------------------------------------------------

    public function test_week_range_sums_and_compares_correctly(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-06T17:00:00Z');
        // 7 Aug - 13 Aug Jakarta totalling 6302.
        $this->reading($f, 6302, '2026-08-13T10:00:00Z', $r0->id);
        $result = $this->comparison()->range($f['machine'], 'Asia/Jakarta', '2026-09-07', '2026-09-13', 5785.0);

        $this->assertTrue($result['available']);
        $this->assertSame('2026-08-07', $result['previous_period']['start_date']);
        $this->assertSame('2026-08-13', $result['previous_period']['end_date']);
        $this->assertSame(6302.0, $result['previous_period']['clicks']);
        $this->assertEqualsWithDelta(-8.2, $result['delta_percentage'], 0.05);
        $this->assertSame('DOWN', $result['direction']);
    }

    public function test_week_range_invalid_shifted_boundary_is_unavailable_not_shortened(): void
    {
        $f = $this->fixture();
        // Current end date has no equivalent day one month earlier.
        $result = $this->comparison()->range($f['machine'], 'Asia/Jakarta', '2026-10-26', '2026-10-31', 100.0);

        $this->assertFalse($result['available']);
        $this->assertSame('UNAVAILABLE', $result['comparison_status']);
    }

    public function test_month_boundary_week_via_projection_shifts_only_the_represented_range(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 300);
        // Represented range for the Sep-1 week card is Sep 1-6 (clipped to
        // month; see MachineClickTargetProjectionTest). Provide Aug 1-6 usage.
        $r0 = $this->reading($f, 0, '2026-07-31T17:00:00Z');
        $this->reading($f, 700, '2026-08-06T10:00:00Z', $r0->id); // Aug 1-6 Jakarta = 700
        $r2 = $this->reading($f, 700, '2026-08-31T17:00:00Z', null);
        $this->reading($f, 1000, '2026-09-06T10:00:00Z', $r2->id); // Sep 1-6 Jakarta = 300

        $projection = $this->projection()->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Jakarta'));

        $this->assertSame('2026-08-31', $projection['week']['week_start']);
        $this->assertSame('2026-09-06', $projection['week']['week_end']);
        $comparison = $projection['week']['comparison'];
        $this->assertTrue($comparison['available']);
        $this->assertSame('2026-08-01', $comparison['previous_period']['start_date']);
        $this->assertSame('2026-08-06', $comparison['previous_period']['end_date']);
        $this->assertSame(700.0, $comparison['previous_period']['clicks']);
    }

    // ---- range (MTD) ------------------------------------------------------

    public function test_mtd_range_caps_to_previous_months_last_valid_day(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-31T17:00:00Z');
        $this->reading($f, 900, '2026-09-30T10:00:00Z', $r0->id); // full September = 900
        // Oct 1-31 MTD (capEndToPreviousMonth=true) should compare vs Sep 1-30.
        $result = $this->comparison()->range($f['machine'], 'Asia/Jakarta', '2026-10-01', '2026-10-31', 1000.0, true);

        $this->assertTrue($result['available']);
        $this->assertSame('2026-09-01', $result['previous_period']['start_date']);
        $this->assertSame('2026-09-30', $result['previous_period']['end_date']);
        $this->assertSame(900.0, $result['previous_period']['clicks']);
    }

    public function test_leap_year_mtd_full_march_compares_against_full_february(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2028-01-31T17:00:00Z');
        $this->reading($f, 290, '2028-02-29T10:00:00Z', $r0->id); // leap Feb = 290
        $result = $this->comparison()->range($f['machine'], 'Asia/Jakarta', '2028-03-01', '2028-03-31', 400.0, true);

        $this->assertTrue($result['available']);
        $this->assertSame('2028-02-01', $result['previous_period']['start_date']);
        $this->assertSame('2028-02-29', $result['previous_period']['end_date']);
        $this->assertSame(290.0, $result['previous_period']['clicks']);
    }

    public function test_current_month_mtd_via_projection_uses_today_as_cutoff(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 50000);
        // August 1-10 Jakarta = 17828 (previous MTD cutoff equivalent).
        $r0 = $this->reading($f, 0, '2026-07-31T17:00:00Z');
        $this->reading($f, 17828, '2026-08-10T10:00:00Z', $r0->id);
        // September 1-10 Jakarta = 20038 (today = Sep 10, so MTD cutoff is Sep 10,
        // not the full month even though readings exist through month end).
        $r2 = $this->reading($f, 17828, '2026-08-31T17:00:00Z', null);
        $this->reading($f, 37866, '2026-09-10T10:00:00Z', $r2->id); // +20038 on Sep 10
        $this->reading($f, 99999, '2026-09-20T10:00:00Z'); // future-of-"today" reading must not leak into MTD

        $projection = $this->projection()->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-10 10:00:00', 'Asia/Jakarta'));

        $this->assertTrue($projection['month']['is_month_to_date']);
        $this->assertSame(20038.0, $projection['month']['comparison']['current_period']['clicks']);
        $this->assertSame('2026-09-01', $projection['month']['comparison']['current_period']['start_date']);
        $this->assertSame('2026-09-10', $projection['month']['comparison']['current_period']['end_date']);
        $this->assertSame('2026-08-01', $projection['month']['comparison']['previous_period']['start_date']);
        $this->assertSame('2026-08-10', $projection['month']['comparison']['previous_period']['end_date']);
        $this->assertSame(17828.0, $projection['month']['comparison']['previous_period']['clicks']);
        $this->assertEqualsWithDelta(12.4, $projection['month']['comparison']['delta_percentage'], 0.05);
    }

    public function test_future_month_comparison_does_not_fabricate_actual_performance(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 11, 3000);
        $r0 = $this->reading($f, 0, '2026-09-30T17:00:00Z');
        $this->reading($f, 400, '2026-10-15T10:00:00Z', $r0->id); // October actuals exist
        $projection = $this->projection()->projection($f['machine'], 2026, 11, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame(0.0, $projection['month']['actual']);
        $this->assertFalse($projection['month']['is_month_to_date']);
        $comparison = $projection['month']['comparison'];
        // Previous (October) has real data; current (November, future) is
        // truthfully zero - never a fabricated non-zero "actual".
        $this->assertTrue($comparison['available']);
        $this->assertSame(0.0, $comparison['current_period']['clicks']);
        $this->assertSame(400.0, $comparison['previous_period']['clicks']);
        $this->assertSame(-100.0, $comparison['delta_percentage']);
    }

    // ---- counter correction / void integrity -------------------------------

    public function test_historical_void_in_the_previous_month_recomputes_the_comparison(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-08T17:00:00Z'); // 2026-08-09 00:00 Jakarta - distinct date from r1
        $r1 = $this->reading($f, 1000, '2026-08-10T01:00:00Z', $r0->id);
        $before = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 500);
        $this->assertSame(1000.0, $before['previous_period']['clicks']);

        $r1->update(['status' => 'voided']);
        $after = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 500);
        $this->assertFalse($after['available']); // voided reading removes all evidence for that date
    }

    public function test_historical_correction_changes_the_comparison_value(): void
    {
        $f = $this->fixture();
        $r0 = $this->reading($f, 0, '2026-08-09T17:00:00Z');
        $r1 = $this->reading($f, 1000, '2026-08-10T01:00:00Z', $r0->id);
        $before = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 500);
        $this->assertSame(1000.0, $before['previous_period']['clicks']);

        $r1->update(['reading_value' => 1200]);
        $after = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 500);
        $this->assertSame(1200.0, $after['previous_period']['clicks']);
    }

    // ---- target independence -----------------------------------------------

    public function test_comparison_actual_does_not_depend_on_previous_months_target(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 3000); // current month has a target
        // No target row at all exists for August 2026.
        $r0 = $this->reading($f, 0, '2026-08-09T17:00:00Z');
        $this->reading($f, 1000, '2026-08-10T01:00:00Z', $r0->id);
        $result = $this->comparison()->daily($f['machine'], 'Asia/Jakarta', '2026-09-10', 500);

        $this->assertTrue($result['available']);
        $this->assertSame(1000.0, $result['previous_period']['clicks']);
    }
}
