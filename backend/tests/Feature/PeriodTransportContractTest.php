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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodTransportContractTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'PT', 'name' => 'Period Transport', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-MAIN-A3-01', 'display_name' => 'C1070', 'timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $counterTypeId = CounterType::where('code', 'total_impressions')->value('id');

        // A chained series of effective readings (each linked via previous_reading_id,
        // mirroring legacy import / manual-entry behavior) straddling the July/August
        // and August/September Jakarta boundaries, proving the August window includes
        // exactly the August-attributed usage and nothing from adjacent months.
        $r0 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 1000, 'observed_at' => '2026-07-31T16:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'client_request_id' => 'a0000000-0000-4000-8000-000000000001']);
        // 2026-07-31T16:00Z = 2026-07-31T23:00 Jakarta (still July 31).
        $r1 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 1500, 'observed_at' => '2026-08-01T01:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r0->id, 'client_request_id' => 'a0000000-0000-4000-8000-000000000002']);
        // 2026-08-01T01:00Z = 2026-08-01T08:00 Jakarta (August 1); usage = 500.
        $r2 = CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 2200, 'observed_at' => '2026-08-31T16:59:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r1->id, 'client_request_id' => 'a0000000-0000-4000-8000-000000000003']);
        // 2026-08-31T16:59Z = 2026-08-31T23:59 Jakarta (still August 31); usage = 700.
        CounterReading::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'counter_type_id' => $counterTypeId, 'reading_value' => 3000, 'observed_at' => '2026-09-05T02:00:00Z', 'status' => 'effective', 'source' => 'legacy_import', 'previous_reading_id' => $r2->id, 'client_request_id' => 'a0000000-0000-4000-8000-000000000004']);
        // 2026-09-05T02:00Z = 2026-09-05T09:00 Jakarta (September); usage = 800.

        return compact('account', 'branch', 'user', 'machine');
    }

    public function test_august_counter_history_projects_into_total_clicks_and_daily_click_trend(): void
    {
        $f = $this->fixture();

        $response = $this->actingAs($f['user'])
            ->getJson("/api/v1/reports?account_id={$f['account']->id}&branch_id={$f['branch']->id}&period_start=2026-08-01&period_end=2026-08-31")
            ->assertOk();

        // usage = 1500-1000 (Aug 1 reading) + 2200-1500 (Aug 31 reading) = 1200, September reading excluded.
        $response->assertJsonPath('overview.total_clicks', 1200);
        $dailyClicks = $response->json('daily_clicks');
        $this->assertSame(['2026-08-01', '2026-08-31'], collect($dailyClicks)->pluck('operational_date')->sort()->values()->all());
        $this->assertEqualsWithDelta(1200.0, collect($dailyClicks)->sum('total_clicks'), 0.001);

        $machineCost = $this->actingAs($f['user'])
            ->getJson("/api/v1/machines/{$f['machine']->id}/cost?period_start=2026-08-01&period_end=2026-08-31")
            ->assertOk();
        $machineCost->assertJsonPath('total_clicks', 1200);
    }

    public function test_repeated_reads_of_the_same_period_never_duplicate_or_rewrite_counters(): void
    {
        $f = $this->fixture();
        $url = "/api/v1/reports?account_id={$f['account']->id}&branch_id={$f['branch']->id}&period_start=2026-08-01&period_end=2026-08-31";

        $first = $this->actingAs($f['user'])->getJson($url)->assertOk()->json('overview.total_clicks');
        $second = $this->actingAs($f['user'])->getJson($url)->assertOk()->json('overview.total_clicks');
        $third = $this->actingAs($f['user'])->getJson($url)->assertOk()->json('overview.total_clicks');

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
        $this->assertSame(4, CounterReading::where('machine_id', $f['machine']->id)->count());
    }

    public function test_current_month_and_year_boundary_resolve_correctly(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['user'])
            ->getJson("/api/v1/reports?account_id={$f['account']->id}&branch_id={$f['branch']->id}&period_start=2026-09-01&period_end=2026-09-30")
            ->assertOk()
            ->assertJsonPath('overview.total_clicks', 800);

        $this->actingAs($f['user'])
            ->getJson("/api/v1/reports?account_id={$f['account']->id}&branch_id={$f['branch']->id}&period_start=2026-07-01&period_end=2026-07-31")
            ->assertOk()
            ->assertJsonPath('overview.total_clicks', 0);
    }

    /** @dataProvider malformedPeriodProvider */
    public function test_malformed_period_values_are_rejected_with_y_m_d_contract(string $start, string $end): void
    {
        $f = $this->fixture();

        $this->actingAs($f['user'])
            ->getJson("/api/v1/reports?account_id={$f['account']->id}&branch_id={$f['branch']->id}&period_start=".urlencode($start)."&period_end=".urlencode($end))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_start']);

        $this->actingAs($f['user'])
            ->getJson("/api/v1/machines/{$f['machine']->id}/cost?period_start=".urlencode($start)."&period_end=".urlencode($end))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_start']);
    }

    public static function malformedPeriodProvider(): array
    {
        return [
            'iso timestamp' => ['2026-08-01T00:00:00Z', '2026-08-31T00:00:00Z'],
            'locale formatted' => ['08/01/2026', '08/31/2026'],
            'single digit month/day' => ['2026-8-1', '2026-8-31'],
            'javascript Date.toString()' => ['Sat Aug 01 2026 00:00:00 GMT+0700', 'Mon Aug 31 2026 00:00:00 GMT+0700'],
        ];
    }
}
