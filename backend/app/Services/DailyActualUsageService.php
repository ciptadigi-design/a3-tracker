<?php

namespace App\Services;

use App\Models\CounterType;
use App\Models\Machine;
use Illuminate\Support\Carbon;

/**
 * Canonical daily actual-usage lookup shared by
 * MachineClickTargetProjectionService (current period) and
 * PeriodComparisonService (previous-month period), so both read the same
 * EffectiveCounterSequence-derived numbers through one code path instead of
 * two independently-maintained counter-delta algorithms.
 *
 * Missing-vs-zero contract: a date is present in the returned map (even with
 * value 0.0) only when at least one effective counter reading was observed
 * that date. A date with no key means no usable historical evidence - callers
 * must not treat a missing key as a zero.
 */
class DailyActualUsageService
{
    public function __construct(private MachineTimezoneResolver $tz, private EffectiveCounterSequence $sequence) {}

    /**
     * @return array<string, float> date (Y-m-d) => summed usage that date
     */
    public function byDate(Machine $machine, string $tz, string $from, string $to): array
    {
        $type = CounterType::whereRaw('lower(code)=?', ['total_impressions'])->first();
        if (! $type) {
            return [];
        }
        [$start, $end] = $this->tz->range($machine, $from, $to);
        $rows = $this->sequence->forMachine($machine->id, $type->id)->filter(fn ($r) => $r->observed_at->gte($start) && $r->observed_at->lt($end));

        $byDate = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->observed_at)->setTimezone($tz)->toDateString();
            $byDate[$date] = ($byDate[$date] ?? 0) + max(0, (float) ($row->usage ?? 0));
        }

        return $byDate;
    }
}
