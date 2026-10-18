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
use Tests\TestCase;

/**
 * Phase 2 - Maintenance workflow & operational schedule: schedule settings API
 * (authorization, branch isolation, audit), the events list scope/enrichment, and
 * the single-event-across-shifts lifecycle example.
 */
class ProductionScheduleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'OTHER', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $second = Branch::create(['account_id' => $account->id, 'code' => 'SECOND', 'name' => 'Second', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $foreignBranch = Branch::create(['account_id' => $foreign->id, 'code' => 'OTHER', 'name' => 'Other', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $model = MachineModel::create(['manufacturer_id' => Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta'])->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $mk = fn (Account $a, Branch $b, string $code) => Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => $code, 'display_name' => $code, 'status' => 'active']);
        $this->f = [
            'account' => $account, 'branch' => $branch, 'second' => $second, 'foreignBranch' => $foreignBranch,
            'machine' => $mk($account, $branch, 'A3-01'), 'secondMachine' => $mk($account, $second, 'A3-02'), 'foreignMachine' => $mk($foreign, $foreignBranch, 'OTHER-01'),
            'owner' => $this->member($account, 'owner'), 'admin' => $this->member($account, 'admin', $branch), 'technician' => $this->member($account, 'technician', $branch),
            'foreignOwner' => $this->member($foreign, 'owner'),
        ];
    }

    private function member(Account $account, string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        if ($branch) {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    private function week(array $byDay = []): array
    {
        return collect(range(0, 6))->map(fn ($d) => $byDay[$d] ?? ['day_of_week' => $d, 'is_operating' => $d >= 1 && $d <= 5, 'start_time' => $d >= 1 && $d <= 5 ? '08:00' : null, 'end_time' => $d >= 1 && $d <= 5 ? '17:00' : null, 'crosses_midnight' => false])->all();
    }

    public function test_branch_schedule_is_empty_not_defaulted_until_configured(): void
    {
        $this->actingAs($this->f['owner'])->getJson("/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_branch_schedule_save_requires_schedule_capability_and_is_audited(): void
    {
        $url = "/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule";
        $this->actingAs($this->f['technician'])->putJson($url, ['days' => $this->week()])->assertForbidden();
        $this->actingAs($this->f['admin'])->putJson($url, ['days' => $this->week()])->assertCreated()->assertJsonCount(7, 'data');
        $this->assertSame(7, BranchProductionSchedule::where('branch_id', $this->f['branch']->id)->count());
        $this->assertDatabaseHas('governance_audit_logs', ['account_id' => $this->f['account']->id, 'action' => 'branch_production_schedule.set', 'actor_user_id' => $this->f['admin']->id]);
    }

    public function test_branch_schedule_replace_does_not_touch_downtime_events(): void
    {
        $event = DowntimeEvent::create(['account_id' => $this->f['account']->id, 'branch_id' => $this->f['branch']->id, 'machine_id' => $this->f['machine']->id, 'cause' => 'jam', 'actual_start_at' => now()->subHour(), 'created_by' => $this->f['owner']->id, 'active_key' => 'active']);
        $before = $event->fresh()->toArray();
        $this->actingAs($this->f['owner'])->putJson("/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule", ['days' => $this->week()])->assertCreated();
        $this->assertEquals($before, $event->fresh()->toArray());
    }

    public function test_schedule_validation_rejects_bad_shapes(): void
    {
        $url = "/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule";
        $owner = $this->actingAs($this->f['owner']);
        $owner->putJson($url, ['days' => array_slice($this->week(), 0, 6)])->assertStatus(422);
        $owner->putJson($url, ['days' => $this->week([1 => ['day_of_week' => 1, 'is_operating' => true, 'start_time' => null, 'end_time' => null]])])->assertStatus(422);
        $owner->putJson($url, ['days' => $this->week([1 => ['day_of_week' => 1, 'is_operating' => true, 'start_time' => '17:00', 'end_time' => '08:00', 'crosses_midnight' => false]])])->assertStatus(422);
        $owner->putJson($url, ['days' => $this->week([1 => ['day_of_week' => 1, 'is_operating' => true, 'start_time' => '08:00', 'end_time' => '08:00', 'crosses_midnight' => true]])])->assertStatus(422);
        $owner->putJson($url, ['days' => $this->week([1 => ['day_of_week' => 1, 'is_operating' => true, 'start_time' => '22:00', 'end_time' => '06:00', 'crosses_midnight' => true]])])->assertCreated();
    }

    public function test_branch_schedule_is_branch_isolated(): void
    {
        $this->actingAs($this->f['admin'])->putJson("/api/v1/operations/branches/{$this->f['second']->id}/production-schedule", ['days' => $this->week()])->assertForbidden();
        $this->actingAs($this->f['foreignOwner'])->putJson("/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule", ['days' => $this->week()])->assertForbidden();
        $this->actingAs($this->f['foreignOwner'])->getJson("/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule")->assertForbidden();
    }

    public function test_machine_override_takes_precedence_then_can_be_disabled_to_inherit_the_branch(): void
    {
        $branchUrl = "/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule";
        $url = "/api/v1/operations/machines/{$this->f['machine']->id}/production-schedule-override";
        $this->actingAs($this->f['owner'])->putJson($branchUrl, ['days' => $this->week()])->assertCreated();
        $this->actingAs($this->f['technician'])->putJson($url, ['days' => $this->week()])->assertForbidden();
        $this->actingAs($this->f['technician'])->deleteJson($url)->assertForbidden();

        $this->actingAs($this->f['admin'])->putJson($url, ['days' => $this->week([1 => ['day_of_week' => 1, 'is_operating' => true, 'start_time' => '06:00', 'end_time' => '14:00', 'crosses_midnight' => false]])])->assertCreated();
        $resolver = app(\App\Services\ProductionScheduleResolver::class);
        $this->assertSame('06:00', substr($resolver->dayRule($this->f['machine']->fresh(), 1)['start_time'], 0, 5));

        $this->actingAs($this->f['admin'])->deleteJson($url)->assertOk()->assertJsonPath('data.removed', 7);
        $this->assertSame(0, MachineProductionScheduleOverride::where('machine_id', $this->f['machine']->id)->count());
        $this->assertSame('08:00', substr($resolver->dayRule($this->f['machine']->fresh(), 1)['start_time'], 0, 5));
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'machine_production_schedule_override.removed', 'account_id' => $this->f['account']->id]);
    }

    public function test_machine_override_is_branch_scoped_for_restricted_members(): void
    {
        $url = "/api/v1/operations/machines/{$this->f['secondMachine']->id}/production-schedule-override";
        $this->actingAs($this->f['admin'])->putJson($url, ['days' => $this->week()])->assertForbidden();
        $this->actingAs($this->f['admin'])->deleteJson($url)->assertForbidden();
        $this->actingAs($this->f['admin'])->getJson($url)->assertForbidden();
    }

    public function test_holiday_and_overtime_exceptions_create_list_delete_with_audit(): void
    {
        $url = "/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule/exceptions";
        $this->actingAs($this->f['technician'])->postJson($url, ['exception_date' => '2026-10-20', 'type' => 'holiday', 'is_operating' => false])->assertForbidden();

        $holiday = $this->actingAs($this->f['admin'])->postJson($url, ['exception_date' => '2026-10-20', 'type' => 'holiday', 'is_operating' => false, 'note' => 'Libur'])->assertCreated()->json('data');
        $this->actingAs($this->f['admin'])->postJson($url, ['exception_date' => '2026-10-24', 'type' => 'overtime', 'is_operating' => true])->assertStatus(422);
        $this->actingAs($this->f['admin'])->postJson($url, ['exception_date' => '2026-10-24', 'type' => 'overtime', 'is_operating' => true, 'override_start_time' => '18:00', 'override_end_time' => '22:00'])->assertCreated();
        $this->actingAs($this->f['admin'])->postJson($url, ['exception_date' => '2026-10-20', 'type' => 'closed', 'is_operating' => false])->assertStatus(409);
        $this->actingAs($this->f['admin'])->postJson($url, ['exception_date' => '2026-10-25', 'type' => 'nonsense', 'is_operating' => false])->assertStatus(422);
        $this->actingAs($this->f['admin'])->postJson($url, ['exception_date' => '2026-10-26', 'type' => 'extra_shift', 'is_operating' => true, 'override_start_time' => '20:00', 'override_end_time' => '10:00'])->assertStatus(422);

        $this->actingAs($this->f['admin'])->getJson("$url?year=2026&month=10")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->f['admin'])->getJson("$url?year=2026&month=11")->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->f['technician'])->deleteJson("/api/v1/operations/production-schedule/exceptions/{$holiday['id']}")->assertForbidden();
        $this->actingAs($this->f['admin'])->deleteJson("/api/v1/operations/production-schedule/exceptions/{$holiday['id']}")->assertOk();
        $this->assertNull(ProductionScheduleException::find($holiday['id']));
        foreach (['production_schedule_exception.created', 'production_schedule_exception.deleted'] as $action) {
            $this->assertDatabaseHas('governance_audit_logs', ['action' => $action, 'account_id' => $this->f['account']->id]);
        }
    }

    public function test_machine_exception_is_not_broadened_to_the_branch_and_must_belong_to_the_branch(): void
    {
        $url = "/api/v1/operations/branches/{$this->f['branch']->id}/production-schedule/exceptions";
        foreach (range(0, 6) as $d) {
            BranchProductionSchedule::create(['account_id' => $this->f['account']->id, 'branch_id' => $this->f['branch']->id, 'day_of_week' => $d, 'is_operating' => true, 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'crosses_midnight' => false]);
        }
        $other = Machine::create(['account_id' => $this->f['account']->id, 'branch_id' => $this->f['branch']->id, 'machine_model_id' => $this->f['machine']->machine_model_id, 'machine_code' => 'A3-09', 'display_name' => 'A3-09', 'status' => 'active']);
        $this->actingAs($this->f['admin'])->postJson($url, ['machine_id' => $this->f['machine']->id, 'exception_date' => '2026-10-21', 'type' => 'closed', 'is_operating' => false])->assertCreated();
        $this->actingAs($this->f['admin'])->postJson($url, ['machine_id' => $this->f['secondMachine']->id, 'exception_date' => '2026-10-21', 'type' => 'closed', 'is_operating' => false])->assertNotFound();

        $resolver = app(\App\Services\ProductionScheduleResolver::class);
        $date = CarbonImmutable::parse('2026-10-21', 'Asia/Jakarta');
        $this->assertSame([], $resolver->intervalsForDate($this->f['machine']->fresh(), $date, 'Asia/Jakarta'));
        $this->assertCount(1, $resolver->intervalsForDate($other->fresh(), $date, 'Asia/Jakarta'));
    }

    public function test_one_event_across_shifts_counts_18h_elapsed_but_only_3h_production_downtime(): void
    {
        foreach (range(0, 6) as $d) {
            BranchProductionSchedule::create(['account_id' => $this->f['account']->id, 'branch_id' => $this->f['branch']->id, 'day_of_week' => $d, 'is_operating' => true, 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'crosses_midnight' => false]);
        }
        // Monday 16:00 -> Tuesday 10:00 Asia/Jakarta, one event, never auto-closed at 17:00.
        $event = DowntimeEvent::create(['account_id' => $this->f['account']->id, 'branch_id' => $this->f['branch']->id, 'machine_id' => $this->f['machine']->id, 'cause' => 'mechanical', 'actual_start_at' => CarbonImmutable::parse('2026-10-12 16:00', 'Asia/Jakarta')->utc(), 'actual_end_at' => CarbonImmutable::parse('2026-10-13 10:00', 'Asia/Jakarta')->utc(), 'created_by' => $this->f['owner']->id]);
        $row = collect($this->actingAs($this->f['owner'])->getJson("/api/v1/downtime/events?machine_id={$this->f['machine']->id}")->assertOk()->json('data.data'))->firstWhere('id', $event->id);
        $this->assertSame(18 * 60, $row['elapsed_minutes']);
        $this->assertSame(3 * 60, $row['production_downtime_minutes']);
        $this->assertSame(1, DowntimeEvent::where('machine_id', $this->f['machine']->id)->count());
    }

    public function test_events_list_reports_null_production_downtime_when_unconfigured(): void
    {
        DowntimeEvent::create(['account_id' => $this->f['account']->id, 'branch_id' => $this->f['branch']->id, 'machine_id' => $this->f['machine']->id, 'cause' => 'jam', 'actual_start_at' => now()->subHours(2), 'actual_end_at' => now()->subHour(), 'created_by' => $this->f['owner']->id]);
        $row = $this->actingAs($this->f['owner'])->getJson('/api/v1/downtime/events')->assertOk()->json('data.data.0');
        $this->assertNull($row['production_downtime_minutes']);
        $this->assertNotNull($row['elapsed_minutes']);
    }

    public function test_events_list_is_branch_and_tenant_scoped(): void
    {
        foreach ([[$this->f['machine'], $this->f['branch']], [$this->f['secondMachine'], $this->f['second']], [$this->f['foreignMachine'], $this->f['foreignBranch']]] as [$machine, $branch]) {
            DowntimeEvent::create(['account_id' => $machine->account_id, 'branch_id' => $branch->id, 'machine_id' => $machine->id, 'cause' => 'jam', 'actual_start_at' => now()->subHour(), 'created_by' => $this->f['owner']->id, 'active_key' => 'active']);
        }
        $ids = fn ($u) => collect($this->actingAs($u)->getJson('/api/v1/downtime/events')->assertOk()->json('data.data'))->pluck('machine_id')->sort()->values()->all();
        $this->assertSame(collect([(string) $this->f['machine']->id, (string) $this->f['secondMachine']->id])->sort()->values()->all(), collect($ids($this->f['owner']))->sort()->values()->all());
        $this->assertSame([(string) $this->f['machine']->id], $ids($this->f['admin']));
        $this->assertSame([(string) $this->f['foreignMachine']->id], $ids($this->f['foreignOwner']));
    }

    public function test_events_list_filters_by_period_status_and_includes_latest_note(): void
    {
        $f = $this->f;
        $old = DowntimeEvent::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id, 'cause' => 'jam', 'actual_start_at' => CarbonImmutable::parse('2026-08-05 09:00', 'Asia/Jakarta')->utc(), 'actual_end_at' => CarbonImmutable::parse('2026-08-05 10:00', 'Asia/Jakarta')->utc(), 'created_by' => $f['owner']->id]);
        $active = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'mechanical'])->assertCreated()->json('data');
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$active['id']}/notes", ['note' => 'Ganti roller'])->assertCreated();

        $aug = collect($this->actingAs($f['owner'])->getJson('/api/v1/downtime/events?period_start=2026-08-01&period_end=2026-08-31')->json('data.data'))->pluck('id')->all();
        $this->assertSame([$old->id], $aug);
        $rows = $this->actingAs($f['owner'])->getJson('/api/v1/downtime/events?status=active')->json('data.data');
        $this->assertSame($active['id'], $rows[0]['id']);
        $this->assertSame('Ganti roller', $rows[0]['latest_note']['note']);
        $this->actingAs($f['owner'])->getJson('/api/v1/downtime/events?period_start=2026-09-01&period_end=2026-08-01')->assertStatus(422);
    }

    public function test_overview_attention_carries_branch_and_latest_note_and_uses_machine_timezone_for_the_period(): void
    {
        $f = $this->f;
        foreach (range(0, 6) as $d) {
            BranchProductionSchedule::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'day_of_week' => $d, 'is_operating' => true, 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'crosses_midnight' => false]);
        }
        // 2026-09-01 00:30 Jakarta == 2026-08-31 17:30 UTC: it belongs to September for a Jakarta machine.
        DowntimeEvent::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id, 'cause' => 'jam', 'actual_start_at' => CarbonImmutable::parse('2026-09-01 00:30', 'Asia/Jakarta')->utc(), 'actual_end_at' => CarbonImmutable::parse('2026-09-01 01:30', 'Asia/Jakarta')->utc(), 'created_by' => $f['owner']->id]);
        $active = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'mechanical'])->json('data');
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$active['id']}/notes", ['note' => 'Menunggu sparepart'])->assertCreated();

        $aug = $this->actingAs($f['owner'])->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=2026-08-01&period_end=2026-08-31")->json('data');
        $sep = $this->actingAs($f['owner'])->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=2026-09-01&period_end=2026-09-30")->json('data');
        $this->assertSame(0, $aug['event_count']);
        $this->assertSame(1, $sep['event_count']);
        $this->assertSame(60, $sep['production_downtime_minutes']);

        $now = $this->actingAs($f['owner'])->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start=".now()->format('Y-m-01').'&period_end='.now()->format('Y-m-t'))->json('data.attention.0');
        $this->assertSame('Main', $now['branch_name']);
        $this->assertSame('Menunggu sparepart', $now['latest_note']['note']);
    }
}
