<?php

namespace App\Services;

use App\Models\ComponentReplacement;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\OperationalIncident;

class MachineCostService
{
    public function __construct(private MachineTimezoneResolver $tz, private OperationalIncidentService $incidents) {}

    public function period(Machine $machine, string $from, string $to): array
    {
        [$start,$end] = $this->tz->range($machine, $from, $to);
        $repls = ComponentReplacement::with('newLifecycle.machineComponent')->where('account_id', $machine->account_id)->whereHas('newLifecycle.machineComponent', fn ($q) => $q->where('machine_id', $machine->id))->where('replaced_at', '>=', $start)->where('replaced_at', '<', $end)->get();
        $known = $repls->whereNotNull('consumed_cost')->sum(fn ($r) => (string) $r->consumed_cost);
        $unknown = $repls->whereNull('consumed_cost')->count();
        $loss = OperationalIncident::where('account_id', $machine->account_id)->where('machine_id', $machine->id)->where('status', '!=', 'voided')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->get()->reduce(fn ($c, $i) => $c + (float) $this->incidents->effectiveLoss($i), 0);
        $type = CounterType::whereRaw('lower(code)=?', ['total_impressions'])->first();
        $clicks = null;
        if ($type) {
            $rows = CounterReading::with('previous')->where('account_id', $machine->account_id)->where('machine_id', $machine->id)->where('counter_type_id', $type->id)->where('status', 'effective')->where('observed_at', '>=', $start)->where('observed_at', '<', $end)->orderBy('observed_at')->get();
            $clicks = $rows->sum(function ($r) {
                $p = $r->previous;

                return $p ? max(0, (float) $r->reading_value - (float) $p->reading_value) : 0;
            });
        }
        $standard = (float) $known + (float) $loss;

        return ['machine_id' => $machine->id, 'machine_code' => $machine->machine_code, 'machine_name' => $machine->display_name, 'resolved_timezone' => $this->tz->resolve($machine), 'period_start' => $from, 'period_end' => $to, 'period_clicks' => $clicks, 'total_clicks' => $clicks, 'known_consumption_cost' => number_format((float) $known, 2, '.', ''), 'component_consumption_cost' => number_format((float) $known, 2, '.', ''), 'unknown_consumption_events' => $unknown, 'unknown_component_cost_events' => $unknown, 'error_waste_cost' => number_format((float) $loss, 2, '.', ''), 'standard_machine_cost' => number_format($standard, 2, '.', ''), 'standard_cost_per_click' => $clicks > 0 ? number_format($standard / $clicks, 4, '.', '') : null, 'machine_cost_per_click' => $clicks > 0 ? number_format($standard / $clicks, 4, '.', '') : null, 'economics_status' => $unknown ? 'PARTIAL' : 'COMPLETE', 'partial' => $unknown > 0];
    }
}
