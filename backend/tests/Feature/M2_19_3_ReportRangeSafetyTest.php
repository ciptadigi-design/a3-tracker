<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2.19 (closing the M2.18 audit's report range/payload-bound finding):
 * ReportsController previously accepted any period_start/period_end pair
 * with no ordering check and no maximum span - a custom range could be
 * arbitrarily wide (or inverted, silently returning an empty result rather
 * than a validation error). This suite proves the new bound is a predictable
 * validation error, not a silent truncation, and that normal report
 * workflows (a single month, a full calendar year) are unaffected.
 */
class M2_19_3_ReportRangeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'RNG', 'name' => 'Range', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'M', 'name' => 'Maker']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'X', 'name' => 'Model']);
        Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A1', 'display_name' => 'A1', 'status' => 'active']);

        return compact('account', 'branch', 'user');
    }

    private function reportUrl(array $f, string $start, string $end): string
    {
        return '/api/v1/reports?account_id='.$f['account']->id.'&branch_id='.$f['branch']->id.'&period_start='.$start.'&period_end='.$end;
    }

    public function test_a_normal_one_month_period_is_accepted(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson($this->reportUrl($f, '2026-08-01', '2026-08-31'))->assertOk();
    }

    public function test_a_full_calendar_year_period_is_accepted(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson($this->reportUrl($f, '2026-01-01', '2026-12-31'))->assertOk();
    }

    public function test_the_exact_boundary_of_366_days_is_accepted(): void
    {
        $f = $this->fixture();
        // 2026-01-01 to 2027-01-01 is exactly 366 days apart.
        $this->actingAs($f['user'])->getJson($this->reportUrl($f, '2026-01-01', '2027-01-01'))->assertOk();
    }

    public function test_a_period_exceeding_the_maximum_span_is_rejected_with_a_deterministic_validation_error(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['user'])->getJson($this->reportUrl($f, '2020-01-01', '2030-01-01'));
        $response->assertStatus(422)->assertJsonValidationErrors(['period_end']);
        $this->assertStringContainsString('cannot exceed', $response->json('errors.period_end.0'));
    }

    public function test_an_inverted_period_is_rejected_rather_than_silently_returning_empty_results(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson($this->reportUrl($f, '2026-08-31', '2026-08-01'))
            ->assertStatus(422)->assertJsonValidationErrors(['period_end']);
    }

    public function test_a_single_day_period_is_accepted(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson($this->reportUrl($f, '2026-08-15', '2026-08-15'))->assertOk();
    }

    public function test_a_malformed_period_start_fails_cleanly_without_a_server_error(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson($this->reportUrl($f, 'not-a-date', '2026-08-31'))
            ->assertStatus(422)->assertJsonValidationErrors(['period_start']);
    }
}
