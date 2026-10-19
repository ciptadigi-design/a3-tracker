<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DowntimeEvent;
use App\Models\Machine;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\DowntimeDurationCalculator;
use App\Services\DowntimeService;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\MachineAccessResolver;
use App\Services\MachineTimezoneResolver;
use App\Services\OperationalPeriodRange;
use App\Services\OwnerDowntimeOverviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DowntimeController extends Controller
{
    public function __construct(private MachineAccessResolver $machineAccess, private AccountAccessResolver $accountAccess, private BranchAccessResolver $branchAccess) {}

    // Downtime read/write access is gated on branch/account membership only (write:
    // false), never on Machine::canAccess(..., write: true) - that write-mode check
    // requires machines.status === 'active', which would make it impossible to End
    // Downtime or Add a Note on a machine that is, by definition, currently down.
    // See the M2 Downtime & Maintenance V1 plan's explicit decision #1.
    private function assertRead(Request $r, Machine $machine): void
    {
        abort_unless($this->machineAccess->canAccess($r->user(), $machine), 403);
    }

    private function assertManage(Request $r, Machine $machine): void
    {
        abort_unless($this->machineAccess->canAccess($r->user(), $machine) && app(EffectiveCapabilityResolver::class)->allows($r->user(), $machine->account, 'downtime.manage'), 403);
    }

    public function active(Request $r, Machine $machine)
    {
        $this->assertRead($r, $machine);
        $event = DowntimeEvent::where('machine_id', $machine->id)->whereNull('actual_end_at')->with('notes.performer')->first();

        return response()->json(['data' => $event]);
    }

    public function start(Request $r, Machine $machine)
    {
        $this->assertManage($r, $machine);
        $d = $r->validate([
            'cause' => 'required|string|max:60',
            'error_code_text' => 'nullable|string|max:16',
            'description' => 'nullable|string',
            'actual_start_at' => 'nullable|date',
            'client_request_id' => 'nullable|uuid',
        ]);
        $canBackdate = app(EffectiveCapabilityResolver::class)->allows($r->user(), $machine->account, 'downtime.backdate');
        $event = app(DowntimeService::class)->start($machine, $r->user(), $d, $canBackdate);

        app(GovernanceAudit::class)->changed($r->user(), 'downtime_event.started', 'downtime_event', $event->id, $event->account_id, [], app(GovernanceAudit::class)->snapshot($event));

        return response()->json(['data' => $event], 201);
    }

    public function addNote(Request $r, DowntimeEvent $event)
    {
        $machine = Machine::with('account')->findOrFail($event->machine_id);
        $this->assertManage($r, $machine);
        $d = $r->validate([
            'note' => 'required|string',
            'machine_component_id' => 'nullable|uuid',
            'component_replacement_id' => 'nullable|uuid',
            'occurred_at' => 'nullable|date',
            'client_request_id' => 'nullable|uuid',
        ]);
        $d['performed_by'] = $r->user()->id;
        $note = app(DowntimeService::class)->addNote($event, $d);

        app(GovernanceAudit::class)->changed($r->user(), 'downtime_note.recorded', 'downtime_note', $note->id, $event->account_id, [], app(GovernanceAudit::class)->snapshot($note));

        return response()->json(['data' => $note], 201);
    }

    public function end(Request $r, DowntimeEvent $event)
    {
        $machine = Machine::with('account')->findOrFail($event->machine_id);
        $this->assertManage($r, $machine);
        $d = $r->validate([
            'actual_end_at' => 'nullable|date',
            'resolution_note' => 'nullable|string',
        ]);
        $before = app(GovernanceAudit::class)->snapshot($event);
        $event = app(DowntimeService::class)->end($event, $r->user(), $d);

        app(GovernanceAudit::class)->changed($r->user(), 'downtime_event.ended', 'downtime_event', $event->id, $event->account_id, $before, app(GovernanceAudit::class)->snapshot($event), array_keys($event->getChanges()));

        return response()->json(['data' => $event]);
    }

    public function index(Request $r)
    {
        $v = $r->validate([
            'machine_id' => 'nullable|uuid', 'branch_id' => 'nullable|uuid', 'status' => 'nullable|in:active,closed',
            'period_start' => 'nullable|date_format:Y-m-d', 'period_end' => 'nullable|date_format:Y-m-d|after_or_equal:period_start',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        // Scope every account the user belongs to by downtime.view AND the branches
        // the membership may see - listing used to filter by account only.
        $resolver = app(EffectiveCapabilityResolver::class);
        $q = DowntimeEvent::query()->where(function ($scope) use ($r, $resolver) {
            $scope->whereRaw('1 = 0');
            $accounts = Account::whereIn('id', $r->user()->memberships()->where('status', 'active')->pluck('account_id'))->get();
            foreach ($accounts as $account) {
                if (! $resolver->allows($r->user(), $account, 'downtime.view')) {
                    continue;
                }
                $allowed = $this->accountAccess->authorizedBranchIds($r->user(), $account);
                $scope->orWhere(function ($accountScope) use ($account, $allowed) {
                    $accountScope->where('account_id', $account->id);
                    if ($allowed !== null) {
                        $accountScope->whereIn('branch_id', $allowed);
                    }
                });
            }
        })->with(['machine.branch', 'machine.account', 'branch', 'notes']);

        if (! empty($v['machine_id'])) {
            $q->where('machine_id', $v['machine_id']);
        }
        if (! empty($v['branch_id'])) {
            $q->where('branch_id', $v['branch_id']);
        }
        if (! empty($v['status'])) {
            $v['status'] === 'active' ? $q->whereNull('actual_end_at') : $q->whereNotNull('actual_end_at');
        }
        if (! empty($v['period_start']) && ! empty($v['period_end'])) {
            // Window is resolved in the selected machine's/branch's timezone, else the account default.
            $tzSource = ! empty($v['machine_id']) ? Machine::with(['branch', 'account'])->find($v['machine_id']) : null;
            $tz = $tzSource ? app(MachineTimezoneResolver::class)->resolve($tzSource)
                : (! empty($v['branch_id']) ? (\App\Models\Branch::with('account')->find($v['branch_id']))?->timezone : null);
            $tz = $tz ?: (Account::whereIn('id', $r->user()->memberships()->where('status', 'active')->pluck('account_id'))->value('default_timezone') ?: 'UTC');
            $from = CarbonImmutable::parse($v['period_start'], $tz)->startOfDay()->utc();
            $to = CarbonImmutable::parse($v['period_end'], $tz)->addDay()->startOfDay()->utc();
            $q->where('actual_start_at', '<', $to)->where(fn ($w) => $w->whereNull('actual_end_at')->orWhere('actual_end_at', '>', $from));
        }

        $calc = app(DowntimeDurationCalculator::class);
        $page = $q->orderByDesc('actual_start_at')->paginate((int) ($v['per_page'] ?? 25));
        $page->getCollection()->transform(function (DowntimeEvent $e) use ($calc) {
            $start = CarbonImmutable::parse($e->actual_start_at);
            $end = $e->actual_end_at ? CarbonImmutable::parse($e->actual_end_at) : CarbonImmutable::now();
            $note = $e->notes->last();
            $row = $e->toArray();
            unset($row['notes'], $row['branch']);
            $row['machine'] = $e->machine ? ['id' => $e->machine->id, 'machine_code' => $e->machine->machine_code, 'display_name' => $e->machine->display_name] : null;
            $row['branch_name'] = $e->branch?->name;
            $row['elapsed_minutes'] = $calc->elapsedMinutes($start, $end);
            $row['production_downtime_minutes'] = $e->machine ? $calc->productionDowntimeMinutes($e->machine, $start, $end) : null;
            $row['note_count'] = $e->notes->count();
            $row['latest_note'] = $note ? ['note' => $note->note, 'occurred_at' => $note->occurred_at] : null;

            return $row;
        });

        return response()->json(['data' => $page]);
    }

    public function overview(Request $r)
    {
        $v = $r->validate([
            'account_id' => 'required|uuid', 'branch_id' => 'nullable|uuid', 'machine_id' => 'nullable|uuid', 'detail' => 'nullable|boolean',
        ] + OperationalPeriodRange::rules($r));
        $account = Account::findOrFail($v['account_id']);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $account, 'downtime.view');
        abort_unless($this->accountAccess->canAccess($r->user(), $account), 403);
        if (! empty($v['branch_id'])) {
            $branch = \App\Models\Branch::where('id', $v['branch_id'])->where('account_id', $account->id)->firstOrFail();
            abort_unless($this->branchAccess->canAccess($r->user(), $branch), 403);
        }

        $allowed = $this->accountAccess->authorizedBranchIds($r->user(), $account);
        if ($allowed !== null) {
            $allowed = $account->branches()->where('is_active', true)->whereIn('id', $allowed)->pluck('id')->all();
        }

        return response()->json(['data' => app(OwnerDowntimeOverviewService::class)->build($account->id, $v['branch_id'] ?? null, $v['machine_id'] ?? null, $v['period_start'], $v['period_end'], $allowed, (bool) ($v['detail'] ?? false))]);
    }
}
