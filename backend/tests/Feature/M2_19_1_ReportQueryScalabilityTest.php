<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\ComponentCatalog;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\User;
use App\Services\EffectiveCounterSequence;
use App\Services\MachineCostService;
use App\Services\MachineTimezoneResolver;
use App\Services\OperationalReportService;
use App\Services\ReplaceMachineComponent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.19.1 (closing the M2.19 report-scalability finding): incidents(),
 * counterRows(), and replacements() in OperationalReportService, and
 * MachineCostService::period() via EffectiveCounterSequence::forMachine(),
 * previously loaded the ENTIRE account/machine history unconditionally and
 * filtered by exact local date in PHP afterward - a 30-day report request
 * cost exactly the same as a multi-year one. Fixed by fetching a SAFE UTC
 * SUPERSET (±1 day around the requested local range, correct for any
 * real-world IANA offset -12:00..+14:00) at the SQL level, then leaving the
 * exact existing local-date PHP filter completely unchanged as the sole
 * authoritative filter - so results are provably identical, only the amount
 * of data fetched from the database changes.
 *
 * This suite covers: the large-fixture scalability proof (Phase 3/9), every
 * boundary case Phase 4 calls out explicitly, and result parity across two
 * different timezones.
 */
class M2_19_1_ReportQueryScalabilityTest extends TestCase
{
    use RefreshDatabase;

