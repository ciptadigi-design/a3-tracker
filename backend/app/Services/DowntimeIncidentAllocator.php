<?php

namespace App\Services;

use App\Models\DowntimeEvent;
use App\Models\Machine;
use Carbon\CarbonImmutable;

/**
 * Splits a machine's MERGED production downtime across its incidents so incident
 * financials reconcile with the machine total (OwnerDowntimeOverviewService).
 *
 * Deterministic rule: incidents are ordered by (start, id); each production minute of
 * the union is attributed to the first incident covering it, i.e. an incident owns only
 * the part of its span that extends beyond earlier incidents. Money uses cumulative
 * rounding (round(cum x rate / 60) minus the previous cumulative), so the incident
 * amounts sum EXACTLY to the machine amount the Overview reports.
 */
class DowntimeIncidentAllocator
{
    public function __construct(private DowntimeDurationCalculator $calc) {}

    /**
     * $from/$to optionally clip to a reporting window (UTC instants; $to already capped at now).
     * Returns [eventId => ['production_minutes' => int, 'operating_cost_impact' => ?int, 'potential_lost_revenue' => ?int]].
     * Unscheduled machine => minutes null (never 0) and null amounts.
     */
    public function allocate(Machine $machine, ?CarbonImmutable $from, ?CarbonImmutable $to, CarbonImmutable $now): array
    {
        $rate = $machine->financialRate;
        $events = DowntimeEvent::where('machine_id', $machine->id)
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('actual_end_at')->orWhere('actual_end_at', '>', $from)))
            ->when($to, fn ($q) => $q->where('actual_start_at', '<', $to))
            ->orderBy('actual_start_at')->orderBy('id')->get();

        $out = [];
        $maxEnd = null;
        $cumulative = 0;
        $prevCost = 0;
        $prevRevenue = 0;
        foreach ($events as $e) {
            $start = CarbonImmutable::parse($e->actual_start_at);
            $end = $e->actual_end_at ? CarbonImmutable::parse($e->actual_end_at) : $now;
            if ($from && $to) {
                $clipped = $this->calc->clipToPeriod($start, $end, $from, $to);
                [$start, $end] = $clipped ?? [$start, $start];
            }
            $ownStart = $maxEnd && $maxEnd->greaterThan($start) ? $maxEnd : $start;
            $minutes = $end->greaterThan($ownStart) ? $this->calc->productionDowntimeMinutes($machine, $ownStart, $end) : ($this->calc->productionDowntimeMinutes($machine, $start, $start));
            if ($end->greaterThan($maxEnd ?? $end->subSecond())) {
                $maxEnd = $end;
            }
            if ($minutes === null) {
                $out[$e->id] = ['production_minutes' => null, 'operating_cost_impact' => null, 'potential_lost_revenue' => null];

                continue;
            }
            $cumulative += $minutes;
            $cost = $rate?->operating_cost_per_hour === null ? null : (int) round($cumulative * $rate->operating_cost_per_hour / 60);
            $revenue = $rate?->estimated_revenue_per_hour === null ? null : (int) round($cumulative * $rate->estimated_revenue_per_hour / 60);
            $out[$e->id] = [
                'production_minutes' => $minutes,
                'operating_cost_impact' => $cost === null ? null : $cost - $prevCost,
                'potential_lost_revenue' => $revenue === null ? null : $revenue - $prevRevenue,
            ];
            $prevCost = $cost ?? 0;
            $prevRevenue = $revenue ?? 0;
        }

        return $out;
    }
}
