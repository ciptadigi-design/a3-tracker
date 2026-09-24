<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountMembership;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineErrorCode;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceTicket;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\MachineAccessResolver;
use App\Services\MaintenanceTicketService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MaintenanceTicketsController extends Controller
{
    public function machineHistory(Request $r, Machine $machine)
    {
        $machine->loadMissing(['account', 'branch']);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $machine), 403);

        $d = $r->validate([
            'status' => 'nullable|in:OPEN,IN_PROGRESS,DONE,CANCELLED',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:10,25,50',
        ]);

        $history = MaintenanceTicket::query()
            ->where('machine_id', $machine->id)
            ->where('account_id', $machine->account_id);
        $statusCounts = (clone $history)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        if (! empty($d['status'])) {
            $history->where('status', $d['status']);
        }

        $tickets = $history
            ->with([
                'errorCode:id,code,title',
                'assignee:id,name',
                'officialErrorEntry' => fn ($entries) => $entries
                    ->select(['id', 'document_id', 'code', 'variant_key', 'classification'])
                    ->visibleTo($r->user())
                    ->whereHas('document', fn ($documents) => $documents
                        ->where(fn ($scope) => $scope
                            ->whereNull('account_id')
                            ->orWhere('account_id', $machine->account_id)))
                    ->with('document:id,title'),
            ])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate((int) ($d['per_page'] ?? 10))
            ->through(fn (MaintenanceTicket $ticket) => [
                'id' => $ticket->id,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'opened_at' => $ticket->opened_at,
                'started_at' => $ticket->started_at,
                'resolved_at' => $ticket->resolved_at,
                'assignee' => $ticket->assignee?->only(['id', 'name']),
                'error_code' => $ticket->errorCode?->only(['id', 'code', 'title']),
                'official_error_entry' => $ticket->officialErrorEntry ? [
                    'id' => $ticket->officialErrorEntry->id,
                    'code' => $ticket->officialErrorEntry->code,
                    'variant_key' => $ticket->officialErrorEntry->variant_key,
                    'classification' => $ticket->officialErrorEntry->classification,
                    'document_title' => $ticket->officialErrorEntry->document?->title,
                ] : null,
            ]);

        return response()->json(['data' => [
            'summary' => [
                'total' => (int) $statusCounts->sum(),
                'active' => (int) (($statusCounts['OPEN'] ?? 0) + ($statusCounts['IN_PROGRESS'] ?? 0)),
                'completed' => (int) ($statusCounts['DONE'] ?? 0),
            ],
            'tickets' => $tickets,
        ]]);
    }

    public function index(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        $q = MaintenanceTicket::whereIn('account_id', $ids);
        if ($r->filled('machine_id')) {
            $q->where('machine_id', $r->string('machine_id'));
        }
        if ($r->filled('status')) {
            $q->where('status', $r->string('status'));
        }

        return response()->json(['data' => $q->with(['machine', 'errorCode', 'assignee'])->orderByDesc('opened_at')->paginate(min((int) $r->integer('per_page', 25), 50))]);
    }

    public function show(Request $r, string $id)
    {
        $ticket = MaintenanceTicket::with([
            'machine.branch', 'errorCode', 'actions.performer', 'reporter', 'assignee',
        ])->findOrFail($id);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $ticket->machine), 403);
        $ticket->load([
            'officialErrorEntry' => fn ($entries) => $entries
                ->select(['id', 'document_id', 'code', 'variant_key', 'classification'])
                ->visibleTo($r->user())
                ->whereHas('document', fn ($documents) => $documents
                    ->where(fn ($scope) => $scope
                        ->whereNull('account_id')
                        ->orWhere('account_id', $ticket->account_id)))
                ->with('document:id,title'),
        ]);

        return response()->json(['data' => $ticket]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'machine_id' => 'required|uuid',
            'machine_component_id' => 'nullable|uuid',
            'error_code_id' => 'nullable|uuid',
            'official_error_entry_id' => 'nullable|uuid',
            'confirm_not_applicable' => 'nullable|boolean',
            'type' => 'nullable|in:breakdown,preventive,inspection',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'client_request_id' => 'nullable|uuid',
        ]);
        $machine = Machine::with(['account', 'branch'])->findOrFail($d['machine_id']);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $machine, true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $machine->account, 'maintenance.ticket.create');

        if (! empty($d['machine_component_id'])) {
            $mc = MachineComponent::findOrFail($d['machine_component_id']);
            if ($mc->machine_id !== $machine->id) {
                throw ValidationException::withMessages(['machine_component_id' => 'Component does not belong to this machine.']);
            }
        }
        if (! empty($d['error_code_id'])) {
            $code = MachineErrorCode::findOrFail($d['error_code_id']);
            if ($code->account_id !== null && $code->account_id !== $machine->account_id) {
                throw ValidationException::withMessages(['error_code_id' => 'Error code is not available in this scope.']);
            }
        }
        if (! empty($d['official_error_entry_id'])) {
            $official = MaintenanceOfficialErrorEntry::query()
                ->visibleTo($r->user())
                ->whereHas('document', fn ($documents) => $documents
                    ->whereNull('account_id')
                    ->orWhere('account_id', $machine->account_id))
                ->with('applicabilities')
                ->findOrFail($d['official_error_entry_id']);
            if ($this->officialApplicability($official, $machine) === 'not_applicable' && ! ($d['confirm_not_applicable'] ?? false)) {
                throw ValidationException::withMessages([
                    'official_error_entry_id' => 'Official knowledge is not applicable to this machine model without explicit acknowledgement.',
                ]);
            }
        }
        unset($d['confirm_not_applicable']);

        $ticket = app(MaintenanceTicketService::class)->create($d + [
            'account_id' => $machine->account_id,
            'branch_id' => $machine->branch_id,
            'reported_by' => $r->user()->id,
        ]);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_ticket.created', 'maintenance_ticket', $ticket->id, $ticket->account_id, [], app(GovernanceAudit::class)->snapshot($ticket));

        return response()->json(['data' => $ticket->load([
            'machine',
            'officialErrorEntry:id,document_id,code,variant_key,classification',
            'officialErrorEntry.document:id,title',
        ])], 201);
    }

    public function update(Request $r, string $id)
    {
        $ticket = MaintenanceTicket::with('machine')->findOrFail($id);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $ticket->machine, true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $ticket->machine->account, 'maintenance.ticket.update');
        $d = $r->validate(['priority' => 'nullable|in:low,normal,high,urgent', 'description' => 'nullable|string', 'title' => 'nullable|string|max:200']);
        $before = app(GovernanceAudit::class)->snapshot($ticket);
        $ticket->update($d);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_ticket.updated', 'maintenance_ticket', $ticket->id, $ticket->account_id, $before, app(GovernanceAudit::class)->snapshot($ticket), array_keys($ticket->getChanges()));

        return response()->json(['data' => $ticket]);
    }

    public function assign(Request $r, string $id)
    {
        $ticket = MaintenanceTicket::with('machine')->findOrFail($id);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $ticket->machine, true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $ticket->machine->account, 'maintenance.ticket.assign');
        $d = $r->validate(['assigned_to' => 'nullable|uuid|exists:users,id']);
        // exists:users,id alone only proves the id is *some* user - assignment must stay
        // within this ticket's own tenant, not any user in the system.
        if (! empty($d['assigned_to']) && ! AccountMembership::where('account_id', $ticket->account_id)->where('user_id', $d['assigned_to'])->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Assignee must be an active member of this account.']);
        }
        $before = app(GovernanceAudit::class)->snapshot($ticket);
        $ticket->update($d);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_ticket.assigned', 'maintenance_ticket', $ticket->id, $ticket->account_id, $before, app(GovernanceAudit::class)->snapshot($ticket), array_keys($ticket->getChanges()));

        return response()->json(['data' => $ticket]);
    }

    public function transition(Request $r, string $id)
    {
        $ticket = MaintenanceTicket::with('machine')->findOrFail($id);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $ticket->machine, true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $ticket->machine->account, 'maintenance.ticket.update');
        $d = $r->validate(['status' => 'required|in:IN_PROGRESS,DONE,CANCELLED']);
        $before = app(GovernanceAudit::class)->snapshot($ticket);
        $ticket = app(MaintenanceTicketService::class)->transition($ticket, $d['status']);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_ticket.status_changed', 'maintenance_ticket', $ticket->id, $ticket->account_id, $before, app(GovernanceAudit::class)->snapshot($ticket), array_keys($ticket->getChanges()));

        return response()->json(['data' => $ticket]);
    }

    public function storeAction(Request $r, string $id)
    {
        $ticket = MaintenanceTicket::with('machine')->findOrFail($id);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $ticket->machine, true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $ticket->machine->account, 'maintenance.action.create');
        $d = $r->validate(['action_description' => 'required|string', 'result' => 'nullable|string|max:40', 'component_replacement_id' => 'nullable|uuid', 'performed_at' => 'nullable|date']);
        $d['performed_by'] = $r->user()->id;
        $action = app(MaintenanceTicketService::class)->recordAction($ticket, $d);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_action.recorded', 'maintenance_action', $action->id, $ticket->account_id, [], app(GovernanceAudit::class)->snapshot($action));

        return response()->json(['data' => $action], 201);
    }

    private function officialApplicability(MaintenanceOfficialErrorEntry $entry, Machine $machine): string
    {
        if ($entry->applicabilities->contains(fn ($scope) => $scope->machine_model_id === $machine->machine_model_id)) {
            return 'match';
        }

        return $entry->applicabilities->isNotEmpty()
            && $entry->applicabilities->every(fn ($scope) => $scope->machine_model_id !== null)
                ? 'not_applicable'
                : 'possible';
    }
}
