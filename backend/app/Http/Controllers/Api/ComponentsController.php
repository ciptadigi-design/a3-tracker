<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ComponentCatalog;
use App\Models\CounterReading;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineComponentExclusion;
use App\Models\MachineModel;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\AccountAccessResolver;
use App\Services\ComponentConfigurationService;
use App\Services\MachineAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ComponentsController extends Controller
{
    private function authorizeCatalogScope(Request $r, ?string $accountId): void
    {
        abort_unless(app(AccountAccessResolver::class)->canManageCatalogScope($r->user(), $accountId), 403);
    }

    // M2.18.1 (closes M2.18 audit BLOCKER B1): machine-component configuration
    // (which components exist on a machine, and how) is master-data scoped to
    // the machine's account - the same authorization tier storeMachine()/
    // updateMachine()/setMachineStatus() already use for machine-level
    // configuration, not merely "can this user read/access the machine"
    // (MachineAccessResolver::canAccess() only proves branch-scoped
    // visibility, not a role's permission to reconfigure master data).
    private function authorizeComponentConfiguration(Request $r, Machine $machine): void
    {
        abort_unless(app(AccountAccessResolver::class)->canManageOperational($r->user(), $machine->account), 403);
    }

    // M2.17.5.2 Part B2: canonical threshold semantics are healthy > watch > warning >
    // critical >= 0. Only enforced when all four are present in this request - a
    // partial edit (e.g. notes-only) must not be forced to resend every threshold.
    private function validateThresholdOrdering(array $d): void
    {
        $keys = ['healthy_threshold_percent', 'watch_threshold_percent', 'warning_threshold_percent', 'critical_threshold_percent'];
        if (count(array_intersect_key(array_flip($keys), $d)) < 4) {
            return;
        }
        [$healthy, $watch, $warning, $critical] = array_map(fn ($k) => (float) $d[$k], $keys);
        if (! ($healthy > $watch && $watch > $warning && $warning > $critical && $critical >= 0)) {
            throw ValidationException::withMessages(['critical_threshold_percent' => 'Thresholds must satisfy healthy > watch > warning > critical >= 0.']);
        }
    }

    public function catalogs(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');

        return response()->json(['data' => ComponentCatalog::where('is_active', true)->where(fn ($q) => $q->whereNull('account_id')->orWhereIn('account_id', $ids))->orderBy('name')->get()]);
    }

    public function storeCatalog(Request $r)
    {
        // M2.17.5.2: manufacturer_id is optional metadata on the Component Catalog
        // identity - it does NOT imply machine-model compatibility (that stays
        // exclusively configured through Model Profiles/slots), so it deliberately
        // gets no additional scope check beyond "is this a real manufacturer row",
        // matching the resolveOperator()-style read-through-validate pattern used
        // elsewhere for optional foreign selections.
        $d = $r->validate(['account_id' => 'nullable|uuid', 'manufacturer_id' => 'nullable|uuid|exists:manufacturers,id', 'code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'description' => 'nullable|string', 'category' => 'nullable|string|max:80']);
        $this->authorizeCatalogScope($r, $d['account_id'] ?? null);

        $code = strtoupper(trim($d['code']));
        if (ComponentCatalog::whereRaw('UPPER(TRIM(code)) = ?', [$code])->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $d['account_id'] ?? null))->exists()) {
            throw new ConflictHttpException('[DUPLICATE_COMPONENT_CODE] A Component Catalog entry with this code already exists in the selected scope.');
        }
        $d['code'] = $code;

        return response()->json(['data' => ComponentCatalog::create($d)], 201);
    }

    public function updateCatalog(Request $r, string $id)
    {
        $c = ComponentCatalog::findOrFail($id);
        $this->authorizeCatalogScope($r, $c->account_id);
        $d = $r->validate(['manufacturer_id' => 'nullable|uuid|exists:manufacturers,id', 'code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'description' => 'nullable|string', 'category' => 'nullable|string|max:80']);
        $c->update($d);

        return response()->json(['data' => $c]);
    }

    public function setCatalogStatus(Request $r, string $id)
    {
        $c = ComponentCatalog::findOrFail($id);
        $this->authorizeCatalogScope($r, $c->account_id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        if (! $active && ModelProfileSlot::where('component_id', $c->id)->where('is_active', true)->exists()) {
            throw new ConflictHttpException('catalog is referenced by an active profile slot');
        }$c->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $c]);
    }

    public function profiles(Request $r, string $model)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');

        return response()->json(['data' => ModelProfile::where('machine_model_id', $model)->where(fn ($q) => $q->whereNull('account_id')->orWhereIn('account_id', $ids))->with(['slots.component'])->where('is_active', true)->get()]);
    }

    public function storeProfile(Request $r, string $model)
    {
        $m = MachineModel::findOrFail($model);
        $d = $r->validate(['component_id' => 'required|uuid', 'slot_code' => 'required|string|max:80', 'slot_name' => 'nullable|string|max:160', 'display_order' => 'nullable|integer|min:0', 'tracking_method' => 'nullable|in:counter_based', 'baseline_expected_clicks' => 'nullable|integer|min:1', 'healthy_threshold_percent' => 'nullable|numeric|min:0|max:100', 'watch_threshold_percent' => 'nullable|numeric|min:0|max:100', 'warning_threshold_percent' => 'nullable|numeric|min:0|max:100', 'critical_threshold_percent' => 'nullable|numeric|min:0|max:100', 'adaptive_enabled' => 'nullable|boolean', 'notes' => 'nullable|string']);
        $this->validateThresholdOrdering($d);
        // An account-owned model is always scoped to its own account; a platform-global
        // model (account_id null) has no account context to assign into, so component
        // assignment against it is Superuser-only, matching every other catalog mutation.
        $this->authorizeCatalogScope($r, $m->account_id);

        $profile = ModelProfile::firstOrCreate(['machine_model_id' => $m->id, 'account_id' => $m->account_id, 'is_active' => true], ['name' => trim($m->name).' profile']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $d['component_id'], 'slot_code' => $d['slot_code'], 'slot_name' => $d['slot_name'] ?? null, 'display_order' => $d['display_order'] ?? 0, 'tracking_method' => $d['tracking_method'] ?? 'counter_based', 'baseline_expected_clicks' => $d['baseline_expected_clicks'] ?? null] + array_intersect_key($d, array_flip(['healthy_threshold_percent', 'watch_threshold_percent', 'warning_threshold_percent', 'critical_threshold_percent', 'adaptive_enabled', 'notes'])));

        return response()->json(['data' => $slot->load('component')], 201);
    }

    public function storeSlot(Request $r, string $profile)
    {
        $p = ModelProfile::findOrFail($profile);
        $this->authorizeCatalogScope($r, $p->account_id);
        $d = $r->validate(['component_id' => 'required|uuid', 'slot_code' => 'required|string|max:80', 'slot_name' => 'nullable|string|max:160', 'display_order' => 'nullable|integer|min:0', 'tracking_method' => 'nullable|in:counter_based', 'baseline_expected_clicks' => 'nullable|integer|min:1', 'healthy_threshold_percent' => 'nullable|numeric|min:0|max:100', 'watch_threshold_percent' => 'nullable|numeric|min:0|max:100', 'warning_threshold_percent' => 'nullable|numeric|min:0|max:100', 'critical_threshold_percent' => 'nullable|numeric|min:0|max:100', 'adaptive_enabled' => 'nullable|boolean', 'notes' => 'nullable|string']);
        $this->validateThresholdOrdering($d);

        return response()->json(['data' => ModelProfileSlot::create($d + ['profile_id' => $profile])->load('component')], 201);
    }

    public function updateSlot(Request $r, string $slot)
    {
        $s = ModelProfileSlot::with('profile')->findOrFail($slot);
        $this->authorizeCatalogScope($r, $s->profile->account_id);
        $d = $r->validate(['slot_name' => 'nullable|string|max:160', 'display_order' => 'nullable|integer|min:0', 'tracking_method' => 'nullable|in:counter_based', 'baseline_expected_clicks' => 'nullable|integer|min:1', 'healthy_threshold_percent' => 'nullable|numeric|min:0|max:100', 'watch_threshold_percent' => 'nullable|numeric|min:0|max:100', 'warning_threshold_percent' => 'nullable|numeric|min:0|max:100', 'critical_threshold_percent' => 'nullable|numeric|min:0|max:100', 'adaptive_enabled' => 'nullable|boolean', 'notes' => 'nullable|string']);
        // M2.17.5.2 Part C4: a forged { tracking_method: "consumption_based" } (etc) on an
        // existing slot must be rejected the same as on create - `in:counter_based` above
        // already refuses anything else, this ordering check just also covers the case
        // where thresholds are edited without tracking_method in the same payload.
        $this->validateThresholdOrdering($d + ['healthy_threshold_percent' => $d['healthy_threshold_percent'] ?? $s->healthy_threshold_percent, 'watch_threshold_percent' => $d['watch_threshold_percent'] ?? $s->watch_threshold_percent, 'warning_threshold_percent' => $d['warning_threshold_percent'] ?? $s->warning_threshold_percent, 'critical_threshold_percent' => $d['critical_threshold_percent'] ?? $s->critical_threshold_percent]);
        $s->update($d);

        return response()->json(['data' => $s->load('component')]);
    }

    public function setSlotStatus(Request $r, string $slot)
    {
        $s = ModelProfileSlot::with('profile')->findOrFail($slot);
        $this->authorizeCatalogScope($r, $s->profile->account_id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        $s->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $s->load('component')]);
    }

    public function setProfileStatus(Request $r, string $id)
    {
        $p = ModelProfile::findOrFail($id);
        $this->authorizeCatalogScope($r, $p->account_id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        $p->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $p]);
    }

    public function exclude(Request $r, string $component)
    {
        $mc = MachineComponent::with('machine')->findOrFail($component);
        $this->authorizeComponentConfiguration($r, $mc->machine);
        $d = $r->validate(['reason' => 'required|string', 'client_request_id' => 'nullable|uuid']);
        app(ComponentConfigurationService::class)->exclude($mc, $d['reason'], $d['client_request_id'] ?? null);

        return response()->noContent();
    }

    public function clearExclusion(Request $r, string $exclusion)
    {
        // Authorization is resolved from the exclusion record's own machine_id,
        // never a client-supplied value, so a forged/unrelated id cannot be
        // used to launder access into a different account.
        $e = MachineComponentExclusion::with('machine')->findOrFail($exclusion);
        $this->authorizeComponentConfiguration($r, $e->machine);
        app(ComponentConfigurationService::class)->clearExclusion($e, $r->user()->id);

        return response()->noContent();
    }

    public function machineComponents(Request $r, string $machine)
    {
        $m = Machine::with(['account', 'branch.account'])->findOrFail($machine);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $m), 403);

        $latestCounter = CounterReading::where('machine_id', $m->id)
            ->where('account_id', $m->account_id)
            ->where('status', 'effective')
            ->whereHas('counterType', fn ($q) => $q->whereRaw('LOWER(TRIM(code)) = ?', ['total_impressions']))
            ->orderByDesc('observed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return response()->json(['data' => MachineComponent::where('machine_id', $m->id)
            ->with([
                'component',
                'profileSlot',
                'lifecycles' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
            ])
            ->orderBy('display_order')
            ->orderBy('slot_code')
            ->get()
            ->map(function ($x) use ($latestCounter) {
                $activeLifecycle = $x->lifecycles->firstWhere('status', 'active');
                $baselineLifecycle = $activeLifecycle ? null : $x->lifecycles->first(
                    fn ($lifecycle) => $lifecycle->status === 'unknown' && $lifecycle->installed_counter !== null
                );
                $x->configuration_state = $x->status === 'retired'
                    ? 'RETIRED'
                    : ($activeLifecycle ? 'INITIALIZED' : ($baselineLifecycle ? 'BASELINE_KNOWN' : 'UNKNOWN'));
                // BASELINE_KNOWN: no active lifecycle exists, but a status='unknown' lifecycle carries a real
                // installed_counter derived from prior history. There is no factual installation date, so this
                // must never be presented as INITIALIZED/active or given a fabricated started_at/health score.
                $x->baseline_lifecycle_id = $baselineLifecycle?->id;
                $x->baseline_installed_counter = $baselineLifecycle?->installed_counter;
                $x->latest_effective_counter = $latestCounter?->reading_value;
                $x->latest_counter_observed_at = $latestCounter?->observed_at;

                return $x;
            })]);
    }

    public function sync(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        $this->authorizeComponentConfiguration($r, $m);
        $n = app(ComponentConfigurationService::class)->sync($m);

        return response()->json(['data' => ['created_or_restored' => $n]]);
    }

    public function add(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        $this->authorizeComponentConfiguration($r, $m);
        $d = $r->validate(['component_id' => 'required|uuid', 'slot_code' => 'required|string|max:80', 'display_order' => 'nullable|integer|min:0', 'tracking_method' => 'required|in:counter_based', 'baseline_expected_clicks' => 'required|integer|min:1', 'notes' => 'nullable|string']);

        return response()->json(['data' => app(ComponentConfigurationService::class)->addManual($m, $d)], 201);
    }

    public function initialize(Request $r, string $component)
    {
        // Operational tier, not configuration tier: recording that a component
        // was physically installed is the same class of action as
        // InventoryController::replace() (which creates the same kind of
        // ComponentLifecycle row) and CreateCounterReading - any branch-scoped
        // role may do it, not only owner/admin.
        $mc = MachineComponent::with('machine')->findOrFail($component);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $mc->machine, true), 403);
        $d = $r->validate(['started_at' => 'nullable|date', 'evidence_level' => 'nullable|string|size:1', 'source' => 'nullable|string|max:40', 'notes' => 'nullable|string', 'client_request_id' => 'nullable|uuid']);

        return response()->json(['data' => app(ComponentConfigurationService::class)->initialize($mc, $d)], 201);
    }

    public function reconcile(Request $r, string $component)
    {
        $mc = MachineComponent::with('machine')->findOrFail($component);
        $this->authorizeComponentConfiguration($r, $mc->machine);
        $d = $r->validate(['profile_slot_id' => 'required|uuid']);
        $slot = ModelProfileSlot::findOrFail($d['profile_slot_id']);

        return response()->json(['data' => app(ComponentConfigurationService::class)->reconcileManual($mc, $slot)]);
    }

    public function reconciliationCandidate(Request $r, string $component)
    {
        // Read-only (ComponentConfigurationService::reconciliationCandidate()
        // performs no writes) - visibility tier matches machineComponents()
        // above: any branch-scoped role may view it.
        $mc = MachineComponent::with(['machine', 'component'])->findOrFail($component);
        abort_unless(app(MachineAccessResolver::class)->canAccess($r->user(), $mc->machine), 403);

        return response()->json(['data' => app(ComponentConfigurationService::class)->reconciliationCandidate($mc)]);
    }

    public function remove(Request $r, string $component)
    {
        $mc = MachineComponent::with('machine')->findOrFail($component);
        $this->authorizeComponentConfiguration($r, $mc->machine);
        $d = $r->validate(['reason' => 'nullable|string']);
        $mc->update(['status' => 'retired', 'retired_at' => now()]);

        return response()->json(['data' => $mc]);
    }
}
