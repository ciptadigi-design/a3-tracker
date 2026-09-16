<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountMembership;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineErrorCode;
use App\Models\MaintenanceTicket;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\MachineAccessResolver;
use App\Services\MaintenanceTicketService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MaintenanceTicketsController extends Controller
{
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
        $ticket = MaintenanceTicket::with(['machine.branch', 'errorCode', 'actions.performer', 'reporter', 'assignee'])->findOrFail($id);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $ticket->machine), 403);

        return response()->json(['data' => $ticket]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'machine_id' => 'required|uuid',
            'machine_component_id' => 'nullable|uuid',
            'error_code_id' => 'nullable|uuid',
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

        $ticket = app(MaintenanceTicketService::class)->create($d + [
            'account_id' => $machine->account_id,
            'branch_id' => $machine->branch_id,
            'reported_by' => $r->user()->id,
        ]);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_ticket.created', 'maintenance_ticket', $ticket->id, $ticket->account_id, [], app(GovernanceAudit::class)->snapshot($ticket));

        return response()->json(['data' => $ticket], 201);
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
}
