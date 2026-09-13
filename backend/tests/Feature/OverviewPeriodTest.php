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
use App\Services\MachineCostService;
use App\Services\OperationalReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OverviewPeriodTest extends TestCase
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

    public function test_range_scopes_actuals_targets_chart_and_cost_with_true_predecessor(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 8, 3100);
        $this->setTarget($f, 2026, 9, 6000);
        $base = $this->reading($f, 1000, '2026-08-30 05:00:00');
        $first = $this->reading($f, 1100, '2026-08-31 05:00:00', $base->id);
        $this->reading($f, 1400, '2026-09-01 05:00:00', $first->id);
        $this->reading($f, 9000, '2026-09-03 05:00:00');
        $this->actingAs($f['user']);
        $id = $f['machine']->id;
        $response = $this->getJson("/api/v1/machines/$id/click-target?period_start=2026-08-31&period_end=2026-09-01")->assertOk();
        $response->assertJsonPath('data.actual_clicks', 400)
            ->assertJsonPath('data.period_target', 300)
            ->assertJsonPath('data.variance', 100)
            ->assertJsonCount(2, 'data.daily')
            ->assertJsonPath('data.daily.0.date', '2026-08-31')
            ->assertJsonPath('data.daily.1.date', '2026-09-01');
        $cost = $this->getJson("/api/v1/machines/$id/cost?period_start=2026-08-31&period_end=2026-09-01&summary_only=1")->assertOk();
        $cost->assertJsonPath('total_clicks', 400)->assertJsonMissingPath('operating_costs')->assertJsonMissingPath('selling_prices');
        $this->assertEquals(app(MachineCostService::class)->period($f['machine'], '2026-08-31', '2026-09-01')['standard_cost_per_click'], $cost->json('standard_cost_per_click'));
        $report = app(OperationalReportService::class)->build($f['account']->id, $f['branch']->id, $id, '2026-08-31', '2026-09-01');
        $this->assertEquals($response->json('data.actual_clicks'), $report['overview']['total_clicks']);
        $this->getJson("/api/v1/machines/$id/click-target?period_start=2026-09-01&period_end=2026-09-01")
            ->assertOk()->assertJsonPath('data.actual_clicks', 300)->assertJsonPath('data.period_target', 200)->assertJsonCount(1, 'data.daily');
    }

    public function test_all_presets_can_be_requested_and_chart_is_exactly_bounded(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user']);
        foreach ([['2026-09-13', '2026-09-13', 1], ['2026-09-07', '2026-09-13', 7], ['2026-09-01', '2026-09-13', 13], ['2026-08-01', '2026-08-31', 31], ['2026-01-01', '2026-09-13', 256], ['2026-08-31', '2026-09-02', 3]] as [$from, $to, $count]) {
            $this->getJson("/api/v1/machines/{$f['machine']->id}/click-target?period_start=$from&period_end=$to")
                ->assertOk()->assertJsonPath('data.period.start', $from)->assertJsonPath('data.period.end', $to)->assertJsonCount($count, 'data.daily');
        }
    }

    public function test_range_validation_reuses_report_safety_for_both_overview_reads(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user']);
        foreach (['click-target', 'cost'] as $endpoint) {
            foreach ([['2026-09-02', '2026-09-01'], ['2026-02-30', '2026-03-02'], ['bad', '2026-03-02'], ['2026-01-01', 'bad'], ['2026-01-01', '2027-01-03']] as [$from, $to]) {
                $this->getJson("/api/v1/machines/{$f['machine']->id}/$endpoint?period_start=$from&period_end=$to&summary_only=1")->assertUnprocessable();
            }
            $this->getJson("/api/v1/machines/{$f['machine']->id}/$endpoint?period_start=2026-01-01&period_end=2027-01-02&summary_only=1")->assertOk();
        }
    }

    public function test_partial_target_range_never_claims_complete_target(): void
    {
        $f = $this->fixture();
        $this->setTarget($f, 2026, 8, 3100);
        $data = app(MachineClickTargetProjectionService::class)->range($f['machine'], '2026-08-31', '2026-09-01');
        $this->assertNull($data['period_target']);
        $this->assertNull($data['variance']);
        $this->assertSame(['2026-09'], $data['missing_target_months']);
        $this->assertSame('NOT_CONFIGURED', $data['target_status']);
        $this->assertCount(2, $data['daily']);
    }

    public function test_machine_timezone_and_calendar_allocation_remain_canonical(): void
    {
        $f = $this->fixture('America/New_York');
        $this->setTarget($f, 2026, 9, 6000);
        $base = $this->reading($f, 100, '2026-09-01 03:30:00'); // Aug 31 locally
        $this->reading($f, 250, '2026-09-01 04:00:00', $base->id);
        $projection = app(MachineClickTargetProjectionService::class);
        $range = $projection->range($f['machine'], '2026-09-01', '2026-09-01');
        $legacy = $projection->projection($f['machine'], 2026, 9);
        $this->assertSame('America/New_York', $range['period']['timezone']);
        $this->assertEquals(150, $range['actual_clicks']);
        $this->assertEquals($legacy['daily'][0]['planned_clicks'], $range['daily'][0]['planned_clicks']);
    }

    public function test_denied_branch_and_cross_account_cannot_read_overview(): void
    {
        $f = $this->fixture();
        $outsider = User::factory()->create(['status' => 'active']);
        $other = Account::create(['code' => 'OTHER', 'name' => 'Other']);
        AccountMembership::create(['account_id' => $other->id, 'user_id' => $outsider->id, 'role' => 'owner', 'status' => 'active']);
        $this->actingAs($outsider);
        $query = '?period_start=2026-09-01&period_end=2026-09-13&summary_only=1';
        foreach (['click-target', 'cost'] as $endpoint) {
            $this->getJson("/api/v1/machines/{$f['machine']->id}/$endpoint$query")->assertForbidden();
        }
        $membership = AccountMembership::create(['account_id' => $f['account']->id, 'user_id' => $outsider->id, 'role' => 'operator', 'status' => 'active']);
        $branch = $f['account']->branches()->create(['code' => 'OTHER', 'name' => 'Other']);
        AccountMembershipBranch::create(['account_id' => $f['account']->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        foreach (['click-target', 'cost'] as $endpoint) {
            $this->getJson("/api/v1/machines/{$f['machine']->id}/$endpoint$query")->assertForbidden();
        }
    }

    public function test_counter_queries_have_sql_range_bounds_and_do_not_load_history(): void
    {
        $f = $this->fixture();
        DB::enableQueryLog();
        app(MachineClickTargetProjectionService::class)->range($f['machine'], '2026-09-01', '2026-09-13');
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'counter_readings'));
        DB::disableQueryLog();
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringContainsString('observed_at', $sql);
            $this->assertTrue(str_contains($sql, 'limit 1') || (str_contains($sql, '>=') && str_contains($sql, '<')), $sql);
        }
    }

    public function test_batched_daily_comparisons_preserve_legacy_calendar_semantics(): void
    {
        $f = $this->fixture();
        $base = $this->reading($f, 100, '2026-07-30 05:00:00');
        $this->reading($f, 200, '2026-08-01 05:00:00', $base->id);
        $this->reading($f, 350, '2026-09-01 05:00:00');
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');
        try {
            $service = app(MachineClickTargetProjectionService::class);
            $range = $service->range($f['machine'], '2026-09-01', '2026-09-13');
            $legacy = $service->projection($f['machine'], 2026, 9);
            foreach ($range['daily'] as $index => $row) {
                $this->assertEquals($legacy['daily'][$index]['previous_month'], $row['previous_month']);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
