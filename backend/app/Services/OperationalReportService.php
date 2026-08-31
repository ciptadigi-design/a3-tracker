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

    public function build(string $accountId, ?string $branchId, ?string $machineId, string $from, string $to, ?string $category = null, ?string $status = null): array
    {
        $machines = Machine::with(['branch.account'])->where('account_id', $accountId)->where('status', '!=', 'retired')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($machineId, fn ($q) => $q->whereKey($machineId))->orderBy('machine_code')->get();
        $machineIds = $machines->pluck('id');

        $costRows = $machines->map(fn (Machine $m) => $this->costs->period($m, $from, $to));
        $overview = [
            'active_machines' => $machines->where('status', 'active')->count(),
            'total_clicks' => (float) $costRows->sum(fn ($r) => (float) ($r['period_clicks'] ?? 0)),
            'component_consumption_cost' => $this->nullableMoney($costRows->pluck('known_consumption_cost')),
            'machine_attributed_error_waste' => $this->money($costRows->sum(fn ($r) => (float) $r['error_waste_cost'])),
            'branch_only_error_waste' => $this->money($this->incidents($accountId, $branchId, null, $from, $to, $category, $status)->whereNull('machine_id')->sum(fn ($i) => (float) app(OperationalIncidentService::class)->effectiveLoss($i))),
            'replacement_count' => 0,
            'incident_count' => 0,
        ];
        $overview['standard_machine_cost'] = $this->money($costRows->sum(fn ($r) => (float) $r['standard_machine_cost']));
        $overview['standard_cost_per_click'] = $overview['total_clicks'] > 0 ? $this->money((float) $overview['standard_machine_cost'] / $overview['total_clicks']) : null;
        $incidents = $this->incidents($accountId, $branchId, $machineId, $from, $to, $category, $status);
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
            'inventory_consumption' => $this->inventoryConsumption($accountId, $branchId, $machineIds, $from, $to),
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

    private function incidents(string $accountId, ?string $branchId, ?string $machineId, string $from, string $to, ?string $category, ?string $status): Collection
    {
        return OperationalIncident::with(['branch', 'machine'])->where('account_id', $accountId)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($machineId, fn ($q) => $q->where('machine_id', $machineId))->when($category, fn ($q) => $q->where('category', $category))->when($status, fn ($q) => $q->where('status', $status))->get()->filter(function ($i) use ($from, $to) {
            $tz = $i->machine?->timezone ?: ($i->branch?->timezone ?: ($i->branch?->account?->default_timezone ?: 'UTC'));
            $date = Carbon::parse($i->occurred_at)->setTimezone($tz)->toDateString();

            return $date >= $from && $date <= $to;
        })->sortByDesc(fn ($i) => [$i->occurred_at?->timestamp ?? 0, (string) $i->id])->values();
    }

    private function counterRows(string $accountId, Collection $machineIds, string $from, string $to): Collection
    {
        return CounterReading::with(['machine.branch.account', 'previous'])->where('account_id', $accountId)->whereIn('machine_id', $machineIds)->where('status', 'effective')->whereHas('counterType', fn ($q) => $q->whereRaw('lower(code)=?', ['total_impressions']))->get()->filter(function ($r) use ($from, $to) {
            $date = Carbon::parse($r->observed_at)->setTimezone($this->tz->resolve($r->machine))->toDateString();

            return $date >= $from && $date <= $to;
        })->map(function ($r) {
            return ['reading_id' => $r->id, 'observed_at' => $r->observed_at?->toISOString(), 'operational_date' => Carbon::parse($r->observed_at)->setTimezone($this->tz->resolve($r->machine))->toDateString(), 'machine_id' => $r->machine_id, 'machine_code' => $r->machine?->machine_code, 'operator_name' => $r->operator_name_snapshot, 'counter' => (float) $r->reading_value, 'usage' => $r->previous ? max(0, (float) $r->reading_value - (float) $r->previous->reading_value) : 0, 'shift' => $r->shift_code];
        })->sortByDesc(fn ($r) => [$r['observed_at'], $r['reading_id']])->values();
    }

    private function replacements(string $accountId, Collection $machineIds, string $from, string $to): Collection
    {
        return ComponentReplacement::with(['newLifecycle.machineComponent.machine.branch', 'newLifecycle.machineComponent.component'])->where('account_id', $accountId)->whereHas('newLifecycle.machineComponent', fn ($q) => $q->whereIn('machine_id', $machineIds))->get()->filter(function ($r) use ($from, $to) {
            $m = $r->newLifecycle?->machineComponent?->machine;
            $date = Carbon::parse($r->replaced_at)->setTimezone($this->tz->resolve($m))->toDateString();

            return $date >= $from && $date <= $to;
        })->sortByDesc(fn ($r) => [$r->replaced_at?->timestamp ?? 0, (string) $r->id])->values();
    }

    private function replacementDto($r): array
    {
        $mc = $r->newLifecycle?->machineComponent;

        return ['replacement_id' => $r->id, 'replaced_at' => $r->replaced_at?->toISOString(), 'machine_id' => $mc?->machine_id, 'machine_code' => $mc?->machine?->machine_code, 'component' => $mc?->component?->name, 'component_code' => $mc?->component?->code, 'slot_code' => $mc?->slot_code, 'consumed_cost' => $r->consumed_cost === null ? null : $this->money($r->consumed_cost), 'source' => $r->inventory_source, 'lifecycle_id' => $r->new_lifecycle_id];
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
        return ['incident_id' => $i->id, 'occurred_at' => $i->occurred_at?->toISOString(), 'machine_id' => $i->machine_id, 'machine_code' => $i->machine?->machine_code, 'operator' => $i->operator_name_snapshot, 'pic' => $i->responsible_name_snapshot, 'category' => $i->category, 'incident_type' => $i->incident_type, 'assessed_loss' => $this->money(app(OperationalIncidentService::class)->effectiveLoss($i)), 'status' => $i->status, 'attribution_scope' => $i->machine_id ? 'MACHINE' : 'BRANCH_ONLY'];
    }

    private function inventoryConsumption(string $accountId, ?string $branchId, Collection $machineIds, string $from, string $to): Collection
    {
        return DB::table('inventory_movements as m')->join('inventory_items as i', 'i.id', '=', 'm.inventory_item_id')->leftJoin('inventory_locations as l', 'l.id', '=', 'm.location_id')->leftJoin('component_replacements as r', 'r.inventory_movement_id', '=', 'm.id')->leftJoin('machine_components as mc', 'mc.id', '=', 'r.machine_component_id')->where('m.account_id', $accountId)->where('m.movement_type', 'issue')->where('m.reference_type', 'replacement_consumption')->when($branchId, fn ($q) => $q->where('l.branch_id', $branchId))->whereBetween('m.occurred_at', [Carbon::parse($from)->startOfDay()->utc(), Carbon::parse($to)->addDay()->startOfDay()->utc()])->select(['m.id as movement_id', 'm.occurred_at', 'm.quantity', 'i.name', 'r.id as replacement_id', 'r.consumed_cost', 'mc.machine_id', 'mc.component_id'])->orderByDesc('m.occurred_at')->orderByDesc('m.id')->get()->map(fn ($r) => ['effective_date' => $r->occurred_at, 'machine_id' => $r->machine_id, 'item' => $r->name, 'component' => $r->component_id, 'quantity_consumed' => (float) $r->quantity, 'consumed_cost' => $r->consumed_cost === null ? null : $this->money($r->consumed_cost), 'replacement_id' => $r->replacement_id]);
    }
}
