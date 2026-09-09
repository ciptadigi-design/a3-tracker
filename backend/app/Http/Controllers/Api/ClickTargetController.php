<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineClickTarget;
use App\Models\MachineClickTargetRevision;
use App\Models\MachineOperationalCalendarException;
use App\Services\AccountAccessResolver;
use App\Services\MachineAccessResolver;
use App\Services\MachineClickTargetProjectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClickTargetController extends Controller
{
    public function __construct(private MachineClickTargetProjectionService $projection, private MachineAccessResolver $machineAccess, private AccountAccessResolver $accountAccess) {}

    private function assertRead(Request $r, Machine $machine): void
    {
        abort_unless($this->machineAccess->canAccess($r->user(), $machine), 403);
    }

    private function assertManage(Request $r, Machine $machine): void
    {
        abort_unless($this->machineAccess->canAccess($r->user(), $machine, true) && $this->accountAccess->canManageOperational($r->user(), $machine->account), 403);
    }

    public function show(Request $r, Machine $machine)
    {
        $this->assertRead($r, $machine);
        $v = $r->validate(['year' => 'required|integer|min:2000|max:2100', 'month' => 'required|integer|min:1|max:12']);

        return response()->json(['data' => $this->projection->projection($machine, (int) $v['year'], (int) $v['month'])]);
    }

    public function upsertTarget(Request $r, Machine $machine)
    {
        $this->assertManage($r, $machine);
        $d = $r->validate([
            'target_year' => 'required|integer|min:2000|max:2100',
            'target_month' => 'required|integer|min:1|max:12',
            'monthly_click_target' => 'required|integer|min:1',
            'reason' => 'nullable|string',
            'client_request_id' => 'required|uuid',
        ]);

        return DB::transaction(function () use ($r, $machine, $d) {
            $existingRequest = MachineClickTargetRevision::where('account_id', $machine->account_id)->where('client_request_id', $d['client_request_id'])->lockForUpdate()->first();
            if ($existingRequest) {
                if ((int) $existingRequest->new_target !== (int) $d['monthly_click_target'] || (int) $existingRequest->target_year !== (int) $d['target_year'] || (int) $existingRequest->target_month !== (int) $d['target_month'] || (string) $existingRequest->machine_id !== (string) $machine->id) {
                    throw new ConflictHttpException('client request id was already used for a different click target revision');
                }

                return response()->json(['data' => $this->projection->projection($machine, (int) $d['target_year'], (int) $d['target_month'])]);
            }

            $row = MachineClickTarget::where('machine_id', $machine->id)->where('target_year', $d['target_year'])->where('target_month', $d['target_month'])->lockForUpdate()->first();
            $previous = $row?->monthly_click_target;

            if ($row) {
                $row->update(['monthly_click_target' => $d['monthly_click_target'], 'updated_by' => $r->user()->id]);
            } else {
                $row = MachineClickTarget::create([
                    'account_id' => $machine->account_id,
                    'branch_id' => $machine->branch_id,
                    'machine_id' => $machine->id,
                    'target_year' => $d['target_year'],
                    'target_month' => $d['target_month'],
                    'monthly_click_target' => $d['monthly_click_target'],
                    'created_by' => $r->user()->id,
                    'updated_by' => $r->user()->id,
                ]);
            }

            $nextSequence = (int) (MachineClickTargetRevision::where('machine_id', $machine->id)->where('target_year', $d['target_year'])->where('target_month', $d['target_month'])->max('sequence')) + 1;
            MachineClickTargetRevision::create([
                'account_id' => $machine->account_id,
                'machine_id' => $machine->id,
                'target_year' => $d['target_year'],
                'target_month' => $d['target_month'],
                'previous_target' => $previous,
                'new_target' => $d['monthly_click_target'],
                'reason' => $d['reason'] ?? null,
                'changed_by' => $r->user()->id,
                'client_request_id' => $d['client_request_id'],
                'sequence' => $nextSequence,
            ]);

            return response()->json(['data' => $this->projection->projection($machine, (int) $d['target_year'], (int) $d['target_month'])], 201);
        });
    }

    public function history(Request $r, Machine $machine)
    {
        $this->assertRead($r, $machine);
        $v = $r->validate(['year' => 'nullable|integer|min:2000|max:2100', 'month' => 'nullable|integer|min:1|max:12']);
        $q = MachineClickTargetRevision::where('machine_id', $machine->id);
        if (! empty($v['year'])) {
            $q->where('target_year', $v['year']);
        }
        if (! empty($v['month'])) {
            $q->where('target_month', $v['month']);
        }

        return response()->json(['data' => $q->orderByDesc('target_year')->orderByDesc('target_month')->orderByDesc('sequence')->get()]);
    }

    public function listCalendarExceptions(Request $r, Machine $machine)
    {
        $this->assertRead($r, $machine);
        $v = $r->validate(['year' => 'required|integer|min:2000|max:2100', 'month' => 'required|integer|min:1|max:12']);
        $start = sprintf('%04d-%02d-01', $v['year'], $v['month']);
        $nextMonthStart = date('Y-m-d', strtotime("$start +1 month"));

        return response()->json(['data' => MachineOperationalCalendarException::where('machine_id', $machine->id)->where('calendar_date', '>=', $start)->where('calendar_date', '<', $nextMonthStart)->orderBy('calendar_date')->get()]);
    }

    public function createCalendarException(Request $r, Machine $machine)
    {
        $this->assertManage($r, $machine);
        $d = $r->validate([
            'calendar_date' => 'required|date_format:Y-m-d',
            'exception_type' => 'required|string|in:family_gathering,store_closed,religious_holiday,planned_maintenance,special_event,other',
            'notes' => 'nullable|string',
            'client_request_id' => 'required|uuid',
        ]);

        return DB::transaction(function () use ($r, $machine, $d) {
            $existingRequest = MachineOperationalCalendarException::where('account_id', $machine->account_id)->where('client_request_id', $d['client_request_id'])->lockForUpdate()->first();
            if ($existingRequest) {
                return response()->json(['data' => $existingRequest]);
            }

            $existing = MachineOperationalCalendarException::where('machine_id', $machine->id)->where('calendar_date', $d['calendar_date'])->lockForUpdate()->first();
            if ($existing) {
                $existing->update(['exception_type' => $d['exception_type'], 'notes' => $d['notes'] ?? null, 'excluded_from_target' => true, 'updated_by' => $r->user()->id, 'client_request_id' => $d['client_request_id']]);

                return response()->json(['data' => $existing]);
            }

            $row = MachineOperationalCalendarException::create([
                'account_id' => $machine->account_id,
                'branch_id' => $machine->branch_id,
                'machine_id' => $machine->id,
                'calendar_date' => $d['calendar_date'],
                'exception_type' => $d['exception_type'],
                'notes' => $d['notes'] ?? null,
                'excluded_from_target' => true,
                'client_request_id' => $d['client_request_id'],
                'created_by' => $r->user()->id,
                'updated_by' => $r->user()->id,
            ]);

            return response()->json(['data' => $row], 201);
        });
    }

    public function removeCalendarException(Request $r, string $exception)
    {
        $row = MachineOperationalCalendarException::find($exception);
        abort_unless($row, 404);
        $machine = Machine::find($row->machine_id);
        $this->assertManage($r, $machine);
        $row->delete();

        return response()->json(['data' => ['id' => $exception, 'deleted' => true]]);
    }
}
