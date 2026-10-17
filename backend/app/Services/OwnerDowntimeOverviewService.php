<?php

namespace App\Services;

use App\Models\DowntimeEvent;
use App\Models\Machine;
use Carbon\CarbonImmutable;

/**
 * The 4 minimal Owner Overview metrics: availability %, production downtime,
 * event count, and the "needs attention" list of currently-active downtime
 * events. Deliberately excludes MTBF/MTTR/financial projections - those are
 * future phases per the M2 Downtime & Maintenance V1 plan.
 */
class OwnerDowntimeOverviewService
{
    public function __construct(private DowntimeDurationCalculator $calc) {}

    public function build(string $accountId, ?string $branchId, ?string $machineId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?array $authorizedBranches): array
    {
        $machines = Machine::where('account_id', $accountId)->where('status', '!=', 'retired')
            ->when($authorizedBranches !== null, fn ($q) => $q->whereIn('branch_id', $authorizedBranches))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($machineId, fn ($q) => $q->whereKey($machineId))
            ->get();
        $machineIds = $machines->pluck('id');

        $events = DowntimeEvent::whereIn('machine_id', $machineIds)
            ->where('actual_start_at', '<', $periodEnd)
            ->where(function ($q) use ($periodStart) {
                $q->whereNull('actual_end_at')->orWhere('actual_end_at', '>', $periodStart);
            })
            ->get();

        $totalScheduled = 0;
        $totalProductionDowntime = 0;
        $anyConfigured = false;
        foreach ($machines as $machine) {
            $scheduled = $this->calc->scheduledMinutesInRange($machine, $periodStart, $periodEnd);
            if ($scheduled === null) {
                continue;
            }
            $anyConfigured = true;
            $totalScheduled += $scheduled;
            foreach ($events->where('machine_id', $machine->id) as $event) {
                $start = CarbonImmutable::parse($event->actual_start_at);
                $end = $event->actual_end_at ? CarbonImmutable::parse($event->actual_end_at) : CarbonImmutable::now();
                $clipped = $this->calc->clipToPeriod($start, $end, $periodStart, $periodEnd);
                if (! $clipped) {
                    continue;
                }
                $totalProductionDowntime += $this->calc->productionDowntimeMinutes($machine, $clipped[0], $clipped[1]) ?? 0;
            }
        }

        $availabilityPercent = ($anyConfigured && $totalScheduled > 0)
            ? round((($totalScheduled - $totalProductionDowntime) / $totalScheduled) * 100, 1)
            : null;

        $activeEvents = DowntimeEvent::whereIn('machine_id', $machineIds)->whereNull('actual_end_at')->with('machine')->orderBy('actual_start_at')->get();

        return [
            'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
            'availability_percent' => $availabilityPercent,
            'production_downtime_minutes' => $anyConfigured ? $totalProductionDowntime : null,
            'event_count' => $events->count(),
            'attention' => $activeEvents->map(fn (DowntimeEvent $e) => [
                'downtime_event_id' => $e->id,
                'machine_id' => $e->machine_id,
                'machine_code' => $e->machine?->machine_code,
                'machine_name' => $e->machine?->display_name,
                'cause' => $e->cause,
                'error_code_text' => $e->error_code_text,
                'actual_start_at' => $e->actual_start_at,
                'elapsed_minutes' => $this->calc->elapsedMinutes(CarbonImmutable::parse($e->actual_start_at), CarbonImmutable::now()),
            ])->values(),
        ];
    }
}
