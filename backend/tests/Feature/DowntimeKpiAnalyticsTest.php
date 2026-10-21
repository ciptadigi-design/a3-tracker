<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\BranchProductionSchedule;
use App\Models\DowntimeEvent;
use App\Models\Machine;
use App\Models\MachineFinancialRate;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 - owner KPI semantics (see OwnerDowntimeOverviewService docblock):
 * availability, production downtime, distinct event count, completed-only recovery,
 * schedule dependency and branch scoping. All times are Asia/Jakarta (UTC+7).
 */
class DowntimeKpiAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Branch $main;

    private Branch $second;

    private Machine $m1;

    private Machine $m2;

    private Machine $m3;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 00:00:00', 'Asia/Jakarta'));
        $this->account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $this->main = Branch::create(['account_id' => $this->account->id, 'code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $this->second = Branch::create(['account_id' => $this->account->id, 'code' => 'SECOND', 'name' => 'Second', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $model = MachineModel::create(['manufacturer_id' => Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta'])->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $mk = fn (Branch $b, string $code) => Machine::create(['account_id' => $this->account->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => $code, 'display_name' => $code, 'status' => 'active']);
        $this->m1 = $mk($this->main, 'A3-01');
        $this->m2 = $mk($this->main, 'A3-02');
        $this->m3 = $mk($this->second, 'B3-01');
        $this->owner = $this->member('owner');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function member(string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $m = AccountMembership::create(['account_id' => $this->account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        if ($branch) {
            AccountMembershipBranch::create(['account_id' => $this->account->id, 'membership_id' => $m->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    private function schedule(Branch $branch, string $start = '08:00:00', string $end = '17:00:00', bool $crosses = false): void
    {
        for ($d = 0; $d <= 6; $d++) {
            BranchProductionSchedule::create(['account_id' => $this->account->id, 'branch_id' => $branch->id, 'day_of_week' => $d, 'is_operating' => true, 'start_time' => $start, 'end_time' => $end, 'crosses_midnight' => $crosses]);
        }
    }

    private function downtime(Machine $machine, string $start, ?string $end, string $cause = 'jam', ?string $code = null): DowntimeEvent
    {
        return DowntimeEvent::create([
            'account_id' => $this->account->id, 'branch_id' => $machine->branch_id, 'machine_id' => $machine->id, 'cause' => $cause, 'error_code_text' => $code,
            // Eloquent writes the wall-clock of the instance as-is, so store UTC like the application does.
            'actual_start_at' => CarbonImmutable::parse($start, 'Asia/Jakarta')->utc(), 'actual_end_at' => $end ? CarbonImmutable::parse($end, 'Asia/Jakarta')->utc() : null,
            'created_by' => $this->owner->id, 'active_key' => $end ? null : 'active',
        ]);
    }

    private function kpi(string $start, string $end, array $query = [], ?User $as = null): array
    {
        $qs = http_build_query(['account_id' => (string) $this->account->id, 'period_start' => $start, 'period_end' => $end] + $query);

        return $this->actingAs($as ?? $this->owner)->getJson("/api/v1/downtime/overview?$qs")->assertOk()->json('data');
    }

    public function test_availability_uses_scheduled_time_as_denominator_and_ignores_off_shift_downtime(): void
    {
        $this->schedule($this->main);
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 11:00'); // 120 min in shift
        $this->downtime($this->m1, '2026-10-06 18:00', '2026-10-06 20:00'); // off-shift: counts as an event, not as production downtime
        $d = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id]);

        $this->assertSame(31 * 540, $d['scheduled_minutes']);
        $this->assertSame(120, $d['production_downtime_minutes']);
        $this->assertSame(round(((31 * 540 - 120) / (31 * 540)) * 100, 1), $d['availability_percent']);
        $this->assertSame(2, $d['event_count']);
    }

    public function test_overlapping_incidents_on_one_machine_are_not_double_counted(): void
    {
        $this->schedule($this->main);
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 11:00');
        $this->downtime($this->m1, '2026-10-05 10:00', '2026-10-05 12:00');
        $d = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id]);

        $this->assertSame(180, $d['production_downtime_minutes']);
        $this->assertSame(2, $d['event_count']);
    }

    public function test_ongoing_incident_counts_to_now_but_is_excluded_from_recovery_average(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 12:00:00', 'Asia/Jakarta'));
        $this->schedule($this->main);
        $this->downtime($this->m1, '2026-11-10 09:00', null);
        $this->downtime($this->m1, '2026-11-02 09:00', '2026-11-02 10:00'); // completed, 60 min
        $d = $this->kpi('2026-11-01', '2026-11-30', ['machine_id' => (string) $this->m1->id]);

        // Future time of the month is neither scheduled nor downtime: 9 full days + 08:00-12:00 on the 10th.
        $this->assertSame(9 * 540 + 240, $d['scheduled_minutes']);
        $this->assertSame(180 + 60, $d['production_downtime_minutes']);
        $this->assertSame(2, $d['event_count']);
        $this->assertSame(1, $d['ongoing_event_count']);
        $this->assertSame(1, $d['completed_event_count']);
        $this->assertSame(60, $d['average_recovery_minutes']);
        $this->assertSame(1, $d['stopped_machine_count']);
    }

    public function test_cross_midnight_incident_is_one_event_and_overnight_shift_is_counted_once(): void
    {
        $this->schedule($this->main, '22:00:00', '06:00:00', true);
        $this->downtime($this->m1, '2026-10-10 23:00', '2026-10-11 02:00');
        $d = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id]);

        $this->assertSame(1, $d['event_count']);
        $this->assertSame(180, $d['production_downtime_minutes']);
        $this->assertSame(180, $d['average_recovery_minutes']);
    }

    public function test_cross_month_incident_is_clipped_per_period_and_counted_in_both_but_recovered_once(): void
    {
        $this->schedule($this->main, '22:00:00', '06:00:00', true);
        $this->downtime($this->m1, '2026-09-30 22:00', '2026-10-01 02:00'); // 240 min total
        $sep = $this->kpi('2026-09-01', '2026-09-30', ['machine_id' => (string) $this->m1->id]);
        $oct = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id]);

        $this->assertSame(120, $sep['production_downtime_minutes']);
        $this->assertSame(120, $oct['production_downtime_minutes']);
        $this->assertSame(1, $sep['event_count']);
        $this->assertSame(1, $oct['event_count']);
        $this->assertNull($sep['average_recovery_minutes']);
        $this->assertSame(240, $oct['average_recovery_minutes']);
    }

    public function test_missing_schedule_is_not_configured_never_zero_availability(): void
    {
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 11:00');
        $d = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id]);

        $this->assertSame('not_configured', $d['schedule_status']);
        $this->assertNull($d['availability_percent']);
        $this->assertNull($d['production_downtime_minutes']);
        $this->assertNull($d['scheduled_minutes']);
        $this->assertSame(1, $d['event_count']);
        $this->assertSame(120, $d['average_recovery_minutes']);
    }

    public function test_partial_configuration_is_flagged_and_unconfigured_machines_stay_out_of_the_denominator(): void
    {
        $this->schedule($this->main);
        $this->downtime($this->m3, '2026-10-05 09:00', '2026-10-05 11:00'); // unconfigured branch
        $d = $this->kpi('2026-10-01', '2026-10-31');

        $this->assertSame('partial', $d['schedule_status']);
        $this->assertSame(2, $d['configured_machine_count']);
        $this->assertSame(3, $d['machine_count']);
        $this->assertSame(2 * 31 * 540, $d['scheduled_minutes']);
        $this->assertSame(0, $d['production_downtime_minutes']);
        $this->assertEquals(100.0, $d['availability_percent']);
        $this->assertSame(1, $d['event_count']);
    }

    public function test_empty_period_with_a_schedule_is_fully_available_with_no_recovery_average(): void
    {
        $this->schedule($this->main);
        $d = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id]);

        $this->assertEquals(100.0, $d['availability_percent']);
        $this->assertSame(0, $d['production_downtime_minutes']);
        $this->assertSame(0, $d['event_count']);
        $this->assertNull($d['average_recovery_minutes']);
    }

    public function test_future_period_has_no_denominator_so_no_fabricated_availability(): void
    {
        $this->schedule($this->main);
        $d = $this->kpi('2026-12-01', '2026-12-31', ['machine_id' => (string) $this->m1->id]);

        $this->assertNull($d['availability_percent']);
    }

    public function test_selected_machine_filter_isolates_that_machine(): void
    {
        $this->schedule($this->main);
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 10:00');
        $this->downtime($this->m2, '2026-10-06 09:00', '2026-10-06 12:00');

        $this->assertSame(60, $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id])['production_downtime_minutes']);
        $this->assertSame(180, $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m2->id])['production_downtime_minutes']);
        $this->assertSame(240, $this->kpi('2026-10-01', '2026-10-31', ['branch_id' => (string) $this->main->id])['production_downtime_minutes']);
    }

    public function test_restricted_member_only_aggregates_assigned_branches(): void
    {
        $this->schedule($this->main);
        $this->schedule($this->second);
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 10:00');
        $this->downtime($this->m3, '2026-10-05 09:00', '2026-10-05 13:00');
        $admin = $this->member('admin', $this->main);

        $d = $this->kpi('2026-10-01', '2026-10-31', [], $admin);
        $this->assertSame(60, $d['production_downtime_minutes']);
        $this->assertSame(1, $d['event_count']);
        $this->assertSame(2, $d['machine_count']);

        $blocked = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m3->id], $admin);
        $this->assertSame(0, $blocked['event_count']);
        $this->assertSame(0, $blocked['machine_count']);
        $this->actingAs($admin)->getJson('/api/v1/downtime/overview?'.http_build_query(['account_id' => (string) $this->account->id, 'branch_id' => (string) $this->second->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']))->assertForbidden();
    }

    public function test_report_detail_ranks_machines_counts_causes_and_trend_reconciles_with_the_total(): void
    {
        $this->schedule($this->main);
        $this->downtime($this->m1, '2026-09-29 09:00', '2026-09-29 10:00', 'mekanik', 'C-3913');
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 09:30', 'mekanik', 'C-3913');
        $this->downtime($this->m2, '2026-10-06 09:00', '2026-10-06 12:00', 'listrik');
        $d = $this->kpi('2026-09-01', '2026-10-31', ['detail' => 1]);

        $this->assertSame(['A3-02', 'A3-01'], array_column($d['ranking'], 'machine_code'));
        $this->assertSame(180, $d['ranking'][0]['production_downtime_minutes']);
        $this->assertSame(['cause' => 'mekanik', 'count' => 2], $d['causes'][0]);
        $this->assertSame([['error_code' => 'C-3913', 'count' => 2]], $d['error_codes']);
        $this->assertSame(['2026-09', '2026-10'], array_column($d['trend'], 'bucket'));
        $this->assertSame($d['production_downtime_minutes'], array_sum(array_column($d['trend'], 'production_downtime_minutes')));
        $this->assertSame(3, $d['completed_event_count']);
        $this->assertSame(90, $d['average_recovery_minutes']); // (60 + 30 + 180) / 3
    }

    public function test_single_month_report_trend_is_daily(): void
    {
        $this->schedule($this->main);
        $d = $this->kpi('2026-10-01', '2026-10-07', ['detail' => 1]);

        $this->assertCount(7, $d['trend']);
        $this->assertSame('2026-10-01', $d['trend'][0]['bucket']);
    }

    public function test_overview_without_detail_stays_compact(): void
    {
        $d = $this->kpi('2026-10-01', '2026-10-31');

        $this->assertArrayNotHasKey('ranking', $d);
        $this->assertArrayNotHasKey('trend', $d);
    }

    public function test_legacy_and_new_cause_keys_coexist_in_report_aggregation(): void
    {
        $this->schedule($this->main);
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 10:00', 'mechanical');
        $this->downtime($this->m1, '2026-10-06 09:00', '2026-10-06 10:00', 'paper_jam');
        $this->downtime($this->m2, '2026-10-07 09:00', '2026-10-07 10:00', 'paper_jam');
        $d = $this->kpi('2026-10-01', '2026-10-31', ['detail' => 1]);

        $counts = collect($d['causes'])->pluck('count', 'cause')->all();
        $this->assertSame(['paper_jam' => 2, 'mechanical' => 1], $counts);
        $this->assertSame(['cause' => 'paper_jam', 'count' => 2], $d['causes'][0]);
    }

    private function rates(Machine $machine, ?int $cost, ?int $revenue): void
    {
        MachineFinancialRate::create(['account_id' => $this->account->id, 'machine_id' => $machine->id, 'operating_cost_per_hour' => $cost, 'estimated_revenue_per_hour' => $revenue]);
    }

    public function test_financial_impact_uses_production_downtime_hours_times_rate_and_keeps_both_estimates_separate(): void
    {
        $this->schedule($this->main);
        $this->rates($this->m1, 50000, 200000);
        $this->downtime($this->m1, '2026-10-05 08:00', '2026-10-05 10:30'); // 150 min in shift
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 10:00'); // overlaps the first: merged, not double counted
        $this->downtime($this->m1, '2026-10-06 18:00', '2026-10-06 22:00'); // off-shift: no production downtime
        $f = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id, 'detail' => 1]);

        $this->assertSame(150, $f['financial']['production_downtime_minutes']);
        $this->assertSame(125000, $f['financial']['operating_cost_impact']['amount']);
        $this->assertSame(500000, $f['financial']['potential_lost_revenue']['amount']);
        $this->assertSame('configured', $f['financial']['operating_cost_impact']['status']);
        $this->assertSame(125000, $f['ranking'][0]['operating_cost_impact']);
    }

    public function test_missing_rate_is_null_never_zero_and_zero_downtime_with_rate_is_zero(): void
    {
        $this->schedule($this->main);
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 10:00');
        $none = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m1->id]);
        $this->assertNull($none['financial']['operating_cost_impact']['amount']);
        $this->assertSame('not_configured', $none['financial']['potential_lost_revenue']['status']);

        $this->rates($this->m2, 10000, null);
        $zero = $this->kpi('2026-10-01', '2026-10-31', ['machine_id' => (string) $this->m2->id]);
        $this->assertSame(0, $zero['financial']['operating_cost_impact']['amount']);
        $this->assertNull($zero['financial']['potential_lost_revenue']['amount']);
    }

    public function test_partial_rates_sum_only_rated_machines_and_open_downtime_is_included(): void
    {
        $this->schedule($this->main);
        $this->rates($this->m1, 60000, null);
        $this->downtime($this->m1, '2026-11-19 09:00', '2026-11-19 10:00'); // 60 min
        $this->downtime($this->m2, '2026-11-19 09:00', '2026-11-19 10:00'); // m2 has no rate
        $f = $this->kpi('2026-11-01', '2026-11-30', ['branch_id' => (string) $this->main->id])['financial'];
        $this->assertSame(60000, $f['operating_cost_impact']['amount']);
        $this->assertSame('partial', $f['operating_cost_impact']['status']);
        $this->assertSame(1, $f['operating_cost_impact']['rated_machine_count']);
    }

    public function test_rates_are_manageable_by_managers_only(): void
    {
        $this->schedule($this->main);
        $viewer = $this->member('operator'); // machine_cost.view (read) but not operating_cost.manage
        $this->assertArrayHasKey('financial', $this->kpi('2026-10-01', '2026-10-31', [], $viewer));

        $url = "/api/v1/machines/{$this->m1->id}/financial-rates";
        $this->actingAs($viewer)->putJson($url, ['operating_cost_per_hour' => 1, 'estimated_revenue_per_hour' => 1])->assertForbidden();
        $this->actingAs($this->owner)->putJson($url, ['operating_cost_per_hour' => 12.5, 'estimated_revenue_per_hour' => null])->assertStatus(422);
        $this->actingAs($this->owner)->putJson($url, ['operating_cost_per_hour' => 40000, 'estimated_revenue_per_hour' => null])->assertOk();
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('data.operating_cost_per_hour', 40000)->assertJsonPath('data.estimated_revenue_per_hour', null);
    }

    public function test_event_list_exposes_incident_financial_breakdown_with_applied_rates(): void
    {
        $this->schedule($this->main);
        $this->rates($this->m1, 30000, 90000);
        $this->downtime($this->m1, '2026-10-05 09:00', '2026-10-05 11:00');
        $row = $this->actingAs($this->owner)->getJson('/api/v1/downtime/events')->assertOk()->json('data.data.0');
        $this->assertSame(['operating_cost_per_hour' => 30000, 'estimated_revenue_per_hour' => 90000, 'operating_cost_impact' => 60000, 'potential_lost_revenue' => 180000], $row['financial']);
    }
}
