<?php

namespace App\Services;

use App\Models\ComponentReplacement;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\OperationalIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MachineCostService
{
    public function __construct(private MachineTimezoneResolver $tz, private OperationalIncidentService $incidents) {}

    public function period(Machine $machine, string $from, string $to): array
    {
        [$start,$end] = $this->tz->range($machine, $from, $to);
        $tz = $this->tz->resolve($machine);
        $repls = ComponentReplacement::with('newLifecycle.machineComponent')->where('account_id', $machine->account_id)->whereHas('newLifecycle.machineComponent', fn ($q) => $q->where('machine_id', $machine->id))->where('replaced_at', '>=', $start)->where('replaced_at', '<', $end)->get();
        $known = $repls->whereNotNull('consumed_cost')->sum(fn ($r) => (string) $r->consumed_cost);
        $unknown = $repls->whereNull('consumed_cost')->count();
        $incidents = OperationalIncident::where('account_id', $machine->account_id)->where('machine_id', $machine->id)->where('status', '!=', 'voided')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->get();
        $loss = $incidents->reduce(fn ($c, $i) => $c + (float) $this->incidents->effectiveLoss($i), 0);
        $type = CounterType::whereRaw('lower(code)=?', ['total_impressions'])->first();
        $clicks = null;
        $rows = collect();
        if ($type) {
            $rows = CounterReading::with('previous')->where('account_id', $machine->account_id)->where('machine_id', $machine->id)->where('counter_type_id', $type->id)->where('status', 'effective')->where('observed_at', '>=', $start)->where('observed_at', '<', $end)->orderBy('observed_at')->get();
            $clicks = $rows->sum(function ($r) {
                $p = $r->previous;

                return $p ? max(0, (float) $r->reading_value - (float) $p->reading_value) : 0;
            });
        }
        $standard = (float) $known + (float) $loss;
        $dailyTrend = $this->dailyTrend($rows, $repls, $incidents, $tz);

        return ['machine_id' => $machine->id, 'machine_code' => $machine->machine_code, 'machine_name' => $machine->display_name, 'resolved_timezone' => $tz, 'period_start' => $from, 'period_end' => $to, 'period_clicks' => $clicks, 'total_clicks' => $clicks, 'known_consumption_cost' => number_format((float) $known, 2, '.', ''), 'component_consumption_cost' => number_format((float) $known, 2, '.', ''), 'unknown_consumption_events' => $unknown, 'unknown_component_cost_events' => $unknown, 'error_waste_cost' => number_format((float) $loss, 2, '.', ''), 'standard_machine_cost' => number_format($standard, 2, '.', ''), 'standard_cost_per_click' => $clicks > 0 ? number_format($standard / $clicks, 4, '.', '') : null, 'machine_cost_per_click' => $clicks > 0 ? number_format($standard / $clicks, 4, '.', '') : null, 'economics_status' => $unknown ? 'PARTIAL' : 'COMPLETE', 'partial' => $unknown > 0, 'daily_trend' => $dailyTrend];
    }

    /**
     * Per-day projection sharing the exact rows, timezone, and click-delta
     * semantics as the aggregate above, so the two can never disagree. A day
     * is included only when it has at least one counter reading, component
     * replacement, or incident — days with no evidence are omitted rather
     * than fabricated with a zero click count.
     */
    private function dailyTrend(Collection $rows, Collection $repls, Collection $incidents, string $tz): array
    {
        $days = [];
        $ensure = function (string $date) use (&$days) {
            return $days[$date] ??= ['operational_date' => $date, 'daily_clicks' => 0.0, 'counter_readings' => 0, 'known_daily_cost' => null, 'component_events' => 0, 'error_waste_events' => 0, 'unknown_cost_events' => 0];
        };

        foreach ($rows as $r) {
            $date = Carbon::parse($r->observed_at)->setTimezone($tz)->toDateString();
            $ensure($date);
            $previous = $r->previous;
            $usage = $previous ? max(0, (float) $r->reading_value - (float) $previous->reading_value) : 0;
            $days[$date]['daily_clicks'] += $usage;
            $days[$date]['counter_readings']++;
        }

        foreach ($repls as $r) {
            $date = Carbon::parse($r->replaced_at)->setTimezone($tz)->toDateString();
            $ensure($date);
            $days[$date]['component_events']++;
            if ($r->consumed_cost === null) {
                $days[$date]['unknown_cost_events']++;
            } else {
                $days[$date]['known_daily_cost'] = (float) ($days[$date]['known_daily_cost'] ?? 0) + (float) $r->consumed_cost;
            }
        }

        foreach ($incidents as $i) {
            $date = Carbon::parse($i->occurred_at)->setTimezone($tz)->toDateString();
            $ensure($date);
            $days[$date]['error_waste_events']++;
            $days[$date]['known_daily_cost'] = (float) ($days[$date]['known_daily_cost'] ?? 0) + (float) $this->incidents->effectiveLoss($i);
        }

        ksort($days);

        return array_values(array_map(function ($day) {
            $day['daily_clicks'] = $day['counter_readings'] > 0 ? $day['daily_clicks'] : null;
            $day['known_daily_cost'] = $day['known_daily_cost'] === null ? null : number_format($day['known_daily_cost'], 2, '.', '');
            $day['cost_evidence_status'] = $day['unknown_cost_events'] > 0 ? 'PARTIAL' : 'COMPLETE';

            return $day;
        }, $days));
    }
}
