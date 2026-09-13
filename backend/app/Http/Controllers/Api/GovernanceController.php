<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\BranchRequest;
use App\Http\Requests\ProvisionMemberRequest;
use App\Http\Resources\OperationalPersonResource;
use App\Models\Account;
use App\Models\ComponentCatalog;
use App\Models\InventoryLocation;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfileSlot;
use App\Models\OperationalPerson;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\AccountAccessResolver;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\IdentityInput;
use App\Services\MemberLifecycle;
use App\Services\ProvisionMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GovernanceController extends Controller
{
    public function settings(Request $r, string $id)
    {
        $a = Account::findOrFail($id);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $resolver = app(EffectiveCapabilityResolver::class);
        $policy = $resolver->policy($a);
        $matrix = collect(['owner', 'admin', 'technician', 'operator'])->mapWithKeys(fn ($role) => [$role => $resolver->forRole($role, $policy)]);

        return response()->json(['data' => ['branches' => $a->branches()->orderBy('name')->get(), 'members' => $a->memberships()->with(['user', 'branchAssignments'])->get()->map(fn ($m) => ['id' => $m->id, 'user_id' => $m->user_id, 'role' => $m->role, 'status' => $m->status, 'username' => $m->user?->username, 'display_name' => $m->user?->name, 'email' => $m->user?->email, 'branch_ids' => $m->branchAssignments->where('is_active', true)->pluck('branch_id')->values()]), 'policy' => $policy, 'capability_matrix' => $matrix, 'models' => MachineModel::with('manufacturer')->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $id))->get(), 'components' => ComponentCatalog::where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $id))->get(), 'profiles' => ModelProfileSlot::with(['component', 'profile'])->whereHas('profile', fn ($q) => $q->where(fn ($x) => $x->whereNull('account_id')->orWhere('account_id', $id)))->get(), 'locations' => InventoryLocation::where('account_id', $id)->get(), 'people' => OperationalPersonResource::collection(OperationalPerson::where('account_id', $id)->with('branchAssignments.branch')->get()), 'manufacturers' => Manufacturer::where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $id))->get(), 'audit' => []]]);
    }

    public function updatePolicy(Request $r, string $id)
    {
        $a = Account::findOrFail($id);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $d = $r->validate(['operator_can_initialize_component' => 'boolean', 'operator_can_replace_component' => 'boolean', 'operator_can_create_purchase' => 'boolean', 'operator_can_receive_goods' => 'boolean', 'operator_can_adjust_inventory' => 'boolean', 'operator_can_transfer_inventory' => 'boolean', 'operator_can_log_errors' => 'boolean']);
        DB::table('account_operational_permissions')->updateOrInsert(['account_id' => $id], $d + ['updated_at' => now(), 'created_at' => now()]);

        return response()->json(['data' => DB::table('account_operational_permissions')->where('account_id', $id)->first()]);
    }

    public function accounts(Request $r)
    {
        Gate::authorize('platform.manage');

        return response()->json(['data' => Account::query()->orderBy('name')->paginate(min((int) $r->integer('per_page', 10), 50))]);
    }

    public function storeAccount(AccountRequest $r)
    {
        Gate::authorize('platform.manage');
        $a = DB::transaction(function () use ($r) {
            return Account::create([...$r->validated(), 'code' => strtoupper(trim($r->code))]);
        });
        app(GovernanceAudit::class)->record($r->user(), 'account.created', 'account', $a->id, null);

        return response()->json(['data' => $a], 201);
    }

    public function updateAccount(AccountRequest $r, string $id)
    {
        Gate::authorize('platform.manage');
        $a = Account::findOrFail($id);
        $values = $r->validated();
        if (isset($values['code'])) {
            $values['code'] = strtoupper(trim($values['code']));
        }if (($values['status'] ?? $a->status) === 'archived') {
            $values['archived_at'] = $a->archived_at ?? now();
        } elseif (isset($values['status'])) {
            $values['archived_at'] = null;
        }$a->update($values);
        app(GovernanceAudit::class)->record($r->user(), 'account.updated', 'account', $a->id, $a->id);

        return response()->json(['data' => $a]);
    }

    public function branches(Request $r, string $id)
    {
        $a = Account::findOrFail($id);
        $resolver = app(AccountAccessResolver::class);
        abort_unless($resolver->canAccess($r->user(), $a), 403);
        $branchIds = $resolver->authorizedBranchIds($r->user(), $a);
        $query = $a->branches()->orderBy('name');
        if ($branchIds !== null) {
            $query->whereIn('id', $branchIds);
        }

        return response()->json(['data' => $query->paginate(min((int) $r->integer('per_page', 10), 50))]);
    }

    public function storeBranch(BranchRequest $r, string $id)
    {
        $a = Account::findOrFail($id);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $b = $a->branches()->create([...$r->validated(), 'code' => strtoupper(trim($r->code))]);
        app(GovernanceAudit::class)->record($r->user(), 'branch.created', 'branch', $b->id, $a->id);

        return response()->json(['data' => $b], 201);
    }

    public function updateBranch(BranchRequest $r, string $accountId, string $id)
    {
        $a = Account::findOrFail($accountId);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $b = $a->branches()->findOrFail($id);
        $d = $r->validated();
        if (isset($d['code'])) {
            $d['code'] = strtoupper(trim($d['code']));
        }if (array_key_exists('is_active', $d)) {
            $d['archived_at'] = $d['is_active'] ? null : now();
        }$b->update($d);
        app(GovernanceAudit::class)->record($r->user(), $b->is_active ? 'branch.restored' : 'branch.archived', 'branch', $b->id, $a->id);

        return response()->json(['data' => $b]);
    }

    public function updateMember(Request $r, string $accountId, string $id)
    {
        $a = Account::findOrFail($accountId);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        if ($r->has('username')) {
            $r->merge(['username' => IdentityInput::normalize($r->input('username'))]);
        }
        $d = $r->validate(['role' => 'sometimes|in:owner,admin,technician,operator', 'status' => 'sometimes|in:invited,active,suspended,revoked', 'username' => ['sometimes', 'string', 'regex:/^[a-z0-9._-]{3,32}$/'], 'display_name' => 'sometimes|string|max:120', 'branch_ids' => 'sometimes|array', 'branch_ids.*' => 'uuid|distinct']);
        $m = app(MemberLifecycle::class)->update($r->user(), $a, $id, $d);

        return response()->json(['data' => $m]);
    }

    public function updateMemberEmail(Request $r, string $accountId, string $id)
    {
        $a = Account::findOrFail($accountId);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $m = $a->memberships()->with('user')->findOrFail($id);
        app(MemberLifecycle::class)->authorizeTarget($r->user(), $m->user, true);
        $r->merge(['email' => IdentityInput::normalize($r->input('email'))]);
        $d = $r->validate(['email' => 'required|email|max:254']);
        IdentityInput::ensureAvailable('email', $d['email'], $m->user_id);
        $m->user->forceFill(['email' => strtolower(trim($d['email']))])->save();

        return response()->json(['data' => $m->fresh('user')]);
    }

    public function resetMemberPassword(Request $r, string $accountId, string $id)
    {
        $a = Account::findOrFail($accountId);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $m = $a->memberships()->with('user')->findOrFail($id);
        app(MemberLifecycle::class)->authorizeTarget($r->user(), $m->user, true);
        $d = $r->validate(['password' => 'required|string|min:10|max:128|confirmed']);
        $m->user->forceFill(['password' => $d['password']])->save();

        return response()->json(['data' => ['membership_id' => $m->id]]);
    }

    public function members(Request $r, string $id)
    {
        $a = Account::findOrFail($id);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);

        return response()->json(['data' => $a->memberships()->with('user')->paginate(min((int) $r->integer('per_page', 10), 50))]);
    }

    public function provision(ProvisionMemberRequest $r, string $id)
    {
        $a = Account::findOrFail($id);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $m = app(ProvisionMember::class)->execute($r->user(), $a, ['name' => $r->name, 'email' => $r->email, 'username' => $r->username, 'password' => $r->password, 'role' => $r->role, 'branch_ids' => $r->branch_ids ?? []]);

        return response()->json(['data' => $m], 201);
    }

    public function attachMember(Request $r, string $id)
    {
        Gate::authorize('platform.manage');
        $a = Account::findOrFail($id);
        $d = $r->validate(['user_id' => 'required|uuid', 'role' => 'required|in:owner,admin,technician,operator', 'branch_ids' => 'required|array', 'branch_ids.*' => 'uuid|distinct']);
        $m = app(MemberLifecycle::class)->attach($r->user(), $a, $d);

        return response()->json(['data' => $m], 201);
    }

    public function bootstrap(Request $r)
    {
        Gate::authorize('platform.manage');
        $d = $r->validate(['user_id' => 'required|uuid']);
        $u = User::findOrFail($d['user_id']);
        $p = PlatformUserPrivilege::updateOrCreate(['user_id' => $u->id], ['role' => 'superuser', 'is_active' => true]);
        app(GovernanceAudit::class)->record($r->user(), 'platform.privilege.granted', 'platform_user_privilege', $u->id);

        return response()->json(['data' => $p]);
    }
}
