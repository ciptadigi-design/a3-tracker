<?php

namespace App\Services;

use App\Models\Machine;
use Carbon\CarbonImmutable;

/**
 * Period-over-period ("vs previous month, same calendar dates") comparison
 * for Overview click performance (M2.14).
 *
 * Locked comparison principle: the current period is shifted EXACTLY ONE
 * CALENDAR MONTH backward using exact-date semantics - never a rolling
 * 30-day window, a previous full month's total, or an ordinal week number.
 *
 * Actual clicks for both sides are always read through
 * DailyActualUsageService::byDate(), which in turn reads
 * EffectiveCounterSequence - the same canonical source as Daily, Machine
 * Cost, and the click target projection itself. This class does not (and
 * must not) compute counter deltas on its own, so a historical correction or
 * void that changes EffectiveCounterSequence automatically changes the
 * comparison the next time it is computed - nothing here is cached.
 */
class PeriodComparisonService
{
    public function __construct(private DailyActualUsageService $usage) {}

    /**
     * Same-calendar-date comparison one month earlier (e.g. 2026-09-10 vs
     * 2026-08-10). If the shifted date does not exist (e.g. 2026-10-31 has no
     * 2026-09-31), the comparison is UNAVAILABLE - it is never clamped to a
     * nearby valid date.
     */
    public function daily(Machine $machine, string $tz, string $date, float $currentActual): array
    {
        $shiftedDate = $this->shiftDateExact($date, -1);
        if ($shiftedDate === null) {
            return $this->unavailable($date, $date, null, null);
        }

        $byDate = $this->usage->byDate($machine, $tz, $shiftedDate, $shiftedDate);
        if (! array_key_exists($shiftedDate, $byDate)) {
            return $this->unavailable($date, $date, $shiftedDate, $shiftedDate);
        }

        return $this->build($date, $date, $currentActual, $shiftedDate, $shiftedDate, (float) $byDate[$shiftedDate]);
    }

    /**
     * Aggregate comparison for an arbitrary [$currentStart, $currentEnd] date
     * range (used for This Week and This Month/MTD), shifted one calendar
     * month backward.
     *
     * $capEndToPreviousMonth is the MTD/full-month-only exception: when the
     * shifted end date does not exist in the previous month (e.g. Oct 31 ->
     * "Sep 31"), the end is capped to the previous month's last valid day
     * instead of making the whole comparison unavailable. Weekly comparisons
     * must NOT set this - an invalid shifted boundary there is UNAVAILABLE.
     */
    public function range(Machine $machine, string $tz, string $currentStart, string $currentEnd, float $currentActual, bool $capEndToPreviousMonth = false): array
    {
        $shiftedStart = $this->shiftDateExact($currentStart, -1);
        if ($shiftedStart === null) {
            return $this->unavailable($currentStart, $currentEnd, null, null);
        }

        $shiftedEnd = $this->shiftDateExact($currentEnd, -1);
        if ($shiftedEnd === null) {
            if (! $capEndToPreviousMonth) {
                return $this->unavailable($currentStart, $currentEnd, null, null);
            }
            $shiftedEnd = CarbonImmutable::parse($shiftedStart)->endOfMonth()->toDateString();
        }

        $byDate = $this->usage->byDate($machine, $tz, $shiftedStart, $shiftedEnd);
        if ($byDate === []) {
            return $this->unavailable($currentStart, $currentEnd, $shiftedStart, $shiftedEnd);
        }

        return $this->build($currentStart, $currentEnd, $currentActual, $shiftedStart, $shiftedEnd, (float) array_sum($byDate));
    }

    /**
     * Exact calendar-month shift of a single Y-m-d date. Returns null (never
     * a clamped/overflowed date) when the shifted month has no such day, e.g.
     * shiftDateExact('2026-10-31', -1) is null because September has no 31st.
     * Deliberately avoids Carbon's addMonths()/subMonths(), which silently
     * overflows into the following month for exactly this case.
     */
    public function shiftDateExact(string $date, int $months): ?string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        $totalMonths = ($year * 12 + ($month - 1)) + $months;
        $newYear = intdiv($totalMonths, 12);
        $newMonth = $totalMonths % 12 + 1;
        if ($newMonth <= 0) {
            $newMonth += 12;
            $newYear--;
        }

        return checkdate($newMonth, $day, $newYear) ? sprintf('%04d-%02d-%02d', $newYear, $newMonth, $day) : null;
    }

    private function build(string $currentStart, string $currentEnd, float $current, string $previousStart, string $previousEnd, float $previous): array
    {
        $deltaClicks = $current - $previous;

        if ($previous > 0) {
            $deltaPercentage = round((($current - $previous) / $previous) * 100, 1);
            $direction = $deltaClicks > 0 ? 'UP' : ($deltaClicks < 0 ? 'DOWN' : 'FLAT');
            $status = 'OK';
        } elseif ($current <= 0) {
            // previous = 0 and current = 0: truthful flat state, not a
            // meaningless "0% growth from nothing" claim.
            $deltaPercentage = 0.0;
            $direction = 'FLAT';
            $status = 'OK';
        } else {
            // previous = 0 and current > 0: growth from zero is
            // mathematically undefined - never rendered as Infinity/100%.
            $deltaPercentage = null;
            $direction = 'UP';
            $status = 'BASE_ZERO';
        }

        return [
            'available' => true,
            'comparison_status' => $status,
            'direction' => $direction,
            'current_period' => ['start_date' => $currentStart, 'end_date' => $currentEnd, 'clicks' => $current],
            'previous_period' => ['start_date' => $previousStart, 'end_date' => $previousEnd, 'clicks' => $previous],
            'delta_clicks' => $deltaClicks,
            'delta_percentage' => $deltaPercentage,
        ];
    }

    private function unavailable(string $currentStart, string $currentEnd, ?string $previousStart, ?string $previousEnd): array
    {
        return [
            'available' => false,
            'comparison_status' => 'UNAVAILABLE',
            'direction' => 'UNAVAILABLE',
            'current_period' => ['start_date' => $currentStart, 'end_date' => $currentEnd, 'clicks' => null],
            'previous_period' => $previousStart === null ? null : ['start_date' => $previousStart, 'end_date' => $previousEnd, 'clicks' => null],
            'delta_clicks' => null,
            'delta_percentage' => null,
        ];
    }
}
