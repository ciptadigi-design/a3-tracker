<?php

namespace Tests\Feature;

use App\Services\MachineClickTargetProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineClickTargetAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MachineClickTargetProjectionService
    {
        return app(MachineClickTargetProjectionService::class);
    }

    public function test_full_month_allocation_sums_exactly_to_the_monthly_target(): void
    {
        $dates = [];
        for ($d = 1; $d <= 30; $d++) {
            $dates[] = sprintf('2026-09-%02d', $d);
        }
        $plan = $this->service()->allocate(500000, $dates);

        $this->assertCount(30, $plan);
        $this->assertSame(500000, array_sum($plan));
    }

    public function test_target_100_across_3_active_days_allocates_34_33_33(): void
    {
        $plan = $this->service()->allocate(100, ['2026-09-01', '2026-09-02', '2026-09-03']);

        $this->assertSame([34, 33, 33], array_values($plan));
        $this->assertSame(100, array_sum($plan));
    }

    public function test_target_100_with_one_excluded_day_allocates_50_0_50(): void
    {
        // Date 2 excluded means only dates 1 and 3 are active.
        $plan = $this->service()->allocate(100, ['2026-09-01', '2026-09-03']);

        $this->assertSame(['2026-09-01' => 50, '2026-09-03' => 50], $plan);
    }

    public function test_no_active_days_returns_empty_plan(): void
    {
        $this->assertSame([], $this->service()->allocate(100000, []));
    }

    public function test_31_day_month_allocation_sums_exactly(): void
    {
        $dates = [];
        for ($d = 1; $d <= 31; $d++) {
            $dates[] = sprintf('2026-10-%02d', $d);
        }
        $plan = $this->service()->allocate(1000000, $dates);
        $this->assertSame(1000000, array_sum($plan));
    }

    public function test_leap_year_february_allocation_sums_exactly(): void
    {
        $dates = [];
        for ($d = 1; $d <= 29; $d++) {
            $dates[] = sprintf('2028-02-%02d', $d);
        }
        // base = floor(90/29) = 3, remainder = 90 - 3*29 = 3.
        $plan = $this->service()->allocate(90, $dates);
        $this->assertSame(90, array_sum($plan));
        $this->assertSame(3, array_sum(array_map(fn ($v) => $v === 4 ? 1 : 0, $plan)));
    }

    public function test_remainder_is_assigned_to_the_earliest_active_dates(): void
    {
        $plan = $this->service()->allocate(10, ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04']);
        // base=2, remainder=2 -> first two dates get 3, rest get 2.
        $this->assertSame(['2026-09-01' => 3, '2026-09-02' => 3, '2026-09-03' => 2, '2026-09-04' => 2], $plan);
    }
}
