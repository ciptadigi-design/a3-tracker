<?php

namespace App\Services;

use App\Models\ComponentReplacement;
use App\Models\CounterType;
use App\Models\Machine;
use App\Models\OperationalIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MachineCostService
{
    public function __construct(private MachineTimezoneResolver $tz, private OperationalIncidentService $incidents, private EffectiveCounterSequence $sequence, private EffectiveSellingPriceResolver $prices) {}

    public function period(Machine $machine, string $from, string $to): array
    {
        [$start,$end] = $this->tz->range($machine, $from, $to);
        $tz = $this->tz->resolve($machine);
        $repls = ComponentReplacement::with('newLifecycle.machineComponent')->where('account_id', $machine->account_id)->whereHas('newLifecycle.machineComponent', fn ($q) => $q->where('machine_id', $machine->id))->where('replaced_at', '>=', $start)->where('replaced_at', '<', $end)->get();
        $known = $repls->whereNotNull('consumed_cost')->sum(fn ($r) => (string) $r->consumed_cost);
        $unknown = $repls->whereNull('consumed_cost')->count();
        $totalConsumptionEvents = $repls->count();
        $knownConsumptionEvents = $totalConsumptionEvents - $unknown;
        $incidents = OperationalIncident::where('account_id', $machine->account_id)->where('machine_id', $machine->id)->where('status', '!=', 'voided')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)->get();
        $incidentLosses = $incidents->map(fn ($i) => (float) $this->incidents->effectiveLoss($i));
        $errorWasteEvents = $incidents->count();
        $knownErrorWasteEvents = $incidentLosses->filter(fn ($amount) => $amount > 0)->count();
        $unknownErrorWasteEvents = $errorWasteEvents - $knownErrorWasteEvents;
        $loss = $incidentLosses->filter(fn ($amount) => $amount > 0)->sum();
        $type = CounterType::whereRaw('lower(code)=?', ['total_impressions'])->first();
        $clicks = null;
        $rows = collect();
        if ($type) {
            // M2.19.1: $start/$end above are already an exact per-machine UTC
            // boundary (MachineTimezoneResolver::range()), so the bounded
            // query is exact here, not a conservative superset - no PHP-side
            // re-filtering needed beyond what forMachineWithinRange() already
            // applies at the SQL level.
            $rows = $this->sequence->forMachineWithinRange($machine->id, $type->id, $start, $end)->values();
            $clicks = $rows->sum(fn ($r) => max(0, (float) ($r->usage ?? 0)));
        }
        // Canonical "has usable counter data for this period" decision. This mirrors the
        // Supabase-authoritative get_machine_cost_period boundary: COMPLETE whenever at
        // least one effective Total Impressions reading falls inside the period (the same
        // $rows collection that total_clicks/daily_trend are derived from), NO_DATA
        // otherwise. Total Clicks and the Daily Trend must never disagree with this flag.
        $counterStatus = $rows->isNotEmpty() ? 'COMPLETE' : 'NO_DATA';
        $standard = (float) $known + (float) $loss;
        $standardCostPerClick = $counterStatus === 'COMPLETE' && $clicks > 0 ? number_format($standard / $clicks, 4, '.', '') : null;
        $dailyTrend = $this->dailyTrend($rows, $repls, $incidents, $tz);
        $economicsStatus = $unknown + $unknownErrorWasteEvents > 0 ? 'PARTIAL' : 'COMPLETE';
        $business = $this->businessProjection($machine, $rows, $end, $clicks, $counterStatus, $standard, $economicsStatus);

        return [
            'machine_id' => $machine->id,
            'machine_code' => $machine->machine_code,
            'machine_name' => $machine->display_name,
            'resolved_timezone' => $tz,
            'period_start' => $from,
            'period_end' => $to,
            'period_clicks' => $clicks,
            'total_clicks' => $clicks,
            'counter_status' => $counterStatus,
            'known_consumption_cost' => number_format((float) $known, 2, '.', ''),
            'component_consumption_cost' => number_format((float) $known, 2, '.', ''),
            'total_consumption_events' => $totalConsumptionEvents,
            'known_consumption_events' => $knownConsumptionEvents,
            'unknown_consumption_events' => $unknown,
            'unknown_component_cost_events' => $unknown,
            'error_waste_events' => $errorWasteEvents,
            'known_error_waste_events' => $knownErrorWasteEvents,
            'unknown_error_waste_events' => $unknownErrorWasteEvents,
            'known_error_waste_cost' => number_format((float) $loss, 2, '.', ''),
            'error_waste_cost' => number_format((float) $loss, 2, '.', ''),
            'unknown_evidence_events' => $unknown + $unknownErrorWasteEvents,
            'standard_machine_cost' => number_format($standard, 2, '.', ''),
            'known_standard_machine_cost' => number_format($standard, 2, '.', ''),
            'standard_cost_per_click' => $standardCostPerClick,
            'known_standard_cost_per_click' => $standardCostPerClick,
            'machine_cost_per_click' => $standardCostPerClick,
            'economics_status' => $economicsStatus,
            'partial' => $unknown > 0,
            'daily_trend' => $dailyTrend,
        ] + $business;
    }

    /**
     * Ports the Supabase oracle's get_machine_economics_period price/revenue/
     * contribution layer (supabase/migrations/20260828001200_machine_selling_price_revenue_contribution.sql)
     * on top of the canonical effective counter sequence already used for
     * total_clicks/daily_trend above, so revenue can never double-count or
     * miss a click relative to those figures. $rows is that same
     * period-filtered effective sequence (each with ->usage already computed
     * against its true chronological predecessor, possibly outside the
     * period — see EffectiveCounterSequence).
     */
    private function businessProjection(Machine $machine, Collection $rows, $periodEnd, ?float $clicks, string $counterStatus, float $standardCost, string $economicsStatus): array
    {
        $prices = $this->prices->postedPrices($machine->id);
        $currentPrice = $this->prices->effectiveAt($prices, Carbon::now());
        $periodEndPrice = $this->prices->effectiveBefore($prices, $periodEnd);

        $pricedClicks = 0.0;
        $unpricedClicks = 0.0;
        $revenueSum = 0.0;
        $havePricedRow = false;
        $distinctPrices = [];
        foreach ($rows as $r) {
            $usage = $r->usage;
            if ($usage === null || $usage <= 0) {
                continue;
            }
            $priceRow = $this->prices->effectiveAt($prices, $r->observed_at);
            if ($priceRow) {
                $pricedClicks += $usage;
                $revenueSum += $usage * (float) $priceRow->price_per_click;
                $havePricedRow = true;
                $distinctPrices[(string) $priceRow->price_per_click] = true;
            } else {
                $unpricedClicks += $usage;
            }
        }

        $totalClicks = (float) ($clicks ?? 0);
        $revenueStatus = match (true) {
            $counterStatus === 'COMPLETE' && $totalClicks == 0.0 => 'NO_CLICKS',
            $totalClicks > 0 && $pricedClicks == 0.0 => 'NO_PRICE',
            $unpricedClicks > 0 || $counterStatus !== 'COMPLETE' => 'PARTIAL',
            default => 'COMPLETE',
        };
        // Mirrors the oracle exactly: revenue stays null (not 0) whenever no
        // priced click was found at all, so "unavailable" is never confused
        // with a real known zero. It is forced to an exact 0 only for
        // NO_CLICKS (COMPLETE counter data, genuinely zero clicks).
        $revenue = $revenueStatus === 'NO_CLICKS' ? 0.0 : ($havePricedRow ? round($revenueSum, 2) : null);

        $contributionStatus = match (true) {
            $revenueStatus === 'NO_CLICKS' => 'NO_CLICKS',
            $revenueStatus !== 'COMPLETE' => 'UNAVAILABLE_REVENUE',
            (float) $revenue === 0.0 => 'ZERO_REVENUE',
            $economicsStatus === 'PARTIAL' => 'PARTIAL_COST',
            default => 'COMPLETE',
        };
        $contribution = null;
        $contributionPerClick = null;
        $margin = null;
        if ($revenueStatus === 'COMPLETE' && $revenue > 0) {
            $contribution = round($revenue - $standardCost, 2);
            $contributionPerClick = $totalClicks > 0 ? round($contribution / $totalClicks, 4) : null;
            $margin = round($contribution / $revenue * 100, 4);
        }

        return [
            'current_selling_price_per_click' => $currentPrice ? (float) $currentPrice->price_per_click : null,
            'period_end_selling_price_per_click' => $periodEndPrice ? (float) $periodEndPrice->price_per_click : null,
            'period_price_count' => count($distinctPrices),
            'priced_clicks' => $pricedClicks,
            'unpriced_clicks' => $unpricedClicks,
            'estimated_revenue' => $revenue,
            'revenue_status' => $revenueStatus,
            'standard_contribution_per_click' => $contributionPerClick,
            'estimated_standard_contribution' => $contribution,
            'standard_contribution_margin_percent' => $margin,
            'standard_contribution_status' => $contributionStatus,
        ];
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
            $usage = max(0, (float) ($r->usage ?? 0));
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
