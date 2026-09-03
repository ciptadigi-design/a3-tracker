<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectionRequest;
use App\Http\Requests\CounterRequest;
use App\Http\Requests\MachineModelRequest;
use App\Http\Requests\MachineRequest;
use App\Http\Requests\ManufacturerRequest;
use App\Http\Requests\OperationalPersonRequest;
use App\Http\Requests\PersonBranchAssignmentRequest;
use App\Http\Resources\OperationalPersonResource;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CounterReading;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\CorrectCounterReading;
use App\Services\CounterPeriodService;
use App\Services\CreateCounterReading;
use App\Services\MachineAccessResolver;
use App\Services\MachineTimezoneResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OperationsController extends Controller
{
    public function storeManufacturer(ManufacturerRequest $r)
    {
        Gate::authorize('platform.manage');
        $d = $r->validated();
        $duplicate = Manufacturer::whereRaw('lower(trim(code)) = ?', [strtolower(trim($d['code']))])->when($d['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id), fn ($q) => $q->whereNull('account_id'))->exists();
        abort_if($duplicate, 409, 'Manufacturer code already exists in this scope.');

        return response()->json(['data' => Manufacturer::create($d)], 201);
    }

    public function setManufacturerStatus(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $m = Manufacturer::findOrFail($id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        if (! $active && $m->models()->where('is_active', true)->exists()) {
            throw new ConflictHttpException('manufacturer has active machine models');
        } $m->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $m]);
    }

    public function updateManufacturer(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $m = Manufacturer::findOrFail($id);
        $d = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'account_id' => 'nullable|uuid']);
        $m->update($d);

        return response()->json(['data' => $m]);
    }

    public function storeModel(MachineModelRequest $r)
    {
        Gate::authorize('platform.manage');
        $d = $r->validated();
        $duplicate = MachineModel::where('manufacturer_id', $d['manufacturer_id'])->whereRaw('lower(trim(model_code)) = ?', [strtolower(trim($d['model_code']))])->when($d['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id), fn ($q) => $q->whereNull('account_id'))->exists();
        abort_if($duplicate, 409, 'Machine model code already exists in this scope.');

        return response()->json(['data' => MachineModel::create($d)->load('manufacturer')], 201);
    }

    public function setModelStatus(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $m = MachineModel::findOrFail($id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        $m->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $m]);
    }

    public function updateModel(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $m = MachineModel::findOrFail($id);
        $d = $r->validate(['manufacturer_id' => 'required|uuid', 'model_code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'account_id' => 'nullable|uuid']);
        $m->update($d);

        return response()->json(['data' => $m->load('manufacturer')]);
    }

    public function storeMachine(MachineRequest $r, string $branch)
    {
        $b = Branch::findOrFail($branch);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $b->account), 403);
        $d = $r->validated();
        $model = MachineModel::with('manufacturer')->findOrFail($d['machine_model_id']);
        abort_unless($model->is_active && ($model->account_id === null || $model->account_id === $b->account_id) && $model->manufacturer?->is_active, 422, 'Machine model is not available for this account.');
        $m = $b->machines()->create($d + ['account_id' => $b->account_id, 'status' => $d['status'] ?? 'active']);

        return response()->json(['data' => $m], 201);
    }

    public function setMachineStatus(Request $r, string $id)
    {
        $m = Machine::with('account')->findOrFail($id);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $m->account), 403);
        $status = $r->validate(['status' => 'required|in:active,down,maintenance,retired'])['status'];
        $m->update(['status' => $status]);

        return response()->json(['data' => $m]);
    }

    public function updateMachine(Request $r, string $id)
    {
        $m = Machine::with('account')->findOrFail($id);
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $m->account), 403);
        $d = $r->validate(['machine_model_id' => 'required|uuid', 'machine_code' => 'required|string|max:80', 'display_name' => 'required|string|max:180', 'serial_number' => 'nullable|string|max:120', 'timezone' => 'nullable|string|max:64', 'status' => 'nullable|in:active,down,maintenance,retired']);
        $m->update($d);

        return response()->json(['data' => $m->load('model.manufacturer')]);
    }

    public function storePerson(OperationalPersonRequest $r, string $account)
    {
        Gate::authorize('platform.manage');
        $d = $r->validated();

        return response()->json(['data' => OperationalPerson::create($d + ['account_id' => $account])], 201);
    }

    public function setPersonStatus(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $p = OperationalPerson::findOrFail($id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        $p->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $p]);
    }

    public function updatePerson(Request $r, string $account, string $id)
    {
        Gate::authorize('platform.manage');
        $p = OperationalPerson::where('account_id', $account)->findOrFail($id);
        $d = $r->validate(['name' => 'required|string|max:160', 'code' => 'nullable|string|max:64', 'linked_user_id' => 'nullable|uuid']);
        $p->update($d);

        return response()->json(['data' => $p]);
    }

    public function assignPerson(PersonBranchAssignmentRequest $r, string $person, string $branch)
    {
        Gate::authorize('platform.manage');
        $p = OperationalPerson::findOrFail($person);
        $b = Branch::findOrFail($branch);
        abort_unless($p->account_id === $b->account_id, 403);
        $active = $r->validated()['is_active'] ?? true;
        $a = OperationalPersonBranch::updateOrCreate(['person_id' => $p->id, 'branch_id' => $b->id], ['account_id' => $b->account_id, 'is_active' => $active, 'can_record_counter' => $active ? ($r->validated()['can_record_counter'] ?? false) : false]);

        return response()->json(['data' => $a], 201);
    }

    public function manufacturers(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        if ($r->filled('account_id')) {
            $account = Account::findOrFail($r->string('account_id')->toString());
            abort_unless(app(AccountAccessResolver::class)->canAccess($r->user(), $account), 403);
            $ids = collect([$account->id]);
        }

        return response()->json(['data' => Manufacturer::where('is_active', true)->where(function ($q) use ($ids) {
            $q->whereNull('account_id')->orWhereIn('account_id', $ids);
        })->orderBy('name')->get()]);
    }

    public function models(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        if ($r->filled('account_id')) {
            $account = Account::findOrFail($r->string('account_id')->toString());
            abort_unless(app(AccountAccessResolver::class)->canAccess($r->user(), $account), 403);
            $ids = collect([$account->id]);
        }

        return response()->json(['data' => MachineModel::with('manufacturer')->where('is_active', true)->where(function ($q) use ($ids) {
            $q->whereNull('account_id')->orWhereIn('account_id', $ids);
        })->orderBy('name')->get()]);
    }

    public function machines(Request $r, string $branch)
    {
        $b = Branch::findOrFail($branch);
        abort_unless(app(BranchAccessResolver::class)->canAccess($r->user(), $b), 403);

        return response()->json(['data' => $b->machines()->with('model.manufacturer')->where('status', 'active')->orderBy('display_name')->paginate(min((int) $r->integer('per_page', 25), 50))]);
    }

    public function machine(Request $r, string $id)
    {
        $m = Machine::with('model.manufacturer')->findOrFail($id);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $m), 403);

        return response()->json(['data' => $m]);
    }

    public function people(Request $r, string $branch)
    {
        $b = Branch::findOrFail($branch);
        abort_unless(app(BranchAccessResolver::class)->canAccess($r->user(), $b), 403);
        $q = OperationalPerson::where('account_id', $b->account_id)->where('is_active', true)
            ->with(['branchAssignments' => fn ($x) => $x->where('branch_id', $b->id)->where('is_active', true)->with('branch')])
            ->whereHas('branchAssignments', fn ($x) => $x->where('branch_id', $b->id)->where('is_active', true));

        return response()->json(['data' => OperationalPersonResource::collection($q->orderBy('name')->get())]);
    }

    public function governancePeople(Request $r, string $account)
    {
        Gate::authorize('platform.manage');

        $page = OperationalPerson::where('account_id', $account)->with('branchAssignments.branch')->orderBy('name')->paginate(min((int) $r->integer('per_page', 25), 50));
        $page->through(fn ($person) => (new OperationalPersonResource($person))->resolve($r));

        return response()->json(['data' => $page]);
    }

    public function counters(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $m), 403);
        $q = CounterReading::where('machine_id', $m->id)->with(['operator', 'previous'])->orderByDesc('observed_at')->orderByDesc('created_at');

        $page = $q->paginate(min((int) $r->integer('per_page', 25), 50));
        $page->getCollection()->transform(function ($row) {
            $row->usage = $row->previous_reading_id ? (float) $row->reading_value - (float) optional($row->previous)->reading_value : null;

            return $row;
        });

        return response()->json(['data' => $page]);
    }

    public function createCounter(CounterRequest $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        $row = app(CreateCounterReading::class)->execute($r->user(), $m, $r->validated());

        return response()->json(['data' => $row->load('operator')], 201);
    }

    public function correctCounter(CorrectionRequest $r, string $reading)
    {
        $row = CounterReading::with('machine.account')->findOrFail($reading);
        $corrected = app(CorrectCounterReading::class)->execute($r->user(), $row, $r->validated());

        return response()->json(['data' => $corrected->load('operator')]);
    }

    public function period(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $m), 403);
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);

        return response()->json(['data' => ['from' => $d['from'], 'to' => $d['to'], 'timezone' => app(MachineTimezoneResolver::class)->resolve($m), 'usage' => app(CounterPeriodService::class)->usage($m, $d['from'], $d['to'])]]);
    }
}
