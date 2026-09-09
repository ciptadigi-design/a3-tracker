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
use App\Models\MachineOperationalCalendarException;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\MachineClickTargetProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MachineClickTargetProjectionTest extends TestCase
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

    private function exclude(array $f, string $date, string $type = 'store_closed'): void
    {
        MachineOperationalCalendarException::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id, 'calendar_date' => $date, 'exception_type' => $type, 'excluded_from_target' => true]);
    }

    private function reading(array $f, float $value, string $observedAt, ?string $previousId = null): CounterReading
    {
        $counterTypeId = CounterType::where('code', 'total_impressions')->value('id');

        return CounterReading::create(['account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $counterTypeId, 'reading_value' => $value, 'observed_at' => $observedAt, 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $previousId, 'client_request_id' => (string) Str::uuid()]);
    }

    public function test_no_target_configured_returns_not_configured_status_and_null_monthly_target(): void
    {
        $f = $this->fixture();
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame('NOT_CONFIGURED', $projection['target_status']);
        $this->assertNull($projection['monthly_target']);
        $this->assertNull($projection['required_daily_pace']);
        $this->assertSame(30, $projection['active_days_total']);
    }

    public function test_daily_plan_sums_exactly_to_monthly_target_with_no_exclusions(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 500000);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $sum = array_sum(array_column($projection['daily'], 'planned_clicks'));
        $this->assertSame(500000, $sum);
        $this->assertSame(30, $projection['active_days_total']);
    }

    public function test_excluded_dates_receive_zero_target_and_are_not_missed(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 290);
        $this->exclude($f, '2026-09-10');
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $byDate = collect($projection['daily'])->keyBy('date');
        $this->assertSame('EXCLUDED', $byDate['2026-09-10']['calendar_status']);
        $this->assertSame(0, $byDate['2026-09-10']['planned_clicks']);
        $this->assertSame(29, $projection['active_days_total']);
        $this->assertSame(1, $projection['excluded_days_total']);
        $this->assertSame(290, array_sum(array_column($projection['daily'], 'planned_clicks')));
    }

    public function test_all_dates_excluded_returns_no_active_days_status(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 1000);
        for ($d = 1; $d <= 30; $d++) {
            $this->exclude($f, sprintf('2026-09-%02d', $d));
        }
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame('NO_ACTIVE_DAYS', $projection['target_status']);
        $this->assertSame(0, $projection['active_days_total']);
        $this->assertSame('NO_ACTIVE_DAYS_REMAINING', $projection['required_pace_status']);
    }

    public function test_weekly_target_is_sum_of_daily_plans_within_the_selected_month(): void
    {
        $f = $this->fixture();
        // 30 active days in September 2026, target 300 -> flat 10/day.
        $this->setTarget($f, 2026, 9, 300);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Jakarta'));
        // Week containing Sep 1 2026 (Tue) is Aug 31 (Mon) - Sep 6 (Sun); only Sep 1-6 belong to September.
        $this->assertSame(60, $projection['week']['planned']);
        $this->assertSame('2026-08-31', $projection['week']['week_start']);
        $this->assertSame('2026-09-06', $projection['week']['week_end']);
    }

    public function test_actual_clicks_reconcile_with_effective_counter_sequence(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 3000);
        $r0 = $this->reading($f, 1000, '2026-08-31T17:00:00Z');
        $this->reading($f, 1500, '2026-09-01T01:00:00Z', $r0->id);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Jakarta'));

        $this->assertSame(500.0, $projection['actual_month_to_date']);
        $byDate = collect($projection['daily'])->keyBy('date');
        $this->assertSame(500.0, $byDate['2026-09-01']['actual_clicks']);
    }

    public function test_counter_void_recomputes_the_projection(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 3000);
        $r0 = $this->reading($f, 1000, '2026-08-31T17:00:00Z');
        $r1 = $this->reading($f, 1500, '2026-09-01T01:00:00Z', $r0->id);
        $before = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Jakarta'));
        $this->assertSame(500.0, $before['actual_month_to_date']);

        $r1->update(['status' => 'voided']);
        $after = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Jakarta'));
        $this->assertSame(0.0, $after['actual_month_to_date']);
    }

    public function test_target_already_achieved_yields_zero_required_pace_and_achieved_status(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 100);
        $r0 = $this->reading($f, 0, '2026-08-31T17:00:00Z');
        $this->reading($f, 500, '2026-09-01T01:00:00Z', $r0->id);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame('ACHIEVED', $projection['target_status']);
        $this->assertSame(0, $projection['required_daily_pace']);
        $this->assertSame('ACHIEVED', $projection['required_pace_status']);
        $this->assertSame(0, $projection['remaining_target']);
    }

    public function test_behind_pace_produces_behind_status_and_positive_required_pace(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 300); // flat 10/day across 30 active days
        // No clicks recorded at all by day 15 -> behind.
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame('BEHIND', $projection['target_status']);
        $this->assertGreaterThan(0, $projection['required_daily_pace']);
        $this->assertSame(150, $projection['planned_month_to_date']);
    }

    public function test_ahead_of_pace_produces_ahead_status(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 9, 300);
        $r0 = $this->reading($f, 0, '2026-08-31T17:00:00Z');
        // Well over the day-15 expectation of 150, but short of the full monthly target.
        $this->reading($f, 200, '2026-09-05T01:00:00Z', $r0->id);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame('AHEAD', $projection['target_status']);
    }

    public function test_future_month_has_no_elapsed_days_and_full_remaining_pace(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 11, 3000);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 11, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame(0, $projection['active_days_elapsed']);
        $this->assertSame(30, $projection['active_days_remaining']);
        $this->assertSame(0.0, $projection['actual_month_to_date']);
        $this->assertSame(100, $projection['required_daily_pace']);
    }

    public function test_past_month_with_no_active_days_remaining_returns_truthful_state(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 7, 1000);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 7, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame(0, $projection['active_days_remaining']);
        $this->assertSame('NO_ACTIVE_DAYS_REMAINING', $projection['required_pace_status']);
        $this->assertNull($projection['required_daily_pace']);
    }

    public function test_leap_year_february_projection_sums_exactly(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2028, 2, 87);
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2028, 2, CarbonImmutable::parse('2028-02-15 10:00:00', 'Asia/Jakarta'));

        $this->assertSame(29, $projection['calendar_days']);
        $this->assertSame(87, array_sum(array_column($projection['daily'], 'planned_clicks')));
    }

    public function test_asia_jakarta_boundary_is_respected_for_actual_clicks(): void
    {
        $f = $this->fixture('Asia/Jakarta');
        $this->setTarget($f, 2026, 9, 3000);
        $r0 = $this->reading($f, 1000, '2026-08-31T16:59:00Z'); // 2026-08-31 23:59 Jakarta - still August
        $this->reading($f, 1600, '2026-08-31T17:01:00Z', $r0->id); // 2026-09-01 00:01 Jakarta - September
        $projection = app(MachineClickTargetProjectionService::class)->projection($f['machine'], 2026, 9, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta'));

        $byDate = collect($projection['daily'])->keyBy('date');
        $this->assertSame(600.0, $byDate['2026-09-01']['actual_clicks']);
    }
}
