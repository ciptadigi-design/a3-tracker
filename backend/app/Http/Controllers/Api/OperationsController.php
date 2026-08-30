<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CounterRequest;
use App\Models\Branch;
use App\Models\CounterReading;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\CounterPeriodService;
use App\Services\CreateCounterReading;
use App\Services\MachineAccessResolver;
use App\Services\MachineTimezoneResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperationsController extends Controller
{
    public function storeManufacturer(Request $r)
    {
        Gate::authorize('platform.manage');
        $d = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'account_id' => 'nullable|uuid']);

        return response()->json(['data' => Manufacturer::create($d)], 201);
    }

    public function storeModel(Request $r)
    {
        Gate::authorize('platform.manage');
        $d = $r->validate(['manufacturer_id' => 'required|uuid', 'model_code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'account_id' => 'nullable|uuid']);

        return response()->json(['data' => MachineModel::create($d)->load('manufacturer')], 201);
    }

    public function storeMachine(Request $r, string $branch)
    {
        $b = Branch::findOrFail($branch);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $b->account), 403);
        $d = $r->validate(['machine_model_id' => 'required|uuid', 'machine_code' => 'required|string|max:80', 'display_name' => 'required|string|max:180', 'serial_number' => 'nullable|string|max:120', 'timezone' => 'nullable|string|max:64', 'status' => 'nullable|in:active,down,maintenance,retired']);
        $m = $b->machines()->create($d + ['account_id' => $b->account_id, 'status' => $d['status'] ?? 'active']);

        return response()->json(['data' => $m], 201);
    }

    public function storePerson(Request $r, string $account)
    {
        Gate::authorize('platform.manage');
        $d = $r->validate(['name' => 'required|string|max:160', 'code' => 'nullable|string|max:64', 'linked_user_id' => 'nullable|uuid']);

        return response()->json(['data' => OperationalPerson::create($d + ['account_id' => $account])], 201);
    }

    public function assignPerson(Request $r, string $person, string $branch)
    {
        Gate::authorize('platform.manage');
        $p = OperationalPerson::findOrFail($person);
        $b = Branch::findOrFail($branch);
        abort_unless($p->account_id === $b->account_id, 403);
        $a = OperationalPersonBranch::updateOrCreate(['person_id' => $p->id, 'branch_id' => $b->id], ['account_id' => $b->account_id, 'is_active' => true]);

        return response()->json(['data' => $a], 201);
    }

    public function manufacturers()
    {
        Gate::authorize('platform.manage');

        return response()->json(['data' => Manufacturer::where('is_active', true)->orderBy('name')->get()]);
    }

    public function models()
    {
        Gate::authorize('platform.manage');

        return response()->json(['data' => MachineModel::with('manufacturer')->where('is_active', true)->orderBy('name')->get()]);
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
        $q = OperationalPerson::where('account_id', $b->account_id)->where('is_active', true)->whereHas('branches', fn ($x) => $x->where('branches.id', $b->id)->where('operational_person_branches.is_active', true));

        return response()->json(['data' => $q->orderBy('name')->get()]);
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

    public function period(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $m), 403);
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);

        return response()->json(['data' => ['from' => $d['from'], 'to' => $d['to'], 'timezone' => app(MachineTimezoneResolver::class)->resolve($m), 'usage' => app(CounterPeriodService::class)->usage($m, $d['from'], $d['to'])]]);
    }
}
