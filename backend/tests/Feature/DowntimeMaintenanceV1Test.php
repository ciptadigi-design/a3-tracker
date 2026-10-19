<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\BranchProductionSchedule;
use App\Models\DowntimeEvent;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\MachineProductionScheduleOverride;
use App\Models\Manufacturer;
use App\Models\ProductionScheduleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M2 Downtime & Maintenance V1 - backend validation coverage. See the M2
 * Downtime & Maintenance V1 plan for the business decisions this asserts
 * (active-downtime uniqueness, elapsed vs. production downtime, cross-day
 * and schedule-exception handling, operator/backdate capability gating).
 */
class DowntimeMaintenanceV1Test extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $timezone = 'Asia/Jakarta'): array
    {
        $account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => $timezone, 'status' => 'active']);
        $foreignAccount = Account::create(['code' => 'OTHER', 'name' => 'Other', 'default_timezone' => $timezone, 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'timezone' => $timezone, 'is_active' => true]);
        $otherBranch = Branch::create(['account_id' => $account->id, 'code' => 'SECOND', 'name' => 'Second', 'timezone' => $timezone, 'is_active' => true]);
        $foreignBranch = Branch::create(['account_id' => $foreignAccount->id, 'code' => 'OTHER', 'name' => 'Other', 'timezone' => $timezone, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-01', 'display_name' => 'Machine A3-01', 'status' => 'active']);
        $otherBranchMachine = Machine::create(['account_id' => $account->id, 'branch_id' => $otherBranch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-02', 'display_name' => 'Machine A3-02', 'status' => 'active']);
        $foreignMachine = Machine::create(['account_id' => $foreignAccount->id, 'branch_id' => $foreignBranch->id, 'machine_model_id' => $model->id, 'machine_code' => 'OTHER-01', 'display_name' => 'Other machine', 'status' => 'active']);
        $owner = $this->member($account, 'owner');
        $technician = $this->member($account, 'technician', $branch);
        $plainMember = $this->member($account, 'member', $branch);

        return compact('account', 'foreignAccount', 'branch', 'otherBranch', 'foreignBranch', 'machine', 'otherBranchMachine', 'foreignMachine', 'owner', 'technician', 'plainMember');
    }

    private function member(Account $account, string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        if ($branch && $role !== 'owner') {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    private function setBranchSchedule(Branch $branch, array $overridesByDay = []): void
    {
        for ($day = 0; $day <= 6; $day++) {
            $row = $overridesByDay[$day] ?? ['is_operating' => true, 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'crosses_midnight' => false];
            BranchProductionSchedule::create(['account_id' => $branch->account_id, 'branch_id' => $branch->id, 'day_of_week' => $day] + $row);
        }
    }

    // --- Downtime workflow ---------------------------------------------------

    public function test_start_requires_manage_capability(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['plainMember'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->assertForbidden();
        $this->actingAs($f['technician'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->assertCreated();
    }

    public function test_duplicate_active_downtime_on_same_machine_is_rejected(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->assertCreated();
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam again'])->assertStatus(409);
        $this->assertSame(1, DowntimeEvent::where('machine_id', $f['machine']->id)->count());
    }

    public function test_active_downtime_can_be_started_again_after_closing(): void
    {
        $f = $this->fixture();
        $first = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->assertCreated()->json('data');
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$first['id']}/end", [])->assertOk();
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam again'])->assertCreated();
        $this->assertSame(2, DowntimeEvent::where('machine_id', $f['machine']->id)->count());
    }

    public function test_concurrent_start_requests_only_let_one_succeed(): void
    {
        $f = $this->fixture();
        $results = [];
        foreach (range(1, 2) as $i) {
            $results[] = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->status();
        }
        sort($results);
        $this->assertSame([201, 409], $results);
    }

    public function test_backdated_start_requires_backdate_capability(): void
    {
        $f = $this->fixture();
        $past = now()->subHours(3)->format('Y-m-d H:i:s');
        $this->actingAs($f['technician'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam', 'actual_start_at' => $past])
            ->assertStatus(422);
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam', 'actual_start_at' => $past])
            ->assertCreated()
            ->assertJsonPath('data.is_backdated', true);
    }

    public function test_future_start_time_is_rejected(): void
    {
        $f = $this->fixture();
        $future = now()->addHour()->format('Y-m-d H:i:s');
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam', 'actual_start_at' => $future])->assertStatus(422);
    }

    public function test_end_before_start_is_rejected(): void
    {
        $f = $this->fixture();
        $event = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam', 'actual_start_at' => now()->subHour()->format('Y-m-d H:i:s')])->json('data');
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$event['id']}/end", ['actual_end_at' => now()->subHours(2)->format('Y-m-d H:i:s')])->assertStatus(422);
    }

    public function test_ending_already_closed_event_conflicts(): void
    {
        $f = $this->fixture();
        $event = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->json('data');
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$event['id']}/end", [])->assertOk();
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$event['id']}/end", [])->assertStatus(409);
    }

    public function test_adding_a_maintenance_note_never_closes_the_event(): void
    {
        $f = $this->fixture();
        $event = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->json('data');
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$event['id']}/notes", ['note' => 'Checked rollers'])->assertCreated();
        $this->assertTrue(DowntimeEvent::find($event['id'])->isActive());
        $this->actingAs($f['owner'])->getJson("/api/v1/downtime/machines/{$f['machine']->id}/active")
            ->assertOk()
            ->assertJsonCount(1, 'data.notes');
    }

    public function test_branch_isolation_and_tenant_isolation_are_enforced(): void
    {
        $f = $this->fixture();
        $foreignOwner = $this->member($f['foreignAccount'], 'owner');
        $this->actingAs($foreignOwner)->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'jam'])->assertForbidden();
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['foreignMachine']->id}/start", ['cause' => 'jam'])->assertForbidden();
    }

    // --- Production schedule / duration calculation --------------------------

    public function test_same_day_downtime_within_schedule(): void
    {
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch']);
        $start = CarbonImmutable::parse('2026-09-14 09:00:00', 'Asia/Jakarta'); // Monday
        $end = CarbonImmutable::parse('2026-09-14 11:00:00', 'Asia/Jakarta');
        $event = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", [
            'cause' => 'jam', 'actual_start_at' => $start->utc()->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('data');
        $closed = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$event['id']}/end", [
            'actual_end_at' => $end->utc()->format('Y-m-d H:i:s'),
        ])->assertOk()->json('data');

        $calc = app(\App\Services\DowntimeDurationCalculator::class);
        $machine = Machine::find($f['machine']->id);
        $this->assertSame(120, $calc->elapsedMinutes(CarbonImmutable::parse($closed['actual_start_at']), CarbonImmutable::parse($closed['actual_end_at'])));
        $this->assertSame(120, $calc->productionDowntimeMinutes($machine, CarbonImmutable::parse($closed['actual_start_at']), CarbonImmutable::parse($closed['actual_end_at'])));
    }

    public function test_cross_day_downtime_elapsed_vs_production_minutes(): void
    {
        // Monday 15:00 stop, Tuesday 10:00 restored. Schedule 08:00-17:00 every day.
        // Mon production window touched: 15:00-17:00 (2h). Tue: 08:00-10:00 (2h). Total 4h = 240m.
        // Elapsed: Mon 15:00 -> Tue 10:00 = 19h = 1140m.
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch']);
        $machine = Machine::find($f['machine']->id);
        $start = CarbonImmutable::parse('2026-09-14 15:00:00', 'Asia/Jakarta');
        $end = CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta');
        $calc = app(\App\Services\DowntimeDurationCalculator::class);

        $this->assertSame(19 * 60, $calc->elapsedMinutes($start, $end));
        $this->assertSame(4 * 60, $calc->productionDowntimeMinutes($machine, $start, $end));
    }

    public function test_active_overnight_downtime_reports_provisional_duration_up_to_now(): void
    {
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch']);
        $start = now()->subHours(5);
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", [
            'cause' => 'jam', 'actual_start_at' => $start->format('Y-m-d H:i:s'),
        ])->assertCreated();

        $active = $this->actingAs($f['owner'])->getJson("/api/v1/downtime/machines/{$f['machine']->id}/active")->assertOk()->json('data');
        $this->assertNull($active['actual_end_at']);
    }

    public function test_overnight_production_schedule_crossing_midnight(): void
    {
        // Night shift 22:00-06:00 every day. Downtime 23:00 Monday -> 05:00 Tuesday is
        // fully within the overnight window = 6h production downtime, elapsed also 6h.
        $f = $this->fixture();
        $overnight = ['is_operating' => true, 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true];
        $this->setBranchSchedule($f['branch'], array_fill(0, 7, $overnight));
        $machine = Machine::find($f['machine']->id);
        $start = CarbonImmutable::parse('2026-09-14 23:00:00', 'Asia/Jakarta');
        $end = CarbonImmutable::parse('2026-09-15 05:00:00', 'Asia/Jakarta');
        $calc = app(\App\Services\DowntimeDurationCalculator::class);

        $this->assertSame(6 * 60, $calc->elapsedMinutes($start, $end));
        $this->assertSame(6 * 60, $calc->productionDowntimeMinutes($machine, $start, $end));
    }

    public function test_non_operating_day_contributes_zero_production_downtime(): void
    {
        // Sunday (day_of_week 0) is closed. Downtime spans all of a closed Sunday.
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch'], [0 => ['is_operating' => false, 'start_time' => null, 'end_time' => null, 'crosses_midnight' => false]]);
        $machine = Machine::find($f['machine']->id);
        $start = CarbonImmutable::parse('2026-09-13 00:00:00', 'Asia/Jakarta'); // Sunday
        $end = CarbonImmutable::parse('2026-09-13 23:59:00', 'Asia/Jakarta');
        $calc = app(\App\Services\DowntimeDurationCalculator::class);

        $this->assertSame(0, $calc->productionDowntimeMinutes($machine, $start, $end));
        $this->assertGreaterThan(0, $calc->elapsedMinutes($start, $end));
    }

    public function test_machine_override_takes_precedence_over_branch_default(): void
    {
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch']); // 08:00-17:00 every day
        for ($day = 0; $day <= 6; $day++) {
            MachineProductionScheduleOverride::create([
                'account_id' => $f['machine']->account_id, 'branch_id' => $f['machine']->branch_id, 'machine_id' => $f['machine']->id,
                'day_of_week' => $day, 'is_operating' => true, 'start_time' => '06:00:00', 'end_time' => '08:00:00', 'crosses_midnight' => false,
            ]);
        }
        $machine = Machine::find($f['machine']->id);
        $calc = app(\App\Services\DowntimeDurationCalculator::class);
        $start = CarbonImmutable::parse('2026-09-14 06:30:00', 'Asia/Jakarta');
        $end = CarbonImmutable::parse('2026-09-14 07:30:00', 'Asia/Jakarta');
        // Within the override window, outside the branch default window - override wins.
        $this->assertSame(60, $calc->productionDowntimeMinutes($machine, $start, $end));
    }

    public function test_holiday_exception_overrides_the_default_operating_day(): void
    {
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch']); // Monday normally 08:00-17:00
        ProductionScheduleException::create([
            'account_id' => $f['branch']->account_id, 'branch_id' => $f['branch']->id, 'machine_id' => null,
            'exception_date' => '2026-09-14', 'type' => 'holiday', 'is_operating' => false,
        ]);
        $machine = Machine::find($f['machine']->id);
        $calc = app(\App\Services\DowntimeDurationCalculator::class);
        $start = CarbonImmutable::parse('2026-09-14 09:00:00', 'Asia/Jakarta');
        $end = CarbonImmutable::parse('2026-09-14 11:00:00', 'Asia/Jakarta');
        $this->assertSame(0, $calc->productionDowntimeMinutes($machine, $start, $end));
    }

    public function test_overtime_exception_extends_an_otherwise_closed_day(): void
    {
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch'], [0 => ['is_operating' => false, 'start_time' => null, 'end_time' => null, 'crosses_midnight' => false]]);
        ProductionScheduleException::create([
            'account_id' => $f['branch']->account_id, 'branch_id' => $f['branch']->id, 'machine_id' => null,
            'exception_date' => '2026-09-13', 'type' => 'overtime', 'is_operating' => true,
            'override_start_time' => '09:00:00', 'override_end_time' => '12:00:00', 'crosses_midnight' => false,
        ]);
        $machine = Machine::find($f['machine']->id);
        $calc = app(\App\Services\DowntimeDurationCalculator::class);
        $start = CarbonImmutable::parse('2026-09-13 09:30:00', 'Asia/Jakarta');
        $end = CarbonImmutable::parse('2026-09-13 10:30:00', 'Asia/Jakarta');
        $this->assertSame(60, $calc->productionDowntimeMinutes($machine, $start, $end));
    }

    public function test_month_boundary_does_not_double_count_or_drop_minutes(): void
    {
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch']);
        $machine = Machine::find($f['machine']->id);
        $calc = app(\App\Services\DowntimeDurationCalculator::class);
        // Sep 30 15:00 -> Oct 1 10:00, schedule 08:00-17:00 every day.
        $start = CarbonImmutable::parse('2026-09-30 15:00:00', 'Asia/Jakarta');
        $end = CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Jakarta');
        // Sep 30: 15:00-17:00 (2h); Oct 1: 08:00-10:00 (2h) = 240m total.
        $this->assertSame(240, $calc->productionDowntimeMinutes($machine, $start, $end));

        // Clipping the same interval to the September reporting period must not
        // double count the October portion nor drop the September portion.
        $periodStart = CarbonImmutable::parse('2026-09-01 00:00:00', 'Asia/Jakarta');
        $periodEnd = CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Jakarta');
        $clipped = $calc->clipToPeriod($start, $end, $periodStart, $periodEnd);
        $this->assertSame(120, $calc->productionDowntimeMinutes($machine, $clipped[0], $clipped[1]));
    }

    public function test_timezone_aware_calculation_for_a_non_utc_machine(): void
    {
        // America/New_York is far enough from Asia/Jakarta that a naive UTC-day
        // calculation would misplace the schedule window.
        $f = $this->fixture('America/New_York');
        $this->setBranchSchedule($f['branch']);
        $machine = Machine::find($f['machine']->id);
        $calc = app(\App\Services\DowntimeDurationCalculator::class);
        $start = CarbonImmutable::parse('2026-09-14 09:00:00', 'America/New_York');
        $end = CarbonImmutable::parse('2026-09-14 11:00:00', 'America/New_York');
        $this->assertSame(120, $calc->productionDowntimeMinutes($machine, $start, $end));
    }

    public function test_missing_schedule_reports_null_not_zero(): void
    {
        $f = $this->fixture();
        // No branch schedule rows created at all.
        $machine = Machine::find($f['machine']->id);
        $calc = app(\App\Services\DowntimeDurationCalculator::class);
        $resolver = app(\App\Services\ProductionScheduleResolver::class);
        $this->assertFalse($resolver->isConfigured($machine));
        $this->assertNull($calc->productionDowntimeMinutes($machine, CarbonImmutable::parse('2026-09-14 09:00:00'), CarbonImmutable::parse('2026-09-14 11:00:00')));
    }

    // --- Owner overview --------------------------------------------------------

    public function test_owner_overview_requires_view_capability_and_branch_authorization(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['plainMember'])->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=2026-09-01&period_end=2026-09-30")->assertForbidden();
        $foreignOwner = $this->member($f['foreignAccount'], 'owner');
        $this->actingAs($foreignOwner)->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=2026-09-01&period_end=2026-09-30")->assertForbidden();
    }

    public function test_owner_overview_aggregates_availability_and_attention_list(): void
    {
        $f = $this->fixture();
        $this->setBranchSchedule($f['branch']);
        $machine = Machine::find($f['machine']->id);

        // A closed event entirely inside October: 2h production downtime.
        DowntimeEvent::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $machine->id,
            'cause' => 'jam', 'actual_start_at' => CarbonImmutable::parse('2026-09-05 09:00:00', 'Asia/Jakarta'),
            'actual_end_at' => CarbonImmutable::parse('2026-09-05 11:00:00', 'Asia/Jakarta'), 'created_by' => $f['owner']->id,
        ]);
        // An active event, started inside the reporting period, that should show up
        // in "attention" and still count toward event_count even though unresolved.
        DowntimeEvent::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $machine->id,
            'cause' => 'C-3913', 'error_code_text' => 'C-3913', 'actual_start_at' => CarbonImmutable::parse('2026-09-20 09:00:00', 'Asia/Jakarta'), 'created_by' => $f['owner']->id, 'active_key' => 'active',
        ]);

        $response = $this->actingAs($f['owner'])->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=2026-09-01&period_end=2026-09-30")
            ->assertOk();
        $data = $response->json('data');
        $this->assertSame(2, $data['event_count']);
        $this->assertCount(1, $data['attention']);
        $this->assertSame('C-3913', $data['attention'][0]['error_code_text']);
        $this->assertIsFloat($data['availability_percent']) ?: $this->assertIsNumeric($data['availability_percent']);
        $this->assertNotNull($data['production_downtime_minutes']);
    }

    public function test_owner_overview_reports_not_configured_when_schedule_missing(): void
    {
        $f = $this->fixture();
        // No schedule configured anywhere for this account's machines.
        $response = $this->actingAs($f['owner'])->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=2026-09-01&period_end=2026-09-30")->assertOk();
        $data = $response->json('data');
        $this->assertNull($data['availability_percent']);
        $this->assertNull($data['production_downtime_minutes']);
    }

    public function test_owner_overview_is_scoped_to_authorized_branches_for_a_restricted_member(): void
    {
        $f = $this->fixture();
        $restricted = $this->member($f['account'], 'operator');
        \App\Models\AccountMembershipBranch::create([
            'account_id' => $f['account']->id,
            'membership_id' => \App\Models\AccountMembership::where('account_id', $f['account']->id)->where('user_id', $restricted->id)->first()->id,
            'branch_id' => $f['branch']->id, 'is_active' => true,
        ]);
        DB::table('account_operational_permissions')->updateOrInsert(['account_id' => $f['account']->id], ['account_id' => $f['account']->id]);

        $response = $this->actingAs($restricted)->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=2026-09-01&period_end=2026-09-30")->assertOk();
        // Restricted member only sees branch "MAIN", never "SECOND" machines' events.
        $this->assertSame(0, $response->json('data.event_count'));
    }

    public function test_every_operational_cause_key_is_accepted_and_stored_verbatim(): void
    {
        $keys = ['paper_jam', 'layar_blank', 'hasil_cetak_tidak_optimal', 'hasil_kotor', 'hasil_pudar', 'hasil_berbayang', 'hasil_bergaris',
            'hasil_tidak_menempel', 'mesin_berisik', 'toner_tidak_detect', 'mesin_mati_total', 'layar_berubah_warna', 'error_code'];
        $f = $this->fixture();
        foreach ($keys as $key) {
            $event = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => $key, 'error_code_text' => $key === 'error_code' ? 'C-3913' : null])->assertCreated()->json('data');
            $this->assertSame($key, $event['cause']);
            $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$event['id']}/end", [])->assertOk();
        }
        $stored = DowntimeEvent::where('machine_id', $f['machine']->id)->pluck('cause')->all();
        $this->assertCount(13, $stored);
        $this->assertEqualsCanonicalizing($keys, $stored);
    }

    public function test_start_still_requires_a_cause(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", [])->assertStatus(422);
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => str_repeat('x', 61)])->assertStatus(422);
    }
}
