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
    public function __construct(private DowntimeDurationCalculator $calc, private MachineTimezoneResolver $tz) {}

    /**
     * $periodStartDate/$periodEndDate are inclusive local calendar dates (Y-m-d).
     * Each machine's reporting window is resolved in ITS OWN timezone
     * (machine -> branch -> account) so a Jakarta machine's month starts at
     * Jakarta midnight, not at the server's UTC midnight.
     */
    public function build(string $accountId, ?string $branchId, ?string $machineId, string $periodStartDate, string $periodEndDate, ?array $authorizedBranches): array
    {
        $machines = Machine::with(['branch', 'account'])->where('account_id', $accountId)->where('status', '!=', 'retired')
            ->when($authorizedBranches !== null, fn ($q) => $q->whereIn('branch_id', $authorizedBranches))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($machineId, fn ($q) => $q->whereKey($machineId))
            ->get();
        $machineIds = $machines->pluck('id');

        $events = DowntimeEvent::whereIn('machine_id', $machineIds)->get();

        $totalScheduled = 0;
        $totalProductionDowntime = 0;
        $anyConfigured = false;
        $eventCount = 0;
        foreach ($machines as $machine) {
            [$periodStart, $periodEnd] = $this->tz->range($machine, $periodStartDate, $periodEndDate);
            $machineEvents = $events->where('machine_id', $machine->id)->filter(function (DowntimeEvent $e) use ($periodStart, $periodEnd) {
                return CarbonImmutable::parse($e->actual_start_at)->lessThan($periodEnd)
                    && ($e->actual_end_at === null || CarbonImmutable::parse($e->actual_end_at)->greaterThan($periodStart));
            });
            $eventCount += $machineEvents->count();
            $scheduled = $this->calc->scheduledMinutesInRange($machine, $periodStart, $periodEnd);
            if ($scheduled === null) {
                continue;
            }
            $anyConfigured = true;
            $totalScheduled += $scheduled;
            foreach ($machineEvents as $event) {
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

        $activeEvents = DowntimeEvent::whereIn('machine_id', $machineIds)->whereNull('actual_end_at')->with(['machine.branch', 'notes'])->orderBy('actual_start_at')->get();

        return [
            'period' => ['start' => $periodStartDate, 'end' => $periodEndDate],
            'availability_percent' => $availabilityPercent,
            'production_downtime_minutes' => $anyConfigured ? $totalProductionDowntime : null,
            'event_count' => $eventCount,
            'attention' => $activeEvents->map(fn (DowntimeEvent $e) => [
                'downtime_event_id' => $e->id,
                'machine_id' => $e->machine_id,
                'machine_code' => $e->machine?->machine_code,
                'machine_name' => $e->machine?->display_name,
                'branch_id' => $e->branch_id,
                'branch_name' => $e->machine?->branch?->name,
                'description' => $e->description,
                'latest_note' => ($n = $e->notes->last()) ? ['note' => $n->note, 'occurred_at' => $n->occurred_at] : null,
                'cause' => $e->cause,
                'error_code_text' => $e->error_code_text,
                'actual_start_at' => $e->actual_start_at,
                'elapsed_minutes' => $this->calc->elapsedMinutes(CarbonImmutable::parse($e->actual_start_at), CarbonImmutable::now()),
            ])->values(),
        ];
    }
}
