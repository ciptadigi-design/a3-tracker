<?php

namespace App\Services;

use App\Models\CounterReading;
use App\Models\Machine;

class CounterPeriodService
{
    public function __construct(private MachineTimezoneResolver $tz) {}

    public function usage(Machine $machine, string $from, string $to): int|float
    {
        [$start,$end] = $this->tz->range($machine, $from, $to);
        $rows = CounterReading::where('machine_id', $machine->id)->where('status', 'effective')->whereBetween('observed_at', [$start, $end->copy()->subMicrosecond()])->get();

        return $rows->sum(function ($r) {
            return $r->previous_reading_id ? ((float) $r->reading_value - (float) CounterReading::find($r->previous_reading_id)->reading_value) : 0;
        });
    }
}
