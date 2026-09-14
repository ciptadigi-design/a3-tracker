<?php

namespace App\Services;

use App\Models\ComponentReplacement;
use App\Models\CounterReading;
use App\Models\Machine;
use App\Models\OperationalIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only operational reporting projection. The service deliberately composes
 * existing domain evidence; it does not persist summaries or introduce a second
 * economics model.
 */
class OperationalReportService
{
    public function __construct(private MachineCostService $costs, private MachineTimezoneResolver $tz) {}

    public function build(string $accountId, ?string $branchId, ?string $machineId, string $from, string $to, ?string $category = null, ?string $status = null, ?array $authorizedBranches = null): array
    {
        $machines = Machine::with(['branch.account'])->where('account_id', $accountId)->where('status', '!=', 'retired')
            ->when($authorizedBranches !== null, fn ($q) => $q->whereIn('branch_id', $authorizedBranches))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($machineId, fn ($q) => $q->whereKey($machineId))->orderBy('machine_code')->get();
        $machineIds = $machines->pluck('id');

        $costRows = $machines->map(fn (Machine $m) => $this->costs->period($m, $from, $to));
        $overview = [
            'active_machines' => $machines->where('status', 'active')->count(),
            'total_clicks' => (float) $costRows->sum(fn ($r) => (float) ($r['period_clicks'] ?? 0)),
            'component_consumption_cost' => $this->nullableMoney($costRows->pluck('known_consumption_cost')),
            'machine_attributed_error_waste' => $this->money($costRows->sum(fn ($r) => (float) $r['error_waste_cost'])),
            'branch_only_error_waste' => $this->money($this->incidents($accountId, $branchId, null, $from, $to, $category, $status, $authorizedBranches)->whereNull('machine_id')->sum(fn ($i) => (float) app(OperationalIncidentService::class)->effectiveLoss($i))),
            'replacement_count' => 0,
            'incident_count' => 0,
        ];
        $overview['standard_machine_cost'] = $this->money($costRows->sum(fn ($r) => (float) $r['standard_machine_cost']));
        $overview['standard_cost_per_click'] = $overview['total_clicks'] > 0 ? $this->money((float) $overview['standard_machine_cost'] / $overview['total_clicks']) : null;
        $incidents = $this->incidents($accountId, $branchId, $machineId, $from, $to, $category, $status, $authorizedBranches);
        $replacements = $this->replacements($accountId, $machineIds, $from, $to);
        $overview['replacement_count'] = $replacements->count();
        $overview['incident_count'] = $incidents->where('status', '!=', 'voided')->count();
        $overview['unknown_component_cost_events'] = $replacements->whereNull('consumed_cost')->count();
        $overview['partial'] = $overview['unknown_component_cost_events'] > 0;

        $counter = $this->counterRows($accountId, $machineIds, $from, $to);
        $performance = $machines->map(function (Machine $m) use ($counter) {
            $rows = $counter->where('machine_id', $m->id);

            return ['machine_id' => $m->id, 'machine_code' => $m->machine_code, 'machine_name' => $m->display_name, 'branch_id' => $m->branch_id, 'branch_name' => $m->branch?->name, 'resolved_timezone' => $this->tz->resolve($m), 'total_clicks' => (float) $rows->sum('usage'), 'active_days' => $rows->where('usage', '>', 0)->pluck('operational_date')->unique()->count(), 'latest_counter' => $rows->sortByDesc('observed_at')->first()['counter'] ?? null, 'last_input_at' => $rows->max('observed_at')];
        })->values();
        $daily = $counter->groupBy('operational_date')->map(fn ($rows, $date) => ['operational_date' => $date, 'total_clicks' => (float) $rows->sum('usage'), 'active_machines' => $rows->where('usage', '>', 0)->pluck('machine_id')->unique()->count()])->values();
        $operatorActivity = $counter->groupBy(fn ($r) => $r['operator_name'] ?: 'Unassigned')->map(fn ($rows, $name) => ['operator' => $name, 'counter_entries' => $rows->count(), 'recorded_usage' => (float) $rows->sum('usage'), 'last_counter_entry' => $rows->max('observed_at'), 'machines' => $rows->pluck('machine_code')->unique()->values()])->values();

        return [
            'period' => ['start' => $from, 'end' => $to, 'timezone' => $machines->first() ? $this->tz->resolve($machines->first()) : 'UTC'],
            'scope' => ['account_id' => $accountId, 'branch_id' => $branchId, 'machine_id' => $machineId],
            'overview' => $overview,
            'machine_cost' => $costRows->values(),
            'economics' => $costRows->values(),
            'performance' => $performance,
            'machine_cost_trend' => $this->costTrend($replacements, $incidents, $machines),
            'daily_clicks' => $daily,
            'counter' => $counter->values(),
            'operator_activity' => $operatorActivity,
            'incidents' => $incidents->map(fn ($i) => $this->incidentDto($i))->values(),
            'replacements' => $replacements->map(fn ($r) => $this->replacementDto($r))->values(),
            'components' => $replacements->map(fn ($r) => $this->replacementDto($r))->values(),
            'componentRanking' => $replacements->groupBy('machine_component_id')->values()->map(fn ($rows) => ['component_id' => $rows->first()->machine_component_id, 'component_code' => $rows->first()->newLifecycle?->machineComponent?->component?->code, 'component_name' => $rows->first()->newLifecycle?->machineComponent?->component?->name, 'replacement_count' => $rows->count(), 'known_consumed_cost' => $this->money($rows->sum(fn ($r) => (float) $r->consumed_cost)), 'unknown_cost_events' => $rows->whereNull('consumed_cost')->count()])->values(),
            'inventory_consumption' => $this->inventoryConsumption($accountId, $branchId, $machineIds, $from, $to, $authorizedBranches, $machineId !== null),
        ];
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function nullableMoney(Collection $values): ?string
    {
        return $values->isEmpty() ? null : $this->money($values->sum(fn ($v) => (float) $v));
    }

    // M2.19.1: a per-machine/branch/incident exact local-date boundary can't be
    // pushed into one SQL predicate up front, because each row's effective
    // date depends on ITS OWN resolved timezone (machine, then branch, then
    // account default) - a report can span machines in different zones.
    // Rather than partition the query per timezone, fetch a SAFE UTC SUPERSET
    // wide enough to contain every row whose LOCAL date could possibly fall
    // in [$from, $to] under ANY real-world IANA offset (-12:00 to +14:00,
    // both within 24h of UTC), then keep the exact existing local-date
    // ->filter() as the sole authoritative filter, completely unchanged. This
    // narrows the SQL fetch without changing which rows are ultimately
    // returned or how any date is interpreted - verified in
    // M2_19_1_ReportQueryScalabilityTest across two different timezones and
    // day/month/year boundaries.
    private function bufferedUtcRange(string $from, string $to): array
    {
        return [
            Carbon::parse($from)->subDay()->startOfDay(),
            Carbon::parse($to)->addDays(2)->startOfDay(),
        ];
    }

    private function incidents(string $accountId, ?string $branchId, ?string $machineId, string $from, string $to, ?string $category, ?string $status, ?array $authorizedBranches): Collection
    {
        [$lower, $upper] = $this->bufferedUtcRange($from, $to);

        return OperationalIncident::with(['branch', 'machine'])->where('account_id', $accountId)->where(fn ($q) => $q->whereNull('machine_id')->orWhereHas('machine', fn ($m) => $m->where('account_id', $accountId)->whereColumn('machines.branch_id', 'operational_incidents.branch_id')))->when($authorizedBranches !== null, fn ($q) => $q->whereIn('branch_id', $authorizedBranches))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($machineId, fn ($q) => $q->where('machine_id', $machineId))->when($category, fn ($q) => $q->where('category', $category))->when($status, fn ($q) => $q->where('status', $status))->where('occurred_at', '>=', $lower)->where('occurred_at', '<', $upper)->get()->filter(function ($i) use ($from, $to) {
            $tz = $i->machine?->timezone ?: ($i->branch?->timezone ?: ($i->branch?->account?->default_timezone ?: 'UTC'));
            $date = Carbon::parse($i->occurred_at)->setTimezone($tz)->toDateString();

            return $date >= $from && $date <= $to;
        })->sortByDesc(fn ($i) => [$i->occurred_at?->timestamp ?? 0, (string) $i->id])->values();
    }

    private function counterRows(string $accountId, Collection $machineIds, string $from, string $to): Collection
    {
        [$lower, $upper] = $this->bufferedUtcRange($from, $to);

        // The eager-loaded 'previous' belongsTo relation resolves by its own
        // stored previous_reading_id, via a separate WHERE id IN (...) query -
        // it is NOT constrained by this method's own date bound, so a
        // predecessor reading immediately before $from is still correctly
        // resolved even though the main query below excludes it. Verified in
        // M2_19_1_ReportQueryScalabilityTest's "reading exactly before period
        // start" case.
        return CounterReading::with(['machine.branch.account', 'previous'])->where('account_id', $accountId)->whereIn('machine_id', $machineIds)->where('status', 'effective')->whereHas('counterType', fn ($q) => $q->whereRaw('lower(code)=?', ['total_impressions']))->where('observed_at', '>=', $lower)->where('observed_at', '<', $upper)->get()->filter(function ($r) use ($from, $to) {
            $date = Carbon::parse($r->observed_at)->setTimezone($this->tz->resolve($r->machine))->toDateString();

            return $date >= $from && $date <= $to;
        })->map(function ($r) {
            return ['reading_id' => $r->id, 'observed_at' => $r->observed_at?->toISOString(), 'operational_date' => Carbon::parse($r->observed_at)->setTimezone($this->tz->resolve($r->machine))->toDateString(), 'resolved_timezone' => $this->tz->resolve($r->machine), 'machine_id' => $r->machine_id, 'machine_code' => $r->machine?->machine_code, 'operator_name' => $r->operator_name_snapshot, 'counter' => (float) $r->reading_value, 'usage' => $r->previous ? max(0, (float) $r->reading_value - (float) $r->previous->reading_value) : 0, 'shift' => $r->shift_code];
        })->sortByDesc(fn ($r) => [$r['observed_at'], $r['reading_id']])->values();
    }

    private function replacements(string $accountId, Collection $machineIds, string $from, string $to): Collection
    {
        [$lower, $upper] = $this->bufferedUtcRange($from, $to);

        return ComponentReplacement::with(['newLifecycle.machineComponent.machine.branch', 'newLifecycle.machineComponent.component'])->where('account_id', $accountId)->whereHas('newLifecycle.machineComponent', fn ($q) => $q->whereIn('machine_id', $machineIds))->where('replaced_at', '>=', $lower)->where('replaced_at', '<', $upper)->get()->filter(function ($r) use ($from, $to) {
            $m = $r->newLifecycle?->machineComponent?->machine;
            $date = Carbon::parse($r->replaced_at)->setTimezone($this->tz->resolve($m))->toDateString();

            return $date >= $from && $date <= $to;
        })->sortByDesc(fn ($r) => [$r->replaced_at?->timestamp ?? 0, (string) $r->id])->values();
    }

    private function replacementDto($r): array
    {
        $mc = $r->newLifecycle?->machineComponent;

        return ['replacement_id' => $r->id, 'replaced_at' => $r->replaced_at?->toISOString(), 'resolved_timezone' => $this->tz->resolve($mc?->machine), 'machine_id' => $mc?->machine_id, 'machine_code' => $mc?->machine?->machine_code, 'component' => $mc?->component?->name, 'component_code' => $mc?->component?->code, 'slot_code' => $mc?->slot_code, 'consumed_cost' => $r->consumed_cost === null ? null : $this->money($r->consumed_cost), 'source' => $r->inventory_source, 'lifecycle_id' => $r->new_lifecycle_id];
    }

    private function costTrend(Collection $replacements, Collection $incidents, Collection $machines): Collection
    {
        $rows = collect();
        foreach ($replacements->groupBy(fn ($r) => Carbon::parse($r->replaced_at)->setTimezone($this->tz->resolve($r->newLifecycle?->machineComponent?->machine))->toDateString()) as $date => $events) {
            $rows->push(['operational_date' => $date, 'standard_machine_cost' => $this->money($events->whereNotNull('consumed_cost')->sum('consumed_cost') + $incidents->filter(fn ($i) => $i->machine_id && Carbon::parse($i->occurred_at)->setTimezone($this->tz->resolve($i->machine))->toDateString() === $date)->sum(fn ($i) => (float) app(OperationalIncidentService::class)->effectiveLoss($i)))]);
        }

        return $rows->sortBy('operational_date')->values();
    }

    private function incidentDto($i): array
    {
        $timezone = $i->machine ? $this->tz->resolve($i->machine) : ($i->branch?->timezone ?: ($i->branch?->account?->default_timezone ?: 'UTC'));

        return ['incident_id' => $i->id, 'occurred_at' => $i->occurred_at?->toISOString(), 'resolved_timezone' => $timezone, 'machine_id' => $i->machine_id, 'machine_code' => $i->machine?->machine_code, 'operator' => $i->operator_name_snapshot, 'pic' => $i->responsible_name_snapshot, 'category' => $i->category, 'incident_type' => $i->incident_type, 'assessed_loss' => $this->money(app(OperationalIncidentService::class)->effectiveLoss($i)), 'status' => $i->status, 'attribution_scope' => $i->machine_id ? 'MACHINE' : 'BRANCH_ONLY'];
    }

    private function inventoryConsumption(string $accountId, ?string $branchId, Collection $machineIds, string $from, string $to, ?array $authorizedBranches, bool $machineFilter): Collection
    {
        [$lower, $upper] = $this->bufferedUtcRange($from, $to);
        $machines = Machine::with(['branch.account'])->whereIn('id', $machineIds)->get()->keyBy('id');

        return DB::table('inventory_movements as m')
            ->join('component_replacements as r', 'r.inventory_movement_id', '=', 'm.id')
            ->join('machine_components as mc', 'mc.id', '=', 'r.machine_component_id')
            ->join('machines as machine', 'machine.id', '=', 'mc.machine_id')
            ->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')
            ->join('inventory_locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('component_catalogs as c', 'c.id', '=', 'mc.component_id')
            ->where('m.account_id', $accountId)
            ->where('r.account_id', $accountId)
            ->where('mc.account_id', $accountId)
            ->where('machine.account_id', $accountId)
            ->where('l.account_id', $accountId)
            ->where('i.account_id', $accountId)
            ->where('m.movement_type', 'replacement_consumption')
            ->where('m.reference_type', 'component_replacement')
            ->whereIn('mc.machine_id', $machineIds)
            ->when($authorizedBranches !== null, fn ($q) => $q->whereIn('machine.branch_id', $authorizedBranches)->where(fn ($scope) => $scope->whereNull('l.branch_id')->orWhereIn('l.branch_id', $authorizedBranches)))
            ->when($branchId, fn ($q) => $q->where('machine.branch_id', $branchId)->where(fn ($scope) => $scope->whereNull('l.branch_id')->orWhere('l.branch_id', $branchId)))
            ->when($machineFilter, fn ($q) => $q->whereIn('mc.machine_id', $machineIds))
            ->where('r.replaced_at', '>=', $lower)
            ->where('r.replaced_at', '<', $upper)
            ->select([
                'm.id as movement_id', 'm.occurred_at as ledger_occurred_at', 'm.quantity as ledger_quantity',
                'r.id as replacement_id', 'r.replaced_at', 'r.quantity as consumed_quantity', 'r.consumed_cost',
                'mc.machine_id', 'machine.machine_code', 'c.code as component_code', 'c.name as component_name',
                'i.name as inventory_item_name', 'i.unit as inventory_unit', 'l.name as inventory_location_name',
            ])
            ->orderByDesc('r.replaced_at')->orderByDesc('r.id')->get()
            ->filter(function ($row) use ($machines, $from, $to) {
                $machine = $machines->get($row->machine_id);
                $date = Carbon::parse($row->replaced_at)->setTimezone($this->tz->resolve($machine))->toDateString();

                return $date >= $from && $date <= $to;
            })
            ->map(function ($row) use ($machines) {
                $timezone = $this->tz->resolve($machines->get($row->machine_id));

                return [
                    'movement_id' => $row->movement_id,
                    'replacement_id' => $row->replacement_id,
                    'replaced_at' => Carbon::parse($row->replaced_at)->toISOString(),
                    'effective_date' => Carbon::parse($row->replaced_at)->toISOString(),
                    'operational_date' => Carbon::parse($row->replaced_at)->setTimezone($timezone)->toDateString(),
                    'resolved_timezone' => $timezone,
                    'machine_id' => $row->machine_id,
                    'machine_code' => $row->machine_code,
                    'inventory_item_name' => $row->inventory_item_name,
                    'item' => $row->inventory_item_name,
                    'inventory_unit' => $row->inventory_unit,
                    'component_code' => $row->component_code,
                    'component' => $row->component_name,
                    'quantity_consumed' => abs((float) ($row->consumed_quantity ?? $row->ledger_quantity)),
                    'ledger_quantity' => (float) $row->ledger_quantity,
                    'consumed_cost' => $row->consumed_cost === null ? null : $this->money($row->consumed_cost),
                    'inventory_location_name' => $row->inventory_location_name,
                ];
            })->values();
    }
}
