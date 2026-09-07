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
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\User;
use App\Services\CreateCounterReading;
use App\Services\MachineCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers M2.12L: counter correction/void must target the exact record by
 * immutable ID (never "latest"), must support correcting the effective
 * date/time (not just the value), and the resulting effective sequence must
 * recompute deterministically for Counter History, Machine Cost totals, and
 * Daily Click Trend, all of which read through the same canonical source.
 */
class CounterCorrectionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'CCI', 'name' => 'Counter Integrity', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $owner = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-MAIN-A3-01', 'display_name' => 'C1070', 'status' => 'active']);
        $person = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Operator One', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $person->id, 'branch_id' => $branch->id, 'is_active' => true, 'can_record_counter' => true]);

        $otherAccount = Account::create(['code' => 'CCI2', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta']);
        $otherOwner = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $otherAccount->id, 'user_id' => $otherOwner->id, 'role' => 'owner', 'status' => 'active']);

        $counterTypeId = CounterType::where('code', 'total_impressions')->value('id');

        return compact('account', 'branch', 'owner', 'machine', 'person', 'otherOwner', 'counterTypeId');
    }

    private function record(array $f, float $value, string $observedAt): CounterReading
    {
        return app(CreateCounterReading::class)->execute($f['owner'], $f['machine'], [
            'reading_value' => $value, 'observed_at' => $observedAt, 'operator_person_id' => $f['person']->id,
            'client_request_id' => (string) Str::uuid(),
        ]);
    }

    public function test_a_historical_non_latest_reading_can_now_be_corrected(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-03T13:00:00Z');
        $this->record($f, 1200, '2026-09-04T13:00:00Z');

        $response = $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$r1->id}/correction", [
                'correction_reason' => 'Meter misread on the 3rd', 'replacement_value' => 1050, 'client_request_id' => (string) Str::uuid(),
            ])
            ->assertOk()
            ->assertJsonPath('data.source', 'correction');

        $this->assertEquals(1050, $response->json('data.reading_value'));
        $this->assertSame('superseded', $r1->fresh()->status);
    }

    public function test_voiding_a_historical_non_latest_reading_targets_the_exact_id_not_latest(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-03T13:00:00Z');
        $latest = $this->record($f, 1200, '2026-09-04T13:00:00Z');

        $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$r1->id}/correction", ['correction_reason' => 'Duplicate entry'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $this->assertSame('voided', $r1->fresh()->status);
        $this->assertSame('effective', $latest->fresh()->status, 'Voiding the historical reading must not affect the unrelated latest reading.');
    }

    public function test_two_readings_with_identical_timestamps_are_targeted_deterministically_by_id(): void
    {
        $f = $this->fixture();
        $a = $this->record($f, 1000, '2026-09-04T13:00:00Z');
        $b = CounterReading::create(['account_id' => $f['account']->id, 'machine_id' => $f['machine']->id, 'counter_type_id' => $f['counterTypeId'], 'reading_value' => 1300, 'observed_at' => '2026-09-04T13:00:00Z', 'status' => 'effective', 'source' => 'manual', 'client_request_id' => (string) Str::uuid()]);

        $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$a->id}/correction", ['correction_reason' => 'void A only'])->assertOk();

        $this->assertSame('voided', $a->fresh()->status);
        $this->assertSame('effective', $b->fresh()->status, 'Action on reading A must never mutate reading B, even with an identical timestamp.');
    }

    public function test_effective_datetime_can_be_corrected_independently_of_value(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-03T13:00:00Z');
        $this->record($f, 1200, '2026-09-04T13:00:00Z');

        $response = $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$r1->id}/correction", [
                'correction_reason' => 'Wrong day entered', 'replacement_value' => 1000, 'replacement_observed_at' => '2026-09-02T13:00:00Z', 'client_request_id' => (string) Str::uuid(),
            ])
            ->assertOk();

        $this->assertSame('2026-09-02T13:00:00.000000Z', $response->json('data.observed_at'));
    }

    public function test_correcting_value_and_datetime_together_is_supported(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-03T13:00:00Z');

        $response = $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$r1->id}/correction", [
                'correction_reason' => 'Both value and time were wrong', 'replacement_value' => 1010, 'replacement_observed_at' => '2026-09-02T13:00:00Z', 'client_request_id' => (string) Str::uuid(),
            ])
            ->assertOk();
        $this->assertEquals(1010, $response->json('data.reading_value'));
    }

    public function test_a_correction_that_would_make_the_sequence_regress_is_rejected(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-01T00:00:00Z');
        $this->record($f, 1200, '2026-09-02T00:00:00Z');
        $r3 = $this->record($f, 1500, '2026-09-03T00:00:00Z');

        // Moving r3's value down below r1's neighbour (1200) would make the
        // resulting chronological sequence regress.
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$r3->id}/correction", [
                'correction_reason' => 'bad edit', 'replacement_value' => 1100, 'client_request_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['replacement_value']);

        $this->assertSame('effective', $r1->fresh()->status);
    }

    public function test_moving_a_reading_earlier_reorders_the_effective_sequence_correctly(): void
    {
        $f = $this->fixture();
        $this->record($f, 1000, '2026-09-01T00:00:00Z');
        $middle = $this->record($f, 1200, '2026-09-02T00:00:00Z');
        $this->record($f, 1500, '2026-09-03T00:00:00Z');

        // Move the middle reading to before the first one; its new value must
        // not regress against nothing (it becomes the new first/baseline).
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$middle->id}/correction", [
                'correction_reason' => 'was actually the earliest reading', 'replacement_value' => 900, 'replacement_observed_at' => '2026-08-31T00:00:00Z', 'client_request_id' => (string) Str::uuid(),
            ])
            ->assertOk();

        $period = app(MachineCostService::class)->period($f['machine'], '2026-08-31', '2026-09-03');
        // New chronological order: 08-31=900 (baseline), 09-01=1000 (usage100), 09-03=1500 (usage500). Total = 600.
        $this->assertSame(600.0, $period['total_clicks']);
    }

    public function test_moving_a_reading_later_reorders_the_effective_sequence_correctly(): void
    {
        $f = $this->fixture();
        $first = $this->record($f, 1000, '2026-09-01T00:00:00Z');
        $this->record($f, 1200, '2026-09-02T00:00:00Z');
        $this->record($f, 1500, '2026-09-03T00:00:00Z');

        $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$first->id}/correction", [
                'correction_reason' => 'actually recorded after the 09-03 reading', 'replacement_value' => 1600, 'replacement_observed_at' => '2026-09-04T00:00:00Z', 'client_request_id' => (string) Str::uuid(),
            ])
            ->assertOk();

        $period = app(MachineCostService::class)->period($f['machine'], '2026-09-01', '2026-09-04');
        // New order: 09-02=1200 (baseline), 09-03=1500 (usage 300), 09-04=1600 (usage 100). Total = 400.
        $this->assertSame(400.0, $period['total_clicks']);
    }

    public function test_void_reconnects_the_sequence_so_the_next_readings_usage_uses_the_new_predecessor(): void
    {
        $f = $this->fixture();
        $this->record($f, 1000, '2026-09-01T00:00:00Z');
        $b = $this->record($f, 1200, '2026-09-02T00:00:00Z');
        $this->record($f, 1500, '2026-09-03T00:00:00Z');

        $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$b->id}/correction", ['correction_reason' => 'duplicate reading'])->assertOk();

        $period = app(MachineCostService::class)->period($f['machine'], '2026-09-01', '2026-09-03');
        // B is voided; D's (09-03, 1500) usage must now be against A (1000) = 500, not the voided B.
        $this->assertSame(500.0, $period['total_clicks']);
    }

    public function test_the_documented_production_regression_scenario(): void
    {
        $f = $this->fixture();
        // Observed Production shape: 03/09 20:00=1,444,841; 04/09 20:00=1,449,214
        // (correct); then a second reading mistakenly ALSO logged at 04/09
        // 20:00=1,452,214 (should have been 05/09 08:00); then 05/09
        // 20:00=1,455,850.
        $this->record($f, 1444841, '2026-09-03T13:00:00Z');
        $this->record($f, 1449214, '2026-09-04T13:00:00Z');
        $misdated = $this->record($f, 1452214, '2026-09-04T13:00:01Z');
        $this->record($f, 1455850, '2026-09-05T13:00:00Z');

        $before = app(MachineCostService::class)->period($f['machine'], '2026-09-03', '2026-09-06');
        // Before correction: total = (1449214-1444841)+(1452214-1449214)+(1455850-1452214) = 11009.
        $this->assertSame(11009.0, $before['total_clicks']);

        $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$misdated->id}/correction", [
                'correction_reason' => 'Entered under the wrong day; observed on 05/09 morning',
                'replacement_observed_at' => '2026-09-05T01:00:00Z', 'client_request_id' => (string) Str::uuid(),
            ])
            ->assertOk();

        $after = app(MachineCostService::class)->period($f['machine'], '2026-09-03', '2026-09-06');
        // Chronology is now: 09-03=1444841, 09-04=1449214 (usage 4373), 09-05
        // 01:00=1452214 (usage 3000), 09-05 13:00=1455850 (usage 3636). Total
        // click conservation: the aggregate must be unchanged by a pure
        // re-dating (no value changed) — still 11009 total.
        $this->assertSame(11009.0, $after['total_clicks'], 'TOTAL_CLICK_CONSERVATION');

        $byDate = collect($after['daily_trend'])->keyBy('operational_date');
        $this->assertSame(4373.0, $byDate['2026-09-04']['daily_clicks']);
        $this->assertSame(6636.0, $byDate['2026-09-05']['daily_clicks'], 'DAILY_ATTRIBUTION_AFTER_TIME_CORRECTION');
    }

    public function test_correcting_an_already_voided_reading_is_a_truthful_conflict(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-01T00:00:00Z');
        $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$r1->id}/correction", ['correction_reason' => 'void it'])->assertOk();

        $this->actingAs($f['owner'])
            ->postJson("/api/v1/counter-readings/{$r1->id}/correction", ['correction_reason' => 'try again', 'replacement_value' => 1100, 'client_request_id' => (string) Str::uuid()])
            ->assertStatus(409);
    }

    public function test_unauthorized_cross_account_correction_is_denied(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-01T00:00:00Z');

        $this->actingAs($f['otherOwner'])
            ->postJson("/api/v1/counter-readings/{$r1->id}/correction", ['correction_reason' => 'not my machine', 'replacement_value' => 1 , 'client_request_id' => (string) Str::uuid()])
            ->assertForbidden();
    }

    public function test_repeated_correction_request_with_the_same_client_request_id_is_idempotent(): void
    {
        $f = $this->fixture();
        $r1 = $this->record($f, 1000, '2026-09-01T00:00:00Z');
        $clientRequestId = (string) Str::uuid();
        $payload = ['correction_reason' => 'idempotent retry', 'replacement_value' => 1050, 'replacement_observed_at' => '2026-09-01T05:00:00Z', 'client_request_id' => $clientRequestId];

        $first = $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$r1->id}/correction", $payload)->assertOk();
        $second = $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$r1->id}/correction", $payload)->assertOk();
        $this->assertSame($first->json('data.reading_id'), $second->json('data.reading_id'));
    }

    public function test_counter_history_response_carries_reading_id_and_previous_value_for_exact_targeting(): void
    {
        $f = $this->fixture();
        $this->record($f, 1000, '2026-09-01T00:00:00Z');
        $this->record($f, 1200, '2026-09-02T00:00:00Z');

        $response = $this->actingAs($f['owner'])->getJson("/api/v1/machines/{$f['machine']->id}/counters?per_page=100")->assertOk();
        $rows = $response->json('data.data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('reading_id', $row);
            $this->assertNotNull($row['reading_id']);
        }
        $latest = collect($rows)->first(fn ($row) => (float) $row['reading_value'] === 1200.0);
        $this->assertEquals(1000, $latest['previous_value']);
        $this->assertEquals(200, $latest['usage']);
    }
}
