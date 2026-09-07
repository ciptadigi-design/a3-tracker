<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\MachineSellingPrice;
use App\Models\Manufacturer;
use App\Models\OperationalIncident;
use App\Models\User;
use App\Services\MachineCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.12L.1: ports the Supabase oracle (get_machine_economics_period in
 * supabase/migrations/20260828001200_machine_selling_price_revenue_contribution.sql,
 * verified against supabase/tests/database/026_selling_price_revenue_contribution.test.sql)
 * onto MachineCostService::period(). Several fixtures/assertions here are the
 * exact numeric scenario from that pgTAP test, so this suite is a direct
 * parity check against the reference, not an invented contract.
 */
class MachineCostBusinessProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'MEC', 'name' => 'Machine Economics', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'JKT', 'name' => 'Jakarta', 'timezone' => 'Asia/Jakarta']);
        $owner = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'REV-1', 'display_name' => 'Revenue Complete', 'status' => 'active']);
        $counterTypeId = CounterType::where('code', 'total_impressions')->value('id');

        return compact('account', 'branch', 'owner', 'machine', 'counterTypeId');
    }

    private function reading(array $f, Machine $machine, float $value, string $observedAt, string $status = 'effective', ?string $previousId = null): CounterReading
    {
        return CounterReading::create([
            'account_id' => $f['account']->id, 'machine_id' => $machine->id, 'counter_type_id' => $f['counterTypeId'],
            'reading_value' => $value, 'observed_at' => $observedAt, 'status' => $status, 'source' => 'manual',
            'previous_reading_id' => $previousId, 'client_request_id' => (string) Str::uuid(),
        ]);
    }

    private function price(array $f, Machine $machine, float $price, string $effectiveFrom, string $status = 'posted'): MachineSellingPrice
    {
        return MachineSellingPrice::create([
            'account_id' => $f['account']->id, 'machine_id' => $machine->id, 'price_per_click' => $price,
            'effective_from' => $effectiveFrom, 'status' => $status, 'client_request_id' => (string) Str::uuid(),
            'created_by' => $f['owner']->id,
        ]);
    }

    private function period(Machine $machine, string $from, string $to): array
    {
        return app(MachineCostService::class)->period($machine, $from, $to);
    }

    public function test_oracle_scenario_two_historical_prices_reconcile_exactly(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 800, '2026-08-01T00:00:00+07:00');
        $this->price($f, $m, 850, '2026-08-16T00:00:00+07:00');

        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $r1 = $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);
        $r2 = $this->reading($f, $m, 1300, '2026-08-20T10:00:00+07:00', 'effective', $r1->id);
        // Superseded (does not contribute) and its correction (effective, contributes 150).
        $superseded = $this->reading($f, $m, 1400, '2026-08-25T10:00:00+07:00', 'superseded', $r2->id);
        $r3 = $this->reading($f, $m, 1450, '2026-08-25T10:00:00+07:00', 'effective', $r2->id);
        // Voided (does not contribute).
        $voided = $this->reading($f, $m, 1500, '2026-08-26T10:00:00+07:00', 'voided', $r3->id);
        $r4 = $this->reading($f, $m, 1500, '2026-08-27T10:00:00+07:00', 'effective', $r3->id);

        // Machine-attributed assessed waste (20,000) participates in Standard
        // cost; an unpriced/unknown-cost incident (assessed_loss=0) makes the
        // contribution status PARTIAL_COST rather than COMPLETE.
        OperationalIncident::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $m->id, 'occurred_at' => '2026-08-12T10:00:00+07:00', 'category' => 'kualitas', 'incident_type' => 'human', 'status' => 'open', 'material_loss' => 20000, 'service_loss' => 0, 'description' => 'Machine assessed waste', 'client_request_id' => (string) Str::uuid()]);
        OperationalIncident::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $m->id, 'occurred_at' => '2026-08-13T10:00:00+07:00', 'category' => 'kualitas', 'incident_type' => 'human', 'status' => 'open', 'material_loss' => 0, 'service_loss' => 0, 'description' => 'Unknown machine waste cost', 'client_request_id' => (string) Str::uuid()]);

        $period = $this->period($m, '2026-08-01', '2026-08-31');

        $this->assertSame(500.0, $period['total_clicks'], 'corrected effective counter usage excludes superseded and voided readings');
        $this->assertSame(500.0, $period['priced_clicks'], 'all effective clicks have price evidence');
        $this->assertSame(0.0, $period['unpriced_clicks']);
        $this->assertSame(420000.0, $period['estimated_revenue'], 'two historical prices produce reconciled utilization revenue');
        $this->assertSame('COMPLETE', $period['revenue_status']);
        $this->assertSame('20000.00', $period['known_standard_machine_cost'], 'Standard cost includes machine-attributed assessed waste');
        $this->assertSame('PARTIAL_COST', $period['standard_contribution_status'], 'unknown machine cost evidence explicitly qualifies contribution');
        $this->assertSame(400000.0, $period['estimated_standard_contribution'], 'Standard contribution subtracts available Standard cost without treating unknown evidence as complete');
        $this->assertSame(800.0, $period['standard_contribution_per_click'], 'contribution per click reconciles against fully priced clicks');
        $this->assertEqualsWithDelta(95.2381, $period['standard_contribution_margin_percent'], 0.0001, 'margin uses contribution divided by utilization revenue');
        $this->assertSame(2, $period['period_price_count']);
    }

    public function test_no_price_configured_reports_no_price_and_never_fabricates_revenue(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');

        $this->assertSame('NO_PRICE', $period['revenue_status']);
        $this->assertNull($period['estimated_revenue'], 'no price leaves revenue unavailable, never Rp0');
        $this->assertNull($period['estimated_standard_contribution']);
        $this->assertNull($period['standard_contribution_margin_percent']);
        $this->assertNull($period['current_selling_price_per_click']);
    }

    public function test_no_clicks_reports_exact_known_zero_revenue_without_dividing(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 750, '2026-08-01T00:00:00+07:00');
        // Only a baseline reading inside the period — no usage at all.
        $this->reading($f, $m, 1000, '2026-08-10T10:00:00+07:00');

        $period = $this->period($m, '2026-08-01', '2026-08-31');

        $this->assertSame('NO_CLICKS', $period['revenue_status']);
        $this->assertSame(0.0, $period['estimated_revenue'], 'no clicks has exact zero utilization revenue, a real known zero');
        $this->assertNull($period['standard_contribution_margin_percent'], 'zero revenue never divides by zero');
    }

    public function test_one_historical_price_before_the_period_prices_every_click(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 500, '2026-07-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');

        $this->assertSame(100.0, $period['priced_clicks']);
        $this->assertSame(0.0, $period['unpriced_clicks']);
        $this->assertSame(50000.0, $period['estimated_revenue']);
        $this->assertSame('COMPLETE', $period['revenue_status']);
    }

    public function test_price_becoming_effective_exactly_at_period_start_applies_to_the_whole_period(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 600, '2026-08-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-05T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');
        $this->assertSame(60000.0, $period['estimated_revenue']);
    }

    public function test_partial_price_coverage_only_prices_the_covered_portion(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        // First price becomes effective mid-period; the 100-click interval
        // recorded before it must remain explicitly unpriced.
        $this->price($f, $m, 900, '2026-08-10T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $r1 = $this->reading($f, $m, 1100, '2026-08-05T10:00:00+07:00', 'effective', $r0->id);
        $this->reading($f, $m, 1150, '2026-08-15T10:00:00+07:00', 'effective', $r1->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');

        $this->assertSame(50.0, $period['priced_clicks'], 'partial price coverage counts only clicks at or after first price');
        $this->assertSame(100.0, $period['unpriced_clicks'], 'clicks before first price remain explicitly unpriced');
        $this->assertSame(45000.0, $period['estimated_revenue'], 'partial revenue represents priced portion only');
        $this->assertSame('PARTIAL', $period['revenue_status']);
        $this->assertNull($period['estimated_standard_contribution'], 'partial revenue never produces misleading period contribution');
        $this->assertNull($period['standard_contribution_per_click']);
    }

    public function test_voided_selling_price_is_excluded_and_prior_evidence_resumes(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 800, '2026-08-01T00:00:00+07:00');
        $this->price($f, $m, 850, '2026-08-16T00:00:00+07:00', 'voided');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1500, '2026-08-27T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');
        // The whole 500-click delta must now price at 800 throughout, since the
        // 850 evidence is voided and excluded from resolution.
        $this->assertSame(400000.0, $period['estimated_revenue']);
        $this->assertSame(800.0, $period['current_selling_price_per_click'], 'the voided 850 price must never become current');
    }

    public function test_voiding_the_only_configured_price_reverts_to_no_price(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 800, '2026-08-01T00:00:00+07:00', 'voided');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');
        $this->assertSame('NO_PRICE', $period['revenue_status']);
        $this->assertNull($period['estimated_revenue']);
    }

    public function test_same_effective_timestamp_is_tie_broken_deterministically_by_created_at_then_id(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $earlier = $this->price($f, $m, 700, '2026-08-01T00:00:00+07:00');
        \Illuminate\Support\Facades\DB::table('machine_selling_prices')->where('id', $earlier->id)->update(['created_at' => '2026-08-01T00:00:00Z']);
        $later = $this->price($f, $m, 720, '2026-08-01T00:00:00+07:00');
        \Illuminate\Support\Facades\DB::table('machine_selling_prices')->where('id', $later->id)->update(['created_at' => '2026-08-01T00:00:01Z']);
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');
        // Same effective_from -> the later created_at wins, deterministically.
        $this->assertSame(72000.0, $period['estimated_revenue']);
        $this->assertSame(720.0, $period['current_selling_price_per_click']);
    }

    public function test_a_future_price_never_becomes_the_current_price(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 800, '2026-08-01T00:00:00+07:00');
        $this->price($f, $m, 999, '2030-01-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');
        $this->assertSame(800.0, $period['current_selling_price_per_click']);
        $this->assertSame(800.0, $period['period_end_selling_price_per_click']);
        $this->assertSame(80000.0, $period['estimated_revenue'], 'a future price row must never price clicks recorded before it exists');
    }

    public function test_correcting_a_counter_value_recomputes_revenue(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 1000, '2026-08-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $r1 = $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $this->assertSame(100000.0, $this->period($m, '2026-08-01', '2026-08-31')['estimated_revenue']);

        $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$r1->id}/correction", [
            'correction_reason' => 'meter misread', 'replacement_value' => 1150, 'client_request_id' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertSame(150000.0, $this->period($m, '2026-08-01', '2026-08-31')['estimated_revenue'], 'a corrected counter value must not leave stale revenue');
    }

    public function test_correcting_a_counter_datetime_across_a_price_change_recomputes_revenue(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 800, '2026-08-01T00:00:00+07:00');
        $this->price($f, $m, 850, '2026-08-16T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $r1 = $this->reading($f, $m, 1100, '2026-08-14T10:00:00+07:00', 'effective', $r0->id);

        // Before: priced at 800 (100 * 800 = 80,000).
        $this->assertSame(80000.0, $this->period($m, '2026-08-01', '2026-08-31')['estimated_revenue']);

        // Move it across the price boundary to after 08-16 -> priced at 850.
        $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$r1->id}/correction", [
            'correction_reason' => 'wrong day entered', 'replacement_observed_at' => '2026-08-20T10:00:00+07:00', 'client_request_id' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertSame(85000.0, $this->period($m, '2026-08-01', '2026-08-31')['estimated_revenue'], 'a datetime correction across a price change must recompute revenue against the new price');
    }

    public function test_voiding_a_counter_reading_excludes_it_from_revenue(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 1000, '2026-08-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $r1 = $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);
        $this->reading($f, $m, 1300, '2026-08-20T10:00:00+07:00', 'effective', $r1->id);

        $this->assertSame(300000.0, $this->period($m, '2026-08-01', '2026-08-31')['estimated_revenue']);

        $this->actingAs($f['owner'])->postJson("/api/v1/counter-readings/{$r1->id}/correction", ['correction_reason' => 'duplicate reading'])->assertOk();

        // Voided r1 is removed from the sequence; the 08-20 reading's usage is
        // now against the 07-31 baseline (300), not the voided reading (200).
        $this->assertSame(300000.0, $this->period($m, '2026-08-01', '2026-08-31')['estimated_revenue'], 'total revenue is conserved across a void that only removes an intermediate reading');
    }

    public function test_business_projection_does_not_require_advanced_operating_cost_data(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 500, '2026-08-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');
        $this->assertSame('COMPLETE', $period['revenue_status']);
        $this->assertSame('COMPLETE', $period['standard_contribution_status']);
        $this->assertSame(50000.0, $period['estimated_standard_contribution'], 'no operating-cost evidence exists and Standard contribution is still fully available');
    }

    public function test_another_machines_price_history_never_leaks_into_this_projection(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $other = Machine::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_model_id' => $m->machine_model_id, 'machine_code' => 'REV-OTHER', 'display_name' => 'Other', 'status' => 'active']);
        $this->price($f, $other, 5000, '2026-08-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $period = $this->period($m, '2026-08-01', '2026-08-31');
        $this->assertSame('NO_PRICE', $period['revenue_status']);
        $this->assertNull($period['current_selling_price_per_click']);
    }

    public function test_business_fields_are_present_via_the_http_endpoint(): void
    {
        $f = $this->fixture();
        $m = $f['machine'];
        $this->price($f, $m, 500, '2026-08-01T00:00:00+07:00');
        $r0 = $this->reading($f, $m, 1000, '2026-07-31T17:00:00Z');
        $this->reading($f, $m, 1100, '2026-08-10T10:00:00+07:00', 'effective', $r0->id);

        $response = $this->actingAs($f['owner'])
            ->getJson("/api/v1/machines/{$m->id}/cost?period_start=2026-08-01&period_end=2026-08-31")
            ->assertOk()
            ->assertJsonPath('revenue_status', 'COMPLETE')
            ->assertJsonPath('standard_contribution_status', 'COMPLETE');
        $this->assertEquals(500, $response->json('current_selling_price_per_click'));
        $this->assertEquals(50000, $response->json('estimated_revenue'));
    }

    public function test_unauthorized_branch_machine_projection_is_denied(): void
    {
        $f = $this->fixture();
        $otherAccount = Account::create(['code' => 'MEC2', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta']);
        $otherBranch = $otherAccount->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $otherMachine = Machine::create(['account_id' => $otherAccount->id, 'branch_id' => $otherBranch->id, 'machine_model_id' => $f['machine']->machine_model_id, 'machine_code' => 'OTH-01', 'display_name' => 'Other', 'status' => 'active']);

        $this->actingAs($f['owner'])
            ->getJson("/api/v1/machines/{$otherMachine->id}/cost?period_start=2026-08-01&period_end=2026-08-31")
            ->assertForbidden();
    }
}