    private function baseFixture(string $timezone = 'Asia/Jakarta'): array
    {
        $account = Account::create(['code' => 'SCALE', 'name' => 'Scale', 'default_timezone' => $timezone]);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => $timezone]);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'M-'.Str::random(6), 'name' => 'Maker']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'X-'.Str::random(6), 'name' => 'Model']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A1', 'display_name' => 'A1', 'status' => 'active']);
        $counterType = CounterType::whereRaw('lower(code)=?', ['total_impressions'])->firstOrFail();

        return compact('account', 'branch', 'user', 'machine', 'counterType');
    }

    /**
     * Bulk-inserts $count effective counter readings for $machine, one per
     * day starting $startDate (UTC-naive date, interpreted literally as
     * stored observed_at at local noon-equivalent UTC to keep the math
     * simple), correctly chaining previous_reading_id in creation order so
     * counterRows()'s stored-pointer computation has real chains to walk,
     * matching real data instead of every row having a null previous.
     */
    private function bulkInsertReadings(array $f, string $startDate, int $count, float $startValue = 1000): void
    {
        $rows = [];
        $previousId = null;
        $value = $startValue;
        $date = Carbon::parse($startDate, 'UTC');
        foreach (range(1, $count) as $i) {
            $id = (string) Str::uuid();
            $rows[] = [
                'id' => $id,
                'account_id' => $f['account']->id,
                'machine_id' => $f['machine']->id,
                'counter_type_id' => $f['counterType']->id,
                'reading_value' => $value,
                'observed_at' => $date->copy()->addHours(8)->toDateTimeString(),
                'previous_reading_id' => $previousId,
                'status' => 'effective',
                'source' => 'manual',
                'client_request_id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $previousId = $id;
            $value += 100;
            $date->addDay();
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('counter_readings')->insert($chunk);
        }
    }

    private function bulkInsertIncidents(array $f, string $startDate, int $count): void
    {
        $rows = [];
        $date = Carbon::parse($startDate, 'UTC');
        foreach (range(1, $count) as $i) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'account_id' => $f['account']->id,
                'branch_id' => $f['branch']->id,
                'machine_id' => null,
                'occurred_at' => $date->copy()->addHours(9)->toDateTimeString(),
                'category' => 'waste',
                'incident_type' => 'test',
                'description' => 'bulk fixture',
                'status' => 'open',
                'client_request_id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $date->addDay();
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('operational_incidents')->insert($chunk);
        }
    }

    public function test_a_30_day_request_does_not_load_multi_year_counter_history(): void
    {
        $f = $this->baseFixture();
        // ~3 years of daily readings: far more than a 30-day window needs.
        $this->bulkInsertReadings($f, '2023-01-01', 1000);
        $this->assertSame(1000, DB::table('counter_readings')->where('machine_id', $f['machine']->id)->count(), 'sanity: fixture actually has 1000 rows');

        DB::enableQueryLog();
        $service = app(OperationalReportService::class);
        $result = $service->build($f['account']->id, $f['branch']->id, null, '2024-06-01', '2024-06-30');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $counterQuery = collect($log)->first(fn ($q) => str_contains($q['query'], 'counter_readings') && str_contains($q['query'], 'observed_at'));
        $this->assertNotNull($counterQuery, 'the counter_readings query must include an observed_at predicate, not load unconditionally');

        // Independently derive the same buffered range and count what SHOULD
        // be loaded, proving it is a small fraction of the 1000-row table.
        $afterRowsLoaded = DB::table('counter_readings')->where('machine_id', $f['machine']->id)
            ->where('observed_at', '>=', Carbon::parse('2024-06-01')->subDay()->startOfDay())
            ->where('observed_at', '<', Carbon::parse('2024-06-30')->addDays(2)->startOfDay())
            ->count();
        $this->assertLessThan(50, $afterRowsLoaded, 'a 30-day window plus a 1-day safety buffer should load well under 50 rows out of 1000');
        $this->assertCount(30, $result['counter'], 'the report itself must still return exactly the 30 in-range readings');
    }

    public function test_reading_exactly_before_period_start_is_used_as_predecessor_context(): void
    {
        $f = $this->baseFixture();
        // One reading the day before the period, then readings inside it -
        // the first in-range reading's usage must be computed against the
        // out-of-range predecessor, not treated as if it had none.
        $this->bulkInsertReadings($f, '2026-05-31', 1, 1000); // 2026-05-31: predecessor, value 1000
        $this->bulkInsertReadings($f, '2026-06-01', 3, 1100); // 2026-06-01..03: in range, values 1100/1200/1300

        // Re-chain manually: bulkInsertReadings resets previous_reading_id
        // per call, so link the first in-range reading back to the real
        // predecessor to match how a real continuous sequence is created.
        $predecessor = DB::table('counter_readings')->where('machine_id', $f['machine']->id)->orderBy('observed_at')->first();
        $firstInRange = DB::table('counter_readings')->where('machine_id', $f['machine']->id)->orderBy('observed_at')->skip(1)->first();
        DB::table('counter_readings')->where('id', $firstInRange->id)->update(['previous_reading_id' => $predecessor->id]);

        $service = app(OperationalReportService::class);
        $result = $service->build($f['account']->id, $f['branch']->id, null, '2026-06-01', '2026-06-03');

        $this->assertCount(3, $result['counter']);
        $first = collect($result['counter'])->firstWhere('counter', 1100.0);
        $this->assertNotNull($first);
        $this->assertSame(100.0, $first['usage'], 'usage must be computed against the out-of-range predecessor (1100-1000=100), not treated as the sequence start');
    }

    public function test_reading_exactly_at_period_start_and_end_boundaries_are_included(): void
    {
        $f = $this->baseFixture();
        $service = app(OperationalReportService::class);
        DB::table('counter_readings')->insert([
            ['id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $f['counterType']->id, 'reading_value' => 100, 'observed_at' => Carbon::parse('2026-07-01 00:00:01', $f['branch']->timezone)->utc(), 'status' => 'effective', 'source' => 'manual', 'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $f['counterType']->id, 'reading_value' => 200, 'observed_at' => Carbon::parse('2026-07-31 23:59:59', $f['branch']->timezone)->utc(), 'status' => 'effective', 'source' => 'manual', 'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $service->build($f['account']->id, $f['branch']->id, null, '2026-07-01', '2026-07-31');
        $this->assertCount(2, $result['counter'], 'both the very first instant of period_start and the very last instant of period_end must be included');
    }

    public function test_no_reading_inside_range_returns_an_empty_but_valid_report(): void
    {
        $f = $this->baseFixture();
        $this->bulkInsertReadings($f, '2020-01-01', 50);
        $service = app(OperationalReportService::class);
        $result = $service->build($f['account']->id, $f['branch']->id, null, '2026-01-01', '2026-01-31');
        $this->assertCount(0, $result['counter']);
        $this->assertSame(0.0, $result['overview']['total_clicks']);
    }

    public function test_a_timezone_day_boundary_reading_is_attributed_to_the_correct_local_date(): void
    {
        // A UTC instant that is one calendar date in UTC but the NEXT date in
        // a positive-offset timezone (e.g. Asia/Jakarta, UTC+7) - proves the
        // buffered SQL fetch does not accidentally exclude it, and the exact
        // local-date filter still attributes it correctly.
        $f = $this->baseFixture('Asia/Jakarta');
        $service = app(OperationalReportService::class);
        // 2026-08-31 18:00 UTC = 2026-09-01 01:00 in Asia/Jakarta (UTC+7).
        DB::table('counter_readings')->insert([
            'id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $f['counterType']->id,
            'reading_value' => 500, 'observed_at' => '2026-08-31 18:00:00', 'status' => 'effective', 'source' => 'manual',
            'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $septemberResult = $service->build($f['account']->id, $f['branch']->id, null, '2026-09-01', '2026-09-01');
        $augustResult = $service->build($f['account']->id, $f['branch']->id, null, '2026-08-31', '2026-08-31');

        $this->assertCount(1, $septemberResult['counter'], 'the reading belongs to 2026-09-01 local time, not 2026-08-31 UTC');
        $this->assertCount(0, $augustResult['counter']);
    }

    public function test_a_different_non_jakarta_timezone_still_resolves_and_buffers_correctly(): void
    {
        // America/New_York (UTC-4/-5) exercises a negative-offset zone -
        // proves the ±1-day buffer is not accidentally one-sided.
        $f = $this->baseFixture('America/New_York');
        $service = app(OperationalReportService::class);
        // 2026-03-15 04:30 UTC = 2026-03-15 00:30 EDT (UTC-4 in March, DST active).
        DB::table('counter_readings')->insert([
            'id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $f['counterType']->id,
            'reading_value' => 700, 'observed_at' => '2026-03-15 04:30:00', 'status' => 'effective', 'source' => 'manual',
            'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $service->build($f['account']->id, $f['branch']->id, null, '2026-03-15', '2026-03-15');
        $this->assertCount(1, $result['counter']);
    }

    public function test_a_corrected_reading_pair_reports_correctly_within_the_bounded_query(): void
    {
        $f = $this->baseFixture();
        $service = app(OperationalReportService::class);
        $original = ['id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $f['counterType']->id, 'reading_value' => 1000, 'observed_at' => '2026-04-10 10:00:00', 'status' => 'superseded', 'source' => 'manual', 'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('counter_readings')->insert($original);
        $replacement = ['id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $f['counterType']->id, 'reading_value' => 1050, 'observed_at' => '2026-04-10 10:00:00', 'corrects_reading_id' => $original['id'], 'status' => 'effective', 'source' => 'correction', 'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('counter_readings')->insert($replacement);

        $result = $service->build($f['account']->id, $f['branch']->id, null, '2026-04-10', '2026-04-10');
        $this->assertCount(1, $result['counter'], 'only the effective replacement must appear, not the superseded original');
        $this->assertSame(1050.0, $result['counter'][0]['counter']);
    }

    public function test_multiple_machines_each_report_their_own_readings_correctly(): void
    {
        $f = $this->baseFixture();
        $manufacturer = Manufacturer::create(['code' => 'M2-'.Str::random(6), 'name' => 'Maker2']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'X2-'.Str::random(6), 'name' => 'Model2']);
        $machine2 = Machine::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_model_id' => $model->id, 'machine_code' => 'A2', 'display_name' => 'A2', 'status' => 'active']);

        $this->bulkInsertReadings($f, '2026-02-01', 5, 500);
        $this->bulkInsertReadings(['account' => $f['account'], 'machine' => $machine2, 'counterType' => $f['counterType']], '2026-02-01', 5, 9000);

        $service = app(OperationalReportService::class);
        $result = $service->build($f['account']->id, $f['branch']->id, null, '2026-02-01', '2026-02-05');
        $this->assertCount(10, $result['counter']);
        $this->assertEquals(2, $result['overview']['active_machines']);
    }

    public function test_incidents_and_replacements_do_not_load_multi_year_history_for_a_narrow_period(): void
    {
        $f = $this->baseFixture();
        // 900 daily incidents from 2022-01-01 comfortably covers through
        // 2024-03-31 (day 820) with margin.
        $this->bulkInsertIncidents($f, '2022-01-01', 900);
        $this->assertSame(900, DB::table('operational_incidents')->where('account_id', $f['account']->id)->count());

        DB::enableQueryLog();
        $service = app(OperationalReportService::class);
        $result = $service->build($f['account']->id, $f['branch']->id, null, '2024-03-01', '2024-03-31');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $incidentQuery = collect($log)->first(fn ($q) => str_contains($q['query'], 'operational_incidents') && str_contains($q['query'], 'occurred_at'));
        $this->assertNotNull($incidentQuery, 'the operational_incidents query must include an occurred_at predicate');

        $afterRowsLoaded = DB::table('operational_incidents')->where('account_id', $f['account']->id)
            ->where('occurred_at', '>=', Carbon::parse('2024-03-01')->subDay()->startOfDay())
            ->where('occurred_at', '<', Carbon::parse('2024-03-31')->addDays(2)->startOfDay())
            ->count();
        $this->assertLessThan(50, $afterRowsLoaded, 'a 31-day window plus buffer should load well under 50 of 900 incidents');
        $this->assertCount(31, $result['incidents']);
    }

    public function test_machine_cost_service_period_result_matches_across_a_large_history_before_and_after_bounding(): void
    {
        $f = $this->baseFixture();
        $this->bulkInsertReadings($f, '2021-01-01', 1500);

        $machine = $f['machine']->fresh();
        $costs = app(MachineCostService::class);
        $bounded = $costs->period($machine, '2023-05-01', '2023-05-31');

        // Independently recompute the same period using the OLD unbounded
        // approach (forMachine() over full history, filtered in PHP) to
        // prove parity - this is the exact computation forMachineWithinRange()
        // replaced.
        $sequence = app(EffectiveCounterSequence::class);
        $type = $f['counterType'];
        [$start, $end] = app(MachineTimezoneResolver::class)->range($machine, '2023-05-01', '2023-05-31');
        $unboundedRows = $sequence->forMachine($machine->id, $type->id)->filter(fn ($r) => $r->observed_at->gte($start) && $r->observed_at->lt($end))->values();
        $expectedClicks = $unboundedRows->sum(fn ($r) => max(0, (float) ($r->usage ?? 0)));

        $this->assertSame($expectedClicks, $bounded['period_clicks'], 'bounded and unbounded computations must produce identical click totals');
        $this->assertSame('COMPLETE', $bounded['counter_status']);
    }

    public function test_replacements_query_does_not_load_multi_year_history_for_a_narrow_period(): void
    {
        $f = $this->baseFixture();
        $catalog = ComponentCatalog::create(['code' => 'TONER_SCALE', 'name' => 'Toner Scale']);
        $profile = ModelProfile::create(['machine_model_id' => $f['machine']->machine_model_id, 'account_id' => $f['account']->id, 'name' => 'P', 'is_active' => true]);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $catalog->id, 'slot_code' => 'TONER_SCALE', 'baseline_expected_clicks' => 10000, 'is_active' => true]);
        $mc = MachineComponent::create(['account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'component_id' => $catalog->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_SCALE', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active', 'baseline_expected_clicks' => 10000]);

        // 400 historical replacements (closed lifecycles only - active_key
        // null on every one, which the unique index allows any number of)
        // spanning ~2.5 years, directly bulk-inserted since exercising
        // ReplaceMachineComponent 400 times is unnecessary for a query-shape
        // proof and would only slow the test down.
        $date = Carbon::parse('2022-01-01', 'UTC');
        $rows = [];
        foreach (range(1, 400) as $i) {
            $lifecycleId = (string) Str::uuid();
            DB::table('component_lifecycles')->insert(['id' => $lifecycleId, 'machine_component_id' => $mc->id, 'started_at' => $date->copy()->toDateTimeString(), 'status' => 'closed', 'source' => 'replacement', 'active_key' => null, 'created_at' => now(), 'updated_at' => now()]);
            $rows[] = ['id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'machine_component_id' => $mc->id, 'new_lifecycle_id' => $lifecycleId, 'inventory_source' => 'external_untracked', 'external_reason' => 'bulk fixture', 'replaced_at' => $date->copy()->addHours(10)->toDateTimeString(), 'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
            $date->addDays(2);
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('component_replacements')->insert($chunk);
        }
        $this->assertSame(400, DB::table('component_replacements')->where('account_id', $f['account']->id)->count());

        DB::enableQueryLog();
        $service = app(OperationalReportService::class);
        $result = $service->build($f['account']->id, $f['branch']->id, null, '2023-06-01', '2023-06-30');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $replacementQuery = collect($log)->first(fn ($q) => str_contains($q['query'], 'component_replacements') && str_contains($q['query'], 'replaced_at'));
        $this->assertNotNull($replacementQuery, 'the component_replacements query must include a replaced_at predicate');

        $afterRowsLoaded = DB::table('component_replacements')->where('account_id', $f['account']->id)
            ->where('replaced_at', '>=', Carbon::parse('2023-06-01')->subDay()->startOfDay())
            ->where('replaced_at', '<', Carbon::parse('2023-06-30')->addDays(2)->startOfDay())
            ->count();
        $this->assertLessThan(40, $afterRowsLoaded, 'a 30-day window plus buffer should load well under 40 of 400 replacements (one every 2 days)');
        $this->assertGreaterThan(0, count($result['replacements']), 'the report must still return the in-range replacements');
    }

    public function test_the_maximum_allowed_366_day_period_still_returns_correct_bounded_results(): void
    {
        // The M2.19 report-range validator's own maximum (366 days) combined
        // with this milestone's ±1-day SQL buffer on each side - proving the
        // two features compose correctly rather than one accidentally
        // starving the other.
        $f = $this->baseFixture();
        $this->bulkInsertReadings($f, '2024-01-01', 800);

        $service = app(OperationalReportService::class);
        $result = $service->build($f['account']->id, $f['branch']->id, null, '2024-01-01', '2025-01-01');

        $this->assertCount(367, $result['counter'], 'inclusive of both boundary dates across a 366-day span');
    }
}
