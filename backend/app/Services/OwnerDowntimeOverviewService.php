<?php

namespace App\Services;

use App\Models\DowntimeEvent;
use App\Models\Machine;
use Carbon\CarbonImmutable;

/**
 * Owner downtime KPIs. Single source of truth for Overview and Reports; the
 * frontend only renders what this returns. Deliberately excludes OEE/MTBF.
 * Financial impact (opt-in via $includeFinancial) is production downtime x a configured
 * hourly rate per machine (DowntimeFinancialImpact); operating cost and lost revenue stay
 * separate estimates, and a machine without a rate is never counted as Rp0.
 *
 * KPI semantics (each testable in DowntimeKpiAnalyticsTest):
 *
 * - Reporting window: inclusive local dates, resolved PER MACHINE in that machine's
 *   own timezone (machine -> branch -> account). The window end is clamped to "now":
 *   time that has not happened yet is neither scheduled nor downtime.
 * - Scheduled production time (denominator): minutes of the machine's scheduled
 *   production intervals (override > branch default, with date exceptions, overnight
 *   windows) inside the window. Machines with no schedule are excluded from the
 *   denominator and reported as unconfigured - never as 0% availability.
 * - Production downtime: downtime clipped to the window, overlapping intervals of the
 *   same machine MERGED first (no double counting), then intersected with scheduled
 *   production intervals. Off-shift time never counts. Elapsed time is a different
 *   number and is never mixed in.
 * - Availability: (scheduled - production downtime) / scheduled, only over configured
 *   machines; null when nothing is configured or the denominator is 0.
 * - Downtime events: DISTINCT incidents (one row == one incident, so crossing midnight
 *   or a month boundary never counts twice) whose interval overlaps the window.
 *   Counted for every in-scope machine, configured or not.
 * - Average recovery: mean full elapsed minutes (start -> end, NOT clipped, NOT
 *   schedule-filtered) of incidents COMPLETED inside the window (ended within it).
 *   Ongoing incidents are excluded. Null when none completed.
 */
class OwnerDowntimeOverviewService
{
    private const TOP_LIMIT = 10;

    public function __construct(private DowntimeDurationCalculator $calc, private MachineTimezoneResolver $tz) {}

