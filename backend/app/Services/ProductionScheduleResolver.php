<?php

namespace App\Services;

use App\Models\BranchProductionSchedule;
use App\Models\Machine;
use App\Models\MachineProductionScheduleOverride;
use App\Models\ProductionScheduleException;
use Carbon\CarbonImmutable;

/**
 * Resolves the effective weekly production schedule (machine override, else
 * branch default, else "not configured") and any date-specific exception
 * (machine-specific exception overrides a branch-wide one for the same date).
 * A schedule is "configured" for a machine when EITHER its branch has a full
 * weekly default or the machine itself has an override row for at least one
 * day - day-by-day absence within a configured schedule means "non-operating
 * that day", never "not configured" (see the M2 Downtime & Maintenance V1
 * plan's explicit decision #3).
 */
class ProductionScheduleResolver
{
    public function isConfigured(Machine $machine): bool
    {
        return MachineProductionScheduleOverride::where('machine_id', $machine->id)->exists()
            || BranchProductionSchedule::where('branch_id', $machine->branch_id)->exists();
    }

    public function dayRule(Machine $machine, int $dayOfWeek): ?array
    {
        $override = MachineProductionScheduleOverride::where('machine_id', $machine->id)->where('day_of_week', $dayOfWeek)->first();
        if ($override) {
            return ['is_operating' => $override->is_operating, 'start_time' => $override->start_time, 'end_time' => $override->end_time, 'crosses_midnight' => $override->crosses_midnight];
        }
        $default = BranchProductionSchedule::where('branch_id', $machine->branch_id)->where('day_of_week', $dayOfWeek)->first();
        if ($default) {
            return ['is_operating' => $default->is_operating, 'start_time' => $default->start_time, 'end_time' => $default->end_time, 'crosses_midnight' => $default->crosses_midnight];
        }

        return null;
    }

    public function exceptionFor(Machine $machine, string $date): ?ProductionScheduleException
    {
        $machineRow = ProductionScheduleException::where('machine_id', $machine->id)->whereDate('exception_date', $date)->first();
        if ($machineRow) {
            return $machineRow;
        }

        return ProductionScheduleException::where('branch_id', $machine->branch_id)->whereNull('machine_id')->whereDate('exception_date', $date)->first();
    }

    /** Operating intervals (as [utcStart, utcEnd] CarbonImmutable pairs) for one local calendar date, honoring exceptions. */
    public function intervalsForDate(Machine $machine, CarbonImmutable $localDate, string $timezone): array
    {
        $exception = $this->exceptionFor($machine, $localDate->format('Y-m-d'));
        if ($exception) {
            if (! $exception->is_operating) {
                return [];
            }
            $start = $exception->override_start_time;
            $end = $exception->override_end_time;
            $crosses = (bool) $exception->crosses_midnight;
        } else {
            $rule = $this->dayRule($machine, $localDate->dayOfWeek);
            if (! $rule || ! $rule['is_operating']) {
                return [];
            }
            $start = $rule['start_time'];
            $end = $rule['end_time'];
            $crosses = (bool) $rule['crosses_midnight'];
        }
        if (! $start || ! $end) {
            return [];
        }
        $startAt = CarbonImmutable::parse($localDate->format('Y-m-d').' '.$start, $timezone);
        $endDate = $crosses ? $localDate->addDay() : $localDate;
        $endAt = CarbonImmutable::parse($endDate->format('Y-m-d').' '.$end, $timezone);

        return [[$startAt->utc(), $endAt->utc()]];
    }
}
