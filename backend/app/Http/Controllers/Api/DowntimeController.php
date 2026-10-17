<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DowntimeEvent;
use App\Models\Machine;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\DowntimeService;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\MachineAccessResolver;
use App\Services\OperationalPeriodRange;
use App\Services\OwnerDowntimeOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        $q = DowntimeEvent::whereIn('account_id', $ids)->with('machine');
        if ($r->filled('machine_id')) {
            $q->where('machine_id', $r->string('machine_id'));
        }
        if ($r->filled('branch_id')) {
            $q->where('branch_id', $r->string('branch_id'));
        }
        if ($r->filled('status')) {
            $r->string('status') === 'active' ? $q->whereNull('actual_end_at') : $q->whereNotNull('actual_end_at');
        }

        return response()->json(['data' => $q->orderByDesc('actual_start_at')->paginate(min((int) $r->integer('per_page', 25), 50))]);
    }

    public function overview(Request $r)
    {
        $v = $r->validate([
            'account_id' => 'required|uuid', 'branch_id' => 'nullable|uuid', 'machine_id' => 'nullable|uuid',
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

        $periodStart = Carbon::createFromFormat('Y-m-d', $v['period_start'])->startOfDay();
        $periodEnd = Carbon::createFromFormat('Y-m-d', $v['period_end'])->addDay()->startOfDay();

        return response()->json(['data' => app(OwnerDowntimeOverviewService::class)->build($account->id, $v['branch_id'] ?? null, $v['machine_id'] ?? null, \Carbon\CarbonImmutable::parse($periodStart), \Carbon\CarbonImmutable::parse($periodEnd), $allowed)]);
    }
}