    /**
     * $periodStartDate/$periodEndDate are inclusive local calendar dates (Y-m-d).
     * $detail adds the Reports breakdowns (trend, machine ranking, causes).
     */
    public function build(string $accountId, ?string $branchId, ?string $machineId, string $periodStartDate, string $periodEndDate, ?array $authorizedBranches, bool $detail = false, bool $includeFinancial = false): array
    {
        $now = CarbonImmutable::now();
        $machines = Machine::with(['branch', 'account', 'financialRate'])->where('account_id', $accountId)->where('status', '!=', 'retired')
            ->when($authorizedBranches !== null, fn ($q) => $q->whereIn('branch_id', $authorizedBranches))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($machineId, fn ($q) => $q->whereKey($machineId))
            ->get();
        $machineIds = $machines->pluck('id');
        $events = DowntimeEvent::whereIn('machine_id', $machineIds)->get()->groupBy('machine_id');

        $totalScheduled = 0;
        $totalProductionDowntime = 0;
        $configured = 0;
        $eventCount = 0;
        $recoveryMinutes = [];
        $ongoingCount = 0;
        $ranking = [];
        $causes = [];
        $codes = [];
        $costTotal = 0;
        $revenueTotal = 0;
        $costRated = 0;
        $revenueRated = 0;

        foreach ($machines as $machine) {
            [$periodStart, $periodEnd] = $this->tz->range($machine, $periodStartDate, $periodEndDate);
            $machineEvents = ($events[$machine->id] ?? collect())->filter(fn (DowntimeEvent $e) => $this->overlaps($e, $periodStart, $periodEnd));
            $eventCount += $machineEvents->count();
            $ongoingCount += $machineEvents->whereNull('actual_end_at')->count();
            foreach ($machineEvents as $e) {
                $causes[$e->cause] = ($causes[$e->cause] ?? 0) + 1;
                if ($e->error_code_text) {
                    $code = strtoupper(trim($e->error_code_text));
                    $codes[$code] = ($codes[$code] ?? 0) + 1;
                }
            }
            foreach (($events[$machine->id] ?? collect())->whereNotNull('actual_end_at') as $e) {
                $end = CarbonImmutable::parse($e->actual_end_at);
                if ($end->greaterThanOrEqualTo($periodStart) && $end->lessThan($periodEnd)) {
                    $recoveryMinutes[] = $this->calc->elapsedMinutes(CarbonImmutable::parse($e->actual_start_at), $end);
                }
            }

            $isConfigured = $this->calc->scheduledMinutesInRange($machine, $periodStart, $periodStart) !== null;
            $effectiveEnd = $periodEnd->lessThan($now) ? $periodEnd : $now;
            $scheduled = $isConfigured ? ($this->calc->scheduledMinutesInRange($machine, $periodStart, $effectiveEnd) ?? 0) : null;
            $production = $isConfigured ? $this->productionDowntime($machine, $machineEvents, $periodStart, $effectiveEnd, $now) : null;
            if ($isConfigured) {
                $configured++;
                $totalScheduled += $scheduled;
                $totalProductionDowntime += $production;
            }
            $money = DowntimeFinancialImpact::forMachine($machine->financialRate, $production);
            if ($includeFinancial) {
                if ($money['operating_cost_impact'] !== null) {
                    $costTotal += $money['operating_cost_impact'];
                    $costRated++;
                }
                if ($money['potential_lost_revenue'] !== null) {
                    $revenueTotal += $money['potential_lost_revenue'];
                    $revenueRated++;
                }
            }
            if ($detail && $machineEvents->isNotEmpty()) {
                $ranking[] = ($includeFinancial ? $money : []) + [
                    'machine_id' => $machine->id, 'machine_code' => $machine->machine_code, 'machine_name' => $machine->display_name, 'branch_name' => $machine->branch?->name,
                    'event_count' => $machineEvents->count(), 'production_downtime_minutes' => $production,
                    'availability_percent' => ($isConfigured && $scheduled > 0) ? round((($scheduled - $production) / $scheduled) * 100, 1) : null,
                ];
            }
        }

        $availabilityPercent = ($configured > 0 && $totalScheduled > 0)
            ? round((($totalScheduled - $totalProductionDowntime) / $totalScheduled) * 100, 1)
            : null;
        $activeEvents = DowntimeEvent::whereIn('machine_id', $machineIds)->whereNull('actual_end_at')->with(['machine.branch', 'notes', 'maintenanceTicket:id,downtime_event_id,status'])->orderBy('actual_start_at')->get();

        $result = [
            'period' => ['start' => $periodStartDate, 'end' => $periodEndDate],
            'schedule_status' => $machines->isEmpty() || $configured === 0 ? 'not_configured' : ($configured < $machines->count() ? 'partial' : 'configured'),
            'machine_count' => $machines->count(),
            'configured_machine_count' => $configured,
            'stopped_machine_count' => $activeEvents->pluck('machine_id')->unique()->count(),
            'scheduled_minutes' => $configured > 0 ? $totalScheduled : null,
            'availability_percent' => $availabilityPercent,
            'production_downtime_minutes' => $configured > 0 ? $totalProductionDowntime : null,
            'event_count' => $eventCount,
            'ongoing_event_count' => $ongoingCount,
            'completed_event_count' => count($recoveryMinutes),
            'average_recovery_minutes' => $recoveryMinutes ? (int) round(array_sum($recoveryMinutes) / count($recoveryMinutes)) : null,
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
                'elapsed_minutes' => $this->calc->elapsedMinutes(CarbonImmutable::parse($e->actual_start_at), $now),
                'maintenance_ticket' => $e->maintenanceTicket ? [
                    'id' => $e->maintenanceTicket->id,
                    'status' => $e->maintenanceTicket->status,
                ] : null,
            ])->values(),
        ];

        if ($includeFinancial) {
            $count = $machines->count();
            $result['financial'] = [
                'production_downtime_minutes' => $result['production_downtime_minutes'],
                'operating_cost_impact' => ['amount' => $costRated > 0 ? $costTotal : null, 'rated_machine_count' => $costRated, 'status' => DowntimeFinancialImpact::status($costRated, $count)],
                'potential_lost_revenue' => ['amount' => $revenueRated > 0 ? $revenueTotal : null, 'rated_machine_count' => $revenueRated, 'status' => DowntimeFinancialImpact::status($revenueRated, $count)],
            ];
        }

        if ($detail) {
            usort($ranking, fn ($a, $b) => [$b['production_downtime_minutes'] ?? -1, $b['event_count']] <=> [$a['production_downtime_minutes'] ?? -1, $a['event_count']]);
            $result['ranking'] = array_slice($ranking, 0, self::TOP_LIMIT);
            $result['causes'] = $this->topCounts($causes, 'cause');
            $result['error_codes'] = $this->topCounts($codes, 'error_code');
            $result['trend'] = $this->trend($machines, $events, $periodStartDate, $periodEndDate, $now);
        }

        return $result;
    }

    private function overlaps(DowntimeEvent $e, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return CarbonImmutable::parse($e->actual_start_at)->lessThan($end)
            && ($e->actual_end_at === null || CarbonImmutable::parse($e->actual_end_at)->greaterThan($start));
    }

    /** Clip each incident to the window, merge overlapping intervals of this machine, then intersect with the schedule. */
    private function productionDowntime(Machine $machine, $machineEvents, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): int
    {
        $intervals = [];
        foreach ($machineEvents as $e) {
            $eventEnd = $e->actual_end_at ? CarbonImmutable::parse($e->actual_end_at) : $now;
            if ($clipped = $this->calc->clipToPeriod(CarbonImmutable::parse($e->actual_start_at), $eventEnd, $start, $end)) {
                $intervals[] = $clipped;
            }
        }
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$s, $f]) {
            if ($merged && $s->lessThanOrEqualTo($merged[count($merged) - 1][1])) {
                if ($f->greaterThan($merged[count($merged) - 1][1])) {
                    $merged[count($merged) - 1][1] = $f;
                }
            } else {
                $merged[] = [$s, $f];
            }
        }

        return (int) collect($merged)->sum(fn ($i) => $this->calc->productionDowntimeMinutes($machine, $i[0], $i[1]) ?? 0);
    }

    private function topCounts(array $counts, string $key): array
    {
        $rows = [];
        foreach ($counts as $label => $count) {
            $rows[] = [$key => (string) $label, 'count' => $count];
        }
        usort($rows, fn ($a, $b) => [$b['count'], $a[$key]] <=> [$a['count'], $b[$key]]);

        return array_slice($rows, 0, self::TOP_LIMIT);
    }

    /** Production downtime per local bucket: daily for periods within one month, otherwise monthly. Each machine uses its own timezone. */
    private function trend($machines, $events, string $startDate, string $endDate, CarbonImmutable $now): array
    {
        $first = CarbonImmutable::parse($startDate);
        $last = CarbonImmutable::parse($endDate);
        $monthly = $first->format('Y-m') !== $last->format('Y-m');
        $buckets = [];
        for ($cursor = $first; $cursor->lessThanOrEqualTo($last);) {
            $from = $cursor;
            $to = $monthly ? $cursor->endOfMonth()->startOfDay() : $cursor;
            if ($to->greaterThan($last)) {
                $to = $last;
            }
            $buckets[] = [$from->format('Y-m-d'), $to->format('Y-m-d'), $monthly ? $from->format('Y-m') : $from->format('Y-m-d')];
            $cursor = $to->addDay();
        }

        return array_map(function ($b) use ($machines, $events, $now) {
            $minutes = 0;
            $configured = 0;
            foreach ($machines as $machine) {
                [$s, $e] = $this->tz->range($machine, $b[0], $b[1]);
                if ($this->calc->scheduledMinutesInRange($machine, $s, $s) === null) {
                    continue;
                }
                $configured++;
                $end = $e->lessThan($now) ? $e : $now;
                $machineEvents = ($events[$machine->id] ?? collect())->filter(fn (DowntimeEvent $x) => $this->overlaps($x, $s, $end));
                $minutes += $this->productionDowntime($machine, $machineEvents, $s, $end, $now);
            }

            return ['bucket' => $b[2], 'start' => $b[0], 'end' => $b[1], 'production_downtime_minutes' => $configured > 0 ? $minutes : null];
        }, $buckets);
    }
}
