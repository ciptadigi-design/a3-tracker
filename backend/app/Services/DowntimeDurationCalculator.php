<?php

namespace App\Services;

use App\Models\Machine;
use Carbon\CarbonImmutable;

/**
 * Elapsed vs. production-downtime math. Elapsed is a plain wall-clock diff.
 * Production downtime is the intersection of the downtime interval with the
 * machine's scheduled production intervals, expanded day-by-day in the
 * machine's resolved timezone (machine -> branch -> account, via
 * MachineTimezoneResolver) so overnight shifts and exceptions are handled
 * correctly without naive calendar-day multiplication.
 *
 * V1 always computes from the CURRENT schedule configuration at calculation
 * time (for both active and closed events) - schedules are not
 * effective-dated/versioned yet. See the M2 Downtime & Maintenance V1 plan's
 * explicit decision #2 for the trade-off.
 */
class DowntimeDurationCalculator
{
    public function __construct(private MachineTimezoneResolver $tz, private ProductionScheduleResolver $schedule) {}

    public function elapsedMinutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return max(0, $start->diffInMinutes($end));
    }

    /** Null when no schedule is configured at all for this machine/branch. */
    public function productionDowntimeMinutes(Machine $machine, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return $this->schedule->isConfigured($machine) ? 0 : null;
        }
        if (! $this->schedule->isConfigured($machine)) {
            return null;
        }

        $tz = $this->tz->resolve($machine);
        $minutes = 0;
        // Start one day early so an overnight shift that began the previous local
        // day but still overlaps $start is not missed.
        $cursor = $start->setTimezone($tz)->startOfDay()->subDay();
        $endLocal = $end->setTimezone($tz);
        $guard = 0;
        while ($cursor->lessThan($endLocal) && $guard < 5000) {
            foreach ($this->schedule->intervalsForDate($machine, $cursor, $tz) as [$intervalStart, $intervalEnd]) {
                $overlapStart = $intervalStart->greaterThan($start) ? $intervalStart : $start;
                $overlapEnd = $intervalEnd->lessThan($end) ? $intervalEnd : $end;
                if ($overlapStart->lessThan($overlapEnd)) {
                    $minutes += $overlapStart->diffInMinutes($overlapEnd);
                }
            }
            $cursor = $cursor->addDay();
            $guard++;
        }

        return $minutes;
    }

    /** Total scheduled production minutes within an arbitrary interval - the same day-by-day schedule expansion as productionDowntimeMinutes(), just called with the reporting window itself rather than a downtime window. Null when not configured. */
    public function scheduledMinutesInRange(Machine $machine, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        return $this->productionDowntimeMinutes($machine, $start, $end);
    }

    /** Clip an interval to a reporting period BEFORE computing durations, to avoid double counting or naive day multiplication across a month boundary. */
    public function clipToPeriod(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): ?array
    {
        $clippedStart = $start->greaterThan($periodStart) ? $start : $periodStart;
        $clippedEnd = $end->lessThan($periodEnd) ? $end : $periodEnd;
        if ($clippedStart->greaterThanOrEqualTo($clippedEnd)) {
            return null;
        }

        return [$clippedStart, $clippedEnd];
    }
}
