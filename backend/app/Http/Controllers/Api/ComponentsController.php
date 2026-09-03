<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComponentCatalog;
use App\Models\CounterReading;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineComponentExclusion;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\ComponentConfigurationService;
use App\Services\MachineAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ComponentsController extends Controller
{
    public function catalogs(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');

        return response()->json(['data' => ComponentCatalog::where('is_active', true)->where(fn ($q) => $q->whereNull('account_id')->orWhereIn('account_id', $ids))->orderBy('name')->get()]);
    }

    public function storeCatalog(Request $r)
    {
        Gate::authorize('platform.manage');
        $d = $r->validate(['account_id' => 'nullable|uuid', 'code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'description' => 'nullable|string', 'category' => 'nullable|string|max:80']);

        $code = strtoupper(trim($d['code']));
        if (ComponentCatalog::whereRaw('UPPER(TRIM(code)) = ?', [$code])->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $d['account_id'] ?? null))->exists()) {
            throw new ConflictHttpException('[DUPLICATE_COMPONENT_CODE] A Component Catalog entry with this code already exists in the selected scope.');
        }
        $d['code'] = $code;

        return response()->json(['data' => ComponentCatalog::create($d)], 201);
    }

    public function updateCatalog(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $c = ComponentCatalog::findOrFail($id);
        $d = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:160', 'description' => 'nullable|string', 'category' => 'nullable|string|max:80']);
        $c->update($d);

        return response()->json(['data' => $c]);
    }

    public function setCatalogStatus(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $c = ComponentCatalog::findOrFail($id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        if (! $active && ModelProfileSlot::where('component_id', $c->id)->where('is_active', true)->exists()) {
            throw new ConflictHttpException('catalog is referenced by an active profile slot');
        }$c->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $c]);
    }

    public function profiles(Request $r, string $model)
    {
        return response()->json(['data' => ModelProfile::where('machine_model_id', $model)->with(['slots.component'])->where('is_active', true)->get()]);
    }

    public function storeProfile(Request $r, string $model)
    {
        Gate::authorize('platform.manage');
        $d = $r->validate(['account_id' => 'nullable|uuid', 'name' => 'required|string|max:160']);

        return response()->json(['data' => ModelProfile::create($d + ['machine_model_id' => $model])], 201);
    }

    public function storeSlot(Request $r, string $profile)
    {
        Gate::authorize('platform.manage');
        $d = $r->validate(['component_id' => 'required|uuid', 'slot_code' => 'required|string|max:80', 'slot_name' => 'nullable|string', 'display_order' => 'nullable|integer|min:0', 'baseline_expected_clicks' => 'nullable|integer|min:1']);

        return response()->json(['data' => ModelProfileSlot::create($d + ['profile_id' => $profile])], 201);
    }

    public function setProfileStatus(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $p = ModelProfile::findOrFail($id);
        $active = $r->validate(['is_active' => 'required|boolean'])['is_active'];
        $p->update(['is_active' => $active, 'archived_at' => $active ? null : now()]);

        return response()->json(['data' => $p]);
    }

    public function exclude(Request $r, string $component)
    {
        $mc = MachineComponent::findOrFail($component);
        $d = $r->validate(['reason' => 'required|string', 'client_request_id' => 'nullable|uuid']);
        app(ComponentConfigurationService::class)->exclude($mc, $d['reason'], $d['client_request_id'] ?? null);

        return response()->noContent();
    }

    public function clearExclusion(Request $r, string $exclusion)
    {
        $e = MachineComponentExclusion::findOrFail($exclusion);
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
                $x->configuration_state = $x->status === 'retired' ? 'RETIRED' : ($activeLifecycle ? 'INITIALIZED' : 'UNKNOWN');
                $x->latest_effective_counter = $latestCounter?->reading_value;
                $x->latest_counter_observed_at = $latestCounter?->observed_at;

                return $x;
            })]);
    }

    public function sync(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        $n = app(ComponentConfigurationService::class)->sync($m);

        return response()->json(['data' => ['created_or_restored' => $n]]);
    }

    public function add(Request $r, string $machine)
    {
        $m = Machine::findOrFail($machine);
        $d = $r->validate(['component_id' => 'required|uuid', 'slot_code' => 'required|string|max:80', 'display_order' => 'nullable|integer|min:0', 'tracking_method' => 'required|in:counter_based', 'baseline_expected_clicks' => 'required|integer|min:1', 'notes' => 'nullable|string']);

        return response()->json(['data' => app(ComponentConfigurationService::class)->addManual($m, $d)], 201);
    }

    public function initialize(Request $r, string $component)
    {
        $mc = MachineComponent::findOrFail($component);
        $d = $r->validate(['started_at' => 'nullable|date', 'evidence_level' => 'nullable|string|size:1', 'source' => 'nullable|string|max:40', 'notes' => 'nullable|string', 'client_request_id' => 'nullable|uuid']);

        return response()->json(['data' => app(ComponentConfigurationService::class)->initialize($mc, $d)], 201);
    }

    public function reconcile(Request $r, string $component)
    {
        $mc = MachineComponent::findOrFail($component);
        $d = $r->validate(['profile_slot_id' => 'required|uuid']);
        $slot = ModelProfileSlot::findOrFail($d['profile_slot_id']);

        return response()->json(['data' => app(ComponentConfigurationService::class)->reconcileManual($mc, $slot)]);
    }

    public function reconciliationCandidate(Request $r, string $component)
    {
        $mc = MachineComponent::with(['machine', 'component'])->findOrFail($component);

        return response()->json(['data' => app(ComponentConfigurationService::class)->reconciliationCandidate($mc)]);
    }

    public function remove(Request $r, string $component)
    {
        $mc = MachineComponent::findOrFail($component);
        $d = $r->validate(['reason' => 'nullable|string']);
        $mc->update(['status' => 'retired', 'retired_at' => now()]);

        return response()->json(['data' => $mc]);
    }
}
