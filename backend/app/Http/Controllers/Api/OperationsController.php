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
use App\Http\Resources\CounterReadingResource;
use App\Http\Resources\OperationalPersonResource;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CounterReading;
use App\Models\CounterType;
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
use App\Services\EffectiveCapabilityResolver;
use App\Services\EffectiveCounterSequence;
use App\Services\GovernanceAudit;
use App\Services\MachineAccessResolver;
use App\Services\MachineTimezoneResolver;
use App\Services\PlatformPrivilegeService;
use App\Services\ScopedReference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
        $d = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'notes' => 'nullable|string', 'account_id' => 'nullable|uuid']);
        $duplicate = Manufacturer::where('id', '!=', $id)->whereRaw('lower(trim(code)) = ?', [strtolower(trim($d['code']))])->when($d['account_id'] ?? null, fn ($q, $accountId) => $q->where('account_id', $accountId), fn ($q) => $q->whereNull('account_id'))->exists();
        abort_if($duplicate, 409, 'Manufacturer code already exists in this scope.');
        $m->update($d);

        return response()->json(['data' => $m]);
    }

    public function storeModel(MachineModelRequest $r)
    {
        return DB::transaction(function () use ($r) {
            $d = $r->validated();
            abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $d['account_id'] ?? null), 403);
            ScopedReference::activeGlobalOrOwned(Manufacturer::class, $d['manufacturer_id'], $d['account_id'] ?? null, 'manufacturer_id');
            $duplicate = MachineModel::where('manufacturer_id', $d['manufacturer_id'])->whereRaw('lower(trim(model_code)) = ?', [strtolower(trim($d['model_code']))])->when($d['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id), fn ($q) => $q->whereNull('account_id'))->exists();
            abort_if($duplicate, 409, 'Machine model code already exists in this scope.');

            $m = MachineModel::create($d);
            app(GovernanceAudit::class)->changed($r->user(), 'machine_model.created', 'machine_model', $m->id, $m->account_id, [], app(GovernanceAudit::class)->snapshot($m));

            return response()->json(['data' => $m->load('manufacturer')], 201);
        });
    }

    public function setModelStatus(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $m = MachineModel::lockForUpdate()->findOrFail($id);
            abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $m->account_id), 403);
            $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
            $before = app(GovernanceAudit::class)->snapshot($m);
            $m->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

            app(GovernanceAudit::class)->changed($r->user(), 'machine_model.updated', 'machine_model', $m->id, $m->account_id, $before, app(GovernanceAudit::class)->snapshot($m), array_keys($m->getChanges()));

            return response()->json(['data' => $m]);
        });
    }

    public function updateModel(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $m = MachineModel::lockForUpdate()->findOrFail($id);
            abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $m->account_id), 403);
            // account_id (ownership scope) is intentionally not accepted here - moving a model
            // between accounts, or between account-owned and platform-global, is not a routine
            // edit and must not be reachable by spoofing this field in the request body.
            $d = $r->validate(['manufacturer_id' => 'required|uuid', 'model_code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'machine_category' => 'nullable|string|max:40', 'color_capability' => 'nullable|string|max:20', 'description' => 'nullable|string', 'notes' => 'nullable|string']);
            ScopedReference::activeGlobalOrOwned(Manufacturer::class, $d['manufacturer_id'], $m->account_id, 'manufacturer_id');
            $duplicate = MachineModel::where('id', '!=', $id)->where('manufacturer_id', $d['manufacturer_id'])->whereRaw('lower(trim(model_code)) = ?', [strtolower(trim($d['model_code']))])->when($m->account_id, fn ($q, $accountId) => $q->where('account_id', $accountId), fn ($q) => $q->whereNull('account_id'))->exists();
            abort_if($duplicate, 409, 'Machine model code already exists in this scope.');
            $before = app(GovernanceAudit::class)->snapshot($m);
            $m->update($d);

            app(GovernanceAudit::class)->changed($r->user(), 'machine_model.updated', 'machine_model', $m->id, $m->account_id, $before, app(GovernanceAudit::class)->snapshot($m), array_keys($m->getChanges()));

            return response()->json(['data' => $m->load('manufacturer')]);
        });
    }

    public function storeMachine(MachineRequest $r, string $branch)
    {
        return DB::transaction(function () use ($r, $branch) {
            $b = Branch::lockForUpdate()->findOrFail($branch);
            abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $b->account), 403);
            $d = $r->validated();
            $model = MachineModel::with('manufacturer')->lockForUpdate()->findOrFail($d['machine_model_id']);
            abort_unless($model->is_active && ($model->account_id === null || $model->account_id === $b->account_id) && $model->manufacturer?->is_active, 422, 'Machine model is not available for this account.');
            ScopedReference::activeGlobalOrOwned(Manufacturer::class, $model->manufacturer_id, $model->account_id, 'machine_model_id');
            $m = $b->machines()->create($d + ['account_id' => $b->account_id, 'status' => $d['status'] ?? 'active']);

            app(GovernanceAudit::class)->changed($r->user(), 'machine.created', 'machine', $m->id, $b->account_id, [], app(GovernanceAudit::class)->snapshot($m));

            return response()->json(['data' => $m], 201);
        });
    }

    public function setMachineStatus(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $m = Machine::with('account')->lockForUpdate()->findOrFail($id);
            abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $m->account), 403);
            $status = $r->validate(['status' => 'required|in:active,down,maintenance,retired'])['status'];
            $before = app(GovernanceAudit::class)->snapshot($m);
            $m->update(['status' => $status]);

            app(GovernanceAudit::class)->changed($r->user(), 'machine.status_changed', 'machine', $m->id, $m->account_id, $before, app(GovernanceAudit::class)->snapshot($m), array_keys($m->getChanges()));

            return response()->json(['data' => $m]);
        });
    }

    public function updateMachine(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $m = Machine::with('account')->lockForUpdate()->findOrFail($id);
            abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $m->account), 403);
            $d = $r->validate(['machine_model_id' => 'required|uuid', 'machine_code' => 'required|string|max:80', 'display_name' => 'required|string|max:180', 'serial_number' => 'nullable|string|max:120', 'timezone' => 'nullable|string|max:64', 'status' => 'nullable|in:active,down,maintenance,retired']);
            ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'], $m->account_id, 'machine_model_id');
            $model = MachineModel::lockForUpdate()->findOrFail($d['machine_model_id']);
            ScopedReference::activeGlobalOrOwned(Manufacturer::class, $model->manufacturer_id, $model->account_id, 'machine_model_id');
            $before = app(GovernanceAudit::class)->snapshot($m);
            $m->update($d);

            app(GovernanceAudit::class)->changed($r->user(), 'machine.updated', 'machine', $m->id, $m->account_id, $before, app(GovernanceAudit::class)->snapshot($m), array_keys($m->getChanges()));

            return response()->json(['data' => $m->load('model.manufacturer')]);
        });
    }

    public function storePerson(OperationalPersonRequest $r, string $account)
    {
        return DB::transaction(function () use ($r, $account) {
            $tenant = Account::lockForUpdate()->findOrFail($account);
            app(EffectiveCapabilityResolver::class)->authorize($r->user(), $tenant, 'operational_people.manage');
            $d = $this->personValues($r);

            $p = OperationalPerson::create($d + ['account_id' => $tenant->id]);
            app(GovernanceAudit::class)->changed($r->user(), 'operational_person.created', 'operational_person', $p->id, $p->account_id, [], app(GovernanceAudit::class)->snapshot($p));

            return response()->json(['data' => $p], 201);
        });
    }

    public function setPersonStatus(Request $r, string $id)
    {
        return DB::transaction(function () use ($r, $id) {
            $p = OperationalPerson::lockForUpdate()->findOrFail($id);
            app(EffectiveCapabilityResolver::class)->authorize($r->user(), $p->account, 'operational_people.manage');
            $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
            $before = app(GovernanceAudit::class)->snapshot($p);
            $p->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

            app(GovernanceAudit::class)->changed($r->user(), ($active ? 'operational_person.reactivated' : 'operational_person.deactivated'), 'operational_person', $p->id, $p->account_id, $before, app(GovernanceAudit::class)->snapshot($p), array_keys($p->getChanges()));

            return response()->json(['data' => $p]);
        });
    }

    public function updatePerson(OperationalPersonRequest $r, string $account, string $id)
    {
        return DB::transaction(function () use ($r, $account, $id) {
            $tenant = Account::lockForUpdate()->findOrFail($account);
            app(EffectiveCapabilityResolver::class)->authorize($r->user(), $tenant, 'operational_people.manage');
            $p = OperationalPerson::where('account_id', $account)->lockForUpdate()->findOrFail($id);
            $d = $this->personValues($r);
            $before = app(GovernanceAudit::class)->snapshot($p);
            $p->update($d);

            app(GovernanceAudit::class)->changed($r->user(), 'operational_person.updated', 'operational_person', $p->id, $p->account_id, $before, app(GovernanceAudit::class)->snapshot($p), array_keys($p->getChanges()));

            return response()->json(['data' => $p]);
        });
    }

    public function assignPerson(PersonBranchAssignmentRequest $r, string $person, string $branch)
    {
        return DB::transaction(function () use ($r, $person, $branch) {
            $p = OperationalPerson::lockForUpdate()->findOrFail($person);
            app(EffectiveCapabilityResolver::class)->authorize($r->user(), $p->account, 'operational_people.manage');
            $b = Branch::lockForUpdate()->findOrFail($branch);
            abort_unless($p->account_id === $b->account_id, 403);
            $before = OperationalPersonBranch::where('person_id', $p->id)->where('branch_id', $b->id)->first();
            $before = $before ? app(GovernanceAudit::class)->snapshot($before) : [];
            $active = $r->validated()['is_active'] ?? true;
            $a = OperationalPersonBranch::updateOrCreate(['person_id' => $p->id, 'branch_id' => $b->id], ['account_id' => $b->account_id, 'is_active' => $active, 'can_record_counter' => $active ? ($r->validated()['can_record_counter'] ?? false) : false]);

            app(GovernanceAudit::class)->changed($r->user(), 'operational_person.branch_assignment_changed', 'operational_person_branch', $a->id, $p->account_id, $before, app(GovernanceAudit::class)->snapshot($a), array_keys($a->getChanges()));

            return response()->json(['data' => $a], 201);
        });
    }

    public function replacePersonBranches(Request $r, string $person)
    {
        return DB::transaction(function () use ($r, $person) {
            $validated = $r->validate([
                'assignments' => 'present|array',
                'assignments.*.branch_id' => 'required|uuid|distinct',
                'assignments.*.can_record_counter' => 'required|boolean',
            ]);

            $result = DB::transaction(function () use ($r, $person, $validated) {
                $operationalPerson = OperationalPerson::lockForUpdate()->findOrFail($person);
                app(EffectiveCapabilityResolver::class)->authorize($r->user(), $operationalPerson->account, 'operational_people.manage');
                $audit = app(GovernanceAudit::class);
                $before = OperationalPersonBranch::where('person_id', $operationalPerson->id)->get()->keyBy('id')->map(fn ($assignment) => $audit->snapshot($assignment));
                $requested = collect($validated['assignments'])->keyBy('branch_id');
                $branches = Branch::where('account_id', $operationalPerson->account_id)
                    ->whereIn('id', $requested->keys())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn ($branch) => (string) $branch->id);

                if ($branches->count() !== $requested->count()) {
                    throw ValidationException::withMessages(['assignments' => 'Every requested Branch must belong to the operational person account.']);
                }
                if ($branches->contains(fn ($branch) => ! $branch->is_active)) {
                    throw ValidationException::withMessages(['assignments' => 'Inactive Branch assignments cannot be activated.']);
                }

                OperationalPersonBranch::where('person_id', $operationalPerson->id)
                    ->where('is_active', true)
                    ->whereNotIn('branch_id', $requested->keys())
                    ->update(['is_active' => false, 'can_record_counter' => false, 'updated_at' => now()]);

                foreach ($requested as $branchId => $assignment) {
                    OperationalPersonBranch::updateOrCreate(
                        ['person_id' => $operationalPerson->id, 'branch_id' => $branchId],
                        ['account_id' => $operationalPerson->account_id, 'is_active' => true, 'can_record_counter' => $assignment['can_record_counter']],
                    );
                }

                foreach (OperationalPersonBranch::where('person_id', $operationalPerson->id)->get() as $assignment) {
                    $audit->changed($r->user(), 'operational_person.branch_assignment_changed', 'operational_person_branch', $assignment->id, $operationalPerson->account_id, $before[$assignment->id] ?? [], $audit->snapshot($assignment));
                }

                return $operationalPerson->load(['branchAssignments.branch']);
            });

            return response()->json(['data' => (new OperationalPersonResource($result))->resolve($r)]);
        });
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
        $tenant = Account::findOrFail($account);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $tenant, 'operational_people.manage');

        $page = OperationalPerson::where('account_id', $account)->with('branchAssignments.branch')->orderBy('name')->paginate(min((int) $r->integer('per_page', 25), 50));
        $page->through(fn ($person) => (new OperationalPersonResource($person))->resolve($r));

        return response()->json(['data' => $page]);
    }

    private function personValues(OperationalPersonRequest $request): array
    {
        $values = $request->validated();
        if (app(PlatformPrivilegeService::class)->isSuperuser($request->user()) === false) {
            // Linking an operational record to global identity remains a platform path.
            unset($values['linked_user_id']);
        }

        return $values;
    }

    public function counters(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $m), 403);
        $type = CounterType::whereRaw('lower(code)=?', ['total_impressions'])->first();

        // Usage/previous-value for every currently-effective reading is derived
        // once from the single canonical sequence, so this list can never
        // disagree with Machine Cost totals or Daily Click Trend after a
        // correction or void. Voided/superseded rows are still listed (for
        // audit) but carry no usage/previous value since they are outside the
        // effective sequence.
        $usageById = [];
        $previousById = [];
        if ($type) {
            foreach (app(EffectiveCounterSequence::class)->forMachine($m->id, $type->id) as $row) {
                $usageById[$row->id] = $row->usage;
                $previousById[$row->id] = $row->usage === null ? null : (float) $row->reading_value - $row->usage;
            }
        }

        $q = CounterReading::where('machine_id', $m->id)->orderByDesc('observed_at')->orderByDesc('created_at')->orderByDesc('id');
        $page = $q->paginate(min((int) $r->integer('per_page', 25), 50));
        $page->getCollection()->transform(function ($row) use ($usageById, $previousById) {
            $row->usage = $usageById[$row->id] ?? null;
            $row->setAttribute('previous_value_override', $previousById[$row->id] ?? null);

            return $row;
        });

        return response()->json(['data' => CounterReadingResource::collection($page)->response()->getData(true)]);
    }

    public function createCounter(CounterRequest $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        $row = app(CreateCounterReading::class)->execute($r->user(), $m, $r->validated());

        return response()->json(['data' => new CounterReadingResource($row->load('operator'))], 201);
    }

    public function correctCounter(CorrectionRequest $r, string $reading)
    {
        $row = CounterReading::with('machine.account')->findOrFail($reading);
        $corrected = app(CorrectCounterReading::class)->execute($r->user(), $row, $r->validated());

        return response()->json(['data' => new CounterReadingResource($corrected->load('operator'))]);
    }

    public function period(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $m), 403);
        $d = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);

        return response()->json(['data' => ['from' => $d['from'], 'to' => $d['to'], 'timezone' => app(MachineTimezoneResolver::class)->resolve($m), 'usage' => app(CounterPeriodService::class)->usage($m, $d['from'], $d['to'])]]);
    }
}
