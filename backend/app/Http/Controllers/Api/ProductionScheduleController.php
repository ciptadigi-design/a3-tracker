<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchProductionSchedule;
use App\Models\Machine;
use App\Models\MachineProductionScheduleOverride;
use App\Models\ProductionScheduleException;
use App\Services\BranchAccessResolver;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\MachineAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionScheduleController extends Controller
{
    public function __construct(private BranchAccessResolver $branchAccess, private MachineAccessResolver $machineAccess) {}

    private const DAY_RULES = [
        'days.*.day_of_week' => 'required|integer|min:0|max:6',
        'days.*.is_operating' => 'required|boolean',
        'days.*.start_time' => 'nullable|date_format:H:i',
        'days.*.end_time' => 'nullable|date_format:H:i',
        'days.*.crosses_midnight' => 'nullable|boolean',
    ];

    private function assertBranchManage(Request $r, Branch $branch): void
    {
        abort_unless($this->branchAccess->canAccess($r->user(), $branch) && app(EffectiveCapabilityResolver::class)->allows($r->user(), $branch->account, 'downtime.schedule.manage'), 403);
    }

    /** An operating window must end after it starts (unless it crosses midnight, where start may not equal end). */
    private function assertTimeOrder(?string $start, ?string $end, bool $crossesMidnight, string $field): void
    {
        if (! $start || ! $end) {
            return;
        }
        if ($crossesMidnight ? $start === $end : $end <= $start) {
            throw ValidationException::withMessages([$field => 'End time must be after start time (or mark the window as crossing midnight).']);
        }
    }

    private function validateDays(Request $r): array
    {
        $v = $r->validate(['days' => 'required|array|size:7'] + self::DAY_RULES);
        $seen = [];
        foreach ($v['days'] as $day) {
            if (($day['is_operating'] ?? false) && (empty($day['start_time']) || empty($day['end_time']))) {
                throw ValidationException::withMessages(['days' => 'start_time and end_time are required for an operating day.']);
            }
            if ($day['is_operating'] ?? false) {
                $this->assertTimeOrder($day['start_time'], $day['end_time'], (bool) ($day['crosses_midnight'] ?? false), 'days');
            }
            $seen[$day['day_of_week']] = true;
        }
        if (count($seen) !== 7) {
            throw ValidationException::withMessages(['days' => 'Exactly one row per day_of_week (0-6) is required.']);
        }

        return $v['days'];
    }

    public function showBranchSchedule(Request $r, Branch $branch)
    {
        abort_unless($this->branchAccess->canAccess($r->user(), $branch), 403);

        return response()->json(['data' => BranchProductionSchedule::where('branch_id', $branch->id)->orderBy('day_of_week')->get()]);
    }

    public function putBranchSchedule(Request $r, Branch $branch)
    {
        $this->assertBranchManage($r, $branch);
        $days = $this->validateDays($r);

        return DB::transaction(function () use ($r, $branch, $days) {
            BranchProductionSchedule::where('branch_id', $branch->id)->delete();
            $rows = collect($days)->map(fn ($day) => BranchProductionSchedule::create([
                'account_id' => $branch->account_id,
                'branch_id' => $branch->id,
                'day_of_week' => $day['day_of_week'],
                'is_operating' => $day['is_operating'],
                'start_time' => $day['start_time'] ?? null,
                'end_time' => $day['end_time'] ?? null,
                'crosses_midnight' => $day['crosses_midnight'] ?? false,
            ]));
            foreach ($rows as $row) {
                app(GovernanceAudit::class)->record($r->user(), 'branch_production_schedule.set', 'branch_production_schedule', $row->id, $branch->account_id, ['changes' => app(GovernanceAudit::class)->snapshot($row)]);
            }

            return response()->json(['data' => $rows->sortBy('day_of_week')->values()], 201);
        });
    }

    public function showMachineOverride(Request $r, Machine $machine)
    {
        abort_unless($this->machineAccess->canAccess($r->user(), $machine), 403);

        return response()->json(['data' => MachineProductionScheduleOverride::where('machine_id', $machine->id)->orderBy('day_of_week')->get()]);
    }

    public function putMachineOverride(Request $r, Machine $machine)
    {
        abort_unless($this->machineAccess->canAccess($r->user(), $machine) && app(EffectiveCapabilityResolver::class)->allows($r->user(), $machine->account, 'downtime.schedule.manage'), 403);
        $days = $this->validateDays($r);

        return DB::transaction(function () use ($r, $machine, $days) {
            MachineProductionScheduleOverride::where('machine_id', $machine->id)->delete();
            $rows = collect($days)->map(fn ($day) => MachineProductionScheduleOverride::create([
                'account_id' => $machine->account_id,
                'branch_id' => $machine->branch_id,
                'machine_id' => $machine->id,
                'day_of_week' => $day['day_of_week'],
                'is_operating' => $day['is_operating'],
                'start_time' => $day['start_time'] ?? null,
                'end_time' => $day['end_time'] ?? null,
                'crosses_midnight' => $day['crosses_midnight'] ?? false,
            ]));
            foreach ($rows as $row) {
                app(GovernanceAudit::class)->record($r->user(), 'machine_production_schedule_override.set', 'machine_production_schedule_override', $row->id, $machine->account_id, ['changes' => app(GovernanceAudit::class)->snapshot($row)]);
            }

            return response()->json(['data' => $rows->sortBy('day_of_week')->values()], 201);
        });
    }

    /** Disables a machine's override so it inherits the branch schedule again. */
    public function deleteMachineOverride(Request $r, Machine $machine)
    {
        abort_unless($this->machineAccess->canAccess($r->user(), $machine) && app(EffectiveCapabilityResolver::class)->allows($r->user(), $machine->account, 'downtime.schedule.manage'), 403);

        return DB::transaction(function () use ($r, $machine) {
            $rows = MachineProductionScheduleOverride::where('machine_id', $machine->id)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $before = app(GovernanceAudit::class)->snapshot($row);
                $row->delete();
                app(GovernanceAudit::class)->record($r->user(), 'machine_production_schedule_override.removed', 'machine_production_schedule_override', $row->id, $machine->account_id, ['changes' => $before]);
            }

            return response()->json(['data' => ['machine_id' => $machine->id, 'removed' => $rows->count()]]);
        });
    }

    public function listExceptions(Request $r, Branch $branch)
    {
        abort_unless($this->branchAccess->canAccess($r->user(), $branch), 403);
        $v = $r->validate(['year' => 'required|integer|min:2000|max:2100', 'month' => 'required|integer|min:1|max:12']);
        $start = sprintf('%04d-%02d-01', $v['year'], $v['month']);
        $nextMonthStart = date('Y-m-d', strtotime("$start +1 month"));

        return response()->json(['data' => ProductionScheduleException::where('branch_id', $branch->id)->where('exception_date', '>=', $start)->where('exception_date', '<', $nextMonthStart)->orderBy('exception_date')->get()]);
    }

    public function createException(Request $r, Branch $branch)
    {
        $this->assertBranchManage($r, $branch);
        $d = $r->validate([
            'machine_id' => 'nullable|uuid',
            'exception_date' => 'required|date_format:Y-m-d',
            'type' => 'required|string|in:holiday,overtime,extra_shift,closed,other',
            'is_operating' => 'required|boolean',
            'override_start_time' => 'nullable|date_format:H:i',
            'override_end_time' => 'nullable|date_format:H:i',
            'crosses_midnight' => 'nullable|boolean',
            'note' => 'nullable|string',
        ]);
        if ($d['is_operating'] && (empty($d['override_start_time']) || empty($d['override_end_time']))) {
            throw ValidationException::withMessages(['override_start_time' => 'An operating exception requires both override start and end times.']);
        }
        if ($d['is_operating']) {
            $this->assertTimeOrder($d['override_start_time'], $d['override_end_time'], (bool) ($d['crosses_midnight'] ?? false), 'override_start_time');
        }
        if (! empty($d['machine_id'])) {
            $machine = Machine::where('id', $d['machine_id'])->where('branch_id', $branch->id)->firstOrFail();
        }

        return DB::transaction(function () use ($r, $branch, $d) {
            // See the migration comment: NULL machine_id uniqueness can't be enforced
            // by a DB constraint, so this check-then-insert is an accepted trade-off
            // for this low-frequency, admin-entered data.
            $existing = ProductionScheduleException::where('branch_id', $branch->id)
                ->whereDate('exception_date', $d['exception_date'])
                ->where(fn ($q) => empty($d['machine_id']) ? $q->whereNull('machine_id') : $q->where('machine_id', $d['machine_id']))
                ->lockForUpdate()->first();
            if ($existing) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('An exception already exists for this date and scope.');
            }
            $row = ProductionScheduleException::create($d + [
                'account_id' => $branch->account_id,
                'branch_id' => $branch->id,
                'created_by' => $r->user()->id,
            ]);
            app(GovernanceAudit::class)->record($r->user(), 'production_schedule_exception.created', 'production_schedule_exception', $row->id, $branch->account_id, ['changes' => app(GovernanceAudit::class)->snapshot($row)]);

            return response()->json(['data' => $row], 201);
        });
    }

    public function deleteException(Request $r, string $exception)
    {
        return DB::transaction(function () use ($r, $exception) {
            $row = ProductionScheduleException::lockForUpdate()->findOrFail($exception);
            $branch = Branch::findOrFail($row->branch_id);
            $this->assertBranchManage($r, $branch);
            $before = app(GovernanceAudit::class)->snapshot($row);
            $row->delete();
            app(GovernanceAudit::class)->record($r->user(), 'production_schedule_exception.deleted', 'production_schedule_exception', $row->id, $branch->account_id, ['changes' => $before]);

            return response()->json(['data' => ['id' => $exception, 'deleted' => true]]);
        });
    }
}
