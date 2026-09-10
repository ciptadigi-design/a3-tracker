<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\MachineClickTarget;
use App\Models\MachineOperationalCalendarException;
use Carbon\CarbonImmutable;

/**
 * Derives the monthly/weekly/daily click plan and pace projection for a
 * machine's operational calendar. Actual clicks are always read through
 * EffectiveCounterSequence (the same canonical source as Machine Cost and
 * Daily Click Trend) so the Overview can never disagree with them for the
 * same machine/period/timezone. Nothing here re-derives usage.
 */
class MachineClickTargetProjectionService
{
    public function __construct(private MachineTimezoneResolver $tz, private DailyActualUsageService $usage, private PeriodComparisonService $comparison) {}

    /**
     * Deterministic integer allocation of $monthlyTarget across $activeDates
     * (ordered ascending date strings). Sum of the returned values always
     * equals $monthlyTarget exactly: the earliest (monthlyTarget % count)
     * dates get one extra click over the floor division, the rest get the
     * floor. Returns [] when there are no active dates.
     */
    public function allocate(int $monthlyTarget, array $activeDates): array
    {
        $count = count($activeDates);
        if ($count === 0) {
            return [];
        }
        $base = intdiv($monthlyTarget, $count);
        $remainder = $monthlyTarget % $count;
        $plan = [];
        foreach (array_values($activeDates) as $index => $date) {
            $plan[$date] = $base + ($index < $remainder ? 1 : 0);
        }

        return $plan;
    }

    public function projection(Machine $machine, int $year, int $month, ?CarbonImmutable $asOf = null): array
    {
        $tz = $this->tz->resolve($machine);
        $now = ($asOf ?? CarbonImmutable::now())->setTimezone($tz);
        $today = $now->toDateString();

        $monthStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $tz);
        $monthEnd = $monthStart->endOfMonth();

        $allDates = [];
        for ($cursor = $monthStart; $cursor->lte($monthEnd); $cursor = $cursor->addDay()) {
            $allDates[] = $cursor->toDateString();
        }

        $exceptions = MachineOperationalCalendarException::where('machine_id', $machine->id)
            ->where('calendar_date', '>=', $monthStart->toDateString())
            ->where('calendar_date', '<', $monthEnd->addDay()->toDateString())
            ->get()
            ->keyBy(fn ($row) => $row->calendar_date->toDateString());

        $activeDates = array_values(array_filter($allDates, fn ($date) => ! ($exceptions[$date]->excluded_from_target ?? false)));
        $excludedDates = array_values(array_diff($allDates, $activeDates));

        $targetRow = MachineClickTarget::where('machine_id', $machine->id)->where('target_year', $year)->where('target_month', $month)->first();
        $monthlyTarget = $targetRow?->monthly_click_target;
        $plan = $monthlyTarget !== null ? $this->allocate($monthlyTarget, $activeDates) : [];

        $actualByDate = $this->actualClicksByDate($machine, $tz, $monthStart->toDateString(), $monthEnd->toDateString());

        $daily = [];
        foreach ($allDates as $date) {
            $excluded = isset($exceptions[$date]) && $exceptions[$date]->excluded_from_target;
            $planned = $targetRow === null ? null : ($plan[$date] ?? 0);
            $actual = array_key_exists($date, $actualByDate) ? $actualByDate[$date] : null;
            $variance = ($planned !== null && $actual !== null) ? $actual - $planned : null;
            $achievement = ($planned !== null && $planned > 0 && $actual !== null) ? round($actual / $planned * 100, 1) : null;
            $daily[] = [
                'date' => $date,
                'calendar_status' => $excluded ? 'EXCLUDED' : 'ACTIVE',
                'exclusion_reason' => $excluded ? $exceptions[$date]->exception_type : null,
                'exclusion_notes' => $excluded ? $exceptions[$date]->notes : null,
                'planned_clicks' => $planned,
                'actual_clicks' => $actual,
                'variance' => $variance,
                'achievement_percentage' => $achievement,
                // Never fabricate comparison for a day that has not happened
                // yet (date > today) or is excluded from operational target.
                'previous_month' => ($excluded || $date > $today) ? null : $this->comparison->daily($machine, $tz, $date, (float) ($actual ?? 0.0)),
            ];
        }

        $actualMonthToDate = (float) array_sum($actualByDate);
        $plannedToDate = $targetRow === null ? null : array_sum(array_filter($plan, fn ($v, $date) => $date <= $today, ARRAY_FILTER_USE_BOTH));

        $activeDatesElapsed = count(array_filter($activeDates, fn ($d) => $d <= $today));
        $activeDatesRemaining = count(array_filter($activeDates, fn ($d) => $d >= $today));

        [$requiredPace, $requiredPaceStatus] = $this->requiredPace($monthlyTarget, $actualMonthToDate, $activeDatesRemaining);

        $variance = $plannedToDate === null ? null : $actualMonthToDate - $plannedToDate;
        $status = $this->targetStatus($monthlyTarget, count($activeDates), $actualMonthToDate, $variance);

        return [
            'machine_id' => $machine->id,
            'period' => ['year' => $year, 'month' => $month, 'timezone' => $tz],
            'target_status' => $status,
            'monthly_target' => $monthlyTarget,
            'actual_month_to_date' => $actualMonthToDate,
            'planned_month_to_date' => $plannedToDate,
            'variance' => $variance,
            'achievement_percentage' => $monthlyTarget > 0 ? round($actualMonthToDate / $monthlyTarget * 100, 1) : null,
            'remaining_target' => $monthlyTarget === null ? null : max($monthlyTarget - $actualMonthToDate, 0),
            'calendar_days' => count($allDates),
            'active_days_total' => count($activeDates),
            'excluded_days_total' => count($excludedDates),
            'active_days_elapsed' => $activeDatesElapsed,
            'active_days_remaining' => $activeDatesRemaining,
            'required_daily_pace' => $requiredPace,
            'required_pace_status' => $requiredPaceStatus,
            'today' => $this->todayCard($daily, $today, $machine, $tz, $monthStart, $monthEnd),
            'week' => $this->weekCard($daily, $today, $tz, $machine, $monthStart, $monthEnd),
            'month' => $this->monthCard($actualByDate, $actualMonthToDate, $monthlyTarget, $today, $machine, $tz, $monthStart, $monthEnd, $now),
            'daily' => $daily,
        ];
    }

    public function actualClicksByDate(Machine $machine, string $tz, string $from, string $to): array
    {
        return $this->usage->byDate($machine, $tz, $from, $to);
    }

    private function requiredPace(?int $monthlyTarget, float $actualMonthToDate, int $activeDaysRemaining): array
    {
        if ($monthlyTarget === null) {
            return [null, 'NOT_CONFIGURED'];
        }
        $remaining = max($monthlyTarget - $actualMonthToDate, 0);
        if ($remaining <= 0) {
            return [0, 'ACHIEVED'];
        }
        if ($activeDaysRemaining <= 0) {
            return [null, 'NO_ACTIVE_DAYS_REMAINING'];
        }

        return [(int) ceil($remaining / $activeDaysRemaining), 'OK'];
    }

    private function targetStatus(?int $monthlyTarget, int $activeDaysTotal, float $actualMonthToDate, ?float $variance): string
    {
        if ($monthlyTarget === null) {
            return 'NOT_CONFIGURED';
        }
        if ($activeDaysTotal === 0) {
            return 'NO_ACTIVE_DAYS';
        }
        if ($actualMonthToDate >= $monthlyTarget) {
            return 'ACHIEVED';
        }
        if ($variance === null || $variance === 0.0) {
            return 'ON_TRACK';
        }

        return $variance > 0 ? 'AHEAD' : 'BEHIND';
    }

    private function periodCard(array $daily, string $from, string $to): array
    {
        $rows = array_filter($daily, fn ($row) => $row['date'] >= $from && $row['date'] <= $to);
        $planned = null;
        $actual = 0.0;
        $haveActual = false;
        foreach ($rows as $row) {
            if ($row['planned_clicks'] !== null) {
                $planned = ($planned ?? 0) + $row['planned_clicks'];
            }
            if ($row['actual_clicks'] !== null) {
                $actual += $row['actual_clicks'];
                $haveActual = true;
            }
        }

        return [
            'actual' => $haveActual ? $actual : ($rows ? 0 : null),
            'planned' => $planned,
            'achievement_percentage' => ($planned !== null && $planned > 0) ? round(($haveActual ? $actual : 0) / $planned * 100, 1) : null,
            'variance' => $planned !== null ? ($haveActual ? $actual : 0) - $planned : null,
        ];
    }

    /**
     * "Today" card plus its previous-month same-date comparison. Only
     * computed when today actually falls within the requested month - a
     * different month being viewed has no meaningful "today" row to compare.
     */
    private function todayCard(array $daily, string $today, Machine $machine, string $tz, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $card = $this->periodCard($daily, $today, $today);
        $inMonth = $today >= $monthStart->toDateString() && $today <= $monthEnd->toDateString();
        $card['comparison'] = $inMonth ? $this->comparison->daily($machine, $tz, $today, (float) ($card['actual'] ?? 0.0)) : $this->comparison->daily($machine, $tz, $today, 0.0);

        return $card;
    }

    /**
     * Monday->Sunday week containing $today, clipped to the dates present in
     * $daily (i.e. to the requested month) so a week spanning a month
     * boundary only counts the days that belong to this month's target.
     *
     * The previous-month comparison shifts the exact date range this card
     * actually measures (the month-clipped intersection, not the full
     * Monday->Sunday span) one calendar month backward - see M2.14's "Current
     * Week At Month Boundary" rule.
     */
    private function weekCard(array $daily, string $today, string $tz, Machine $machine, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $todayCarbon = CarbonImmutable::parse($today, $tz);
        $weekStart = $todayCarbon->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $weekEnd = $todayCarbon->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

        $card = $this->periodCard($daily, $weekStart, $weekEnd) + ['week_start' => $weekStart, 'week_end' => $weekEnd];

        $representedStart = max($weekStart, $monthStart->toDateString());
        $representedEnd = min($weekEnd, $monthEnd->toDateString());
        $card['comparison'] = $representedStart > $representedEnd
            ? ['available' => false, 'comparison_status' => 'UNAVAILABLE', 'direction' => 'UNAVAILABLE', 'current_period' => null, 'previous_period' => null, 'delta_clicks' => null, 'delta_percentage' => null]
            : $this->comparison->range($machine, $tz, $representedStart, $representedEnd, (float) ($card['actual'] ?? 0.0));

        return $card;
    }

    /**
     * "This Month" card plus its previous-month comparison.
     *
     * Current month (today falls inside it): compares month-to-date against
     * the same month-to-date cutoff one month earlier, capped to the
     * previous month's last valid day when the cutoff day itself doesn't
     * exist there (e.g. Oct 31 MTD caps to Sep 30) - the MTD-only exception.
     * Past/future requested months compare the full represented month, also
     * capped, since the request is inherently not fabricating anything: past
     * months already have their real actuals, future months simply have none
     * yet.
     */
    private function monthCard(array $actualByDate, float $actualMonthToDate, ?int $monthlyTarget, string $today, Machine $machine, string $tz, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, CarbonImmutable $now): array
    {
        $isCurrentMonth = $monthStart->format('Y-m') === $now->format('Y-m');
        $currentEnd = $isCurrentMonth ? $today : $monthEnd->toDateString();
        $currentActual = (float) array_sum(array_filter($actualByDate, fn ($v, $d) => $d <= $currentEnd, ARRAY_FILTER_USE_BOTH));

        return [
            'actual' => $actualMonthToDate,
            'planned' => $monthlyTarget,
            'achievement_percentage' => $monthlyTarget > 0 ? round($actualMonthToDate / $monthlyTarget * 100, 1) : null,
            'variance' => $monthlyTarget === null ? null : $actualMonthToDate - $monthlyTarget,
            'is_month_to_date' => $isCurrentMonth,
            'comparison' => $this->comparison->range($machine, $tz, $monthStart->toDateString(), $currentEnd, $currentActual, true),
        ];
    }
}
