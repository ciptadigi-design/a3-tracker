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
use App\Services\GovernanceAudit;
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
        $policy = DB::table('account_operational_permissions')->where('account_id', $id)->first();
        $policy ??= (object) array_merge(['account_id' => $id], array_fill_keys(['operator_can_initialize_component', 'operator_can_replace_component', 'operator_can_create_purchase', 'operator_can_receive_goods', 'operator_can_adjust_inventory', 'operator_can_transfer_inventory', 'operator_can_log_errors'], false));

        return response()->json(['data' => ['branches' => $a->branches()->orderBy('name')->get(), 'members' => $a->memberships()->with(['user', 'branchAssignments'])->get()->map(fn ($m) => ['id' => $m->id, 'user_id' => $m->user_id, 'role' => $m->role, 'status' => $m->status, 'username' => $m->user?->username, 'display_name' => $m->user?->name, 'email' => $m->user?->email, 'branch_ids' => $m->branchAssignments->where('is_active', true)->pluck('branch_id')->values()]), 'policy' => $policy, 'models' => MachineModel::with('manufacturer')->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $id))->get(), 'components' => ComponentCatalog::where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $id))->get(), 'profiles' => ModelProfileSlot::with(['component', 'profile'])->whereHas('profile', fn ($q) => $q->where(fn ($x) => $x->whereNull('account_id')->orWhere('account_id', $id)))->get(), 'locations' => InventoryLocation::where('account_id', $id)->get(), 'people' => OperationalPersonResource::collection(OperationalPerson::where('account_id', $id)->with('branchAssignments.branch')->get()), 'manufacturers' => Manufacturer::where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $id))->get(), 'audit' => []]]);
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
        $m = $a->memberships()->findOrFail($id);
        $d = $r->validate(['role' => 'sometimes|in:owner,admin,technician,operator', 'status' => 'sometimes|in:invited,active,suspended,revoked', 'username' => 'sometimes|string|max:80', 'display_name' => 'sometimes|string|max:120', 'branch_ids' => 'sometimes|array', 'branch_ids.*' => 'uuid']);
        $nextRole = $d['role'] ?? $m->role;
        $nextStatus = $d['status'] ?? $m->status;
        if ($nextRole === 'owner' && $m->role !== 'owner' && ! Gate::allows('platform.manage')) {
            abort(403);
        }if ($m->role === 'owner' && $m->status === 'active' && ($nextRole !== 'owner' || $nextStatus !== 'active') && $a->memberships()->where('role', 'owner')->where('status', 'active')->count() <= 1) {
            return response()->json(['message' => 'The last active owner cannot be suspended or demoted.'], 409);
        }$m->update(array_intersect_key($d, array_flip(['role', 'status'])));
        if ($m->user && (array_key_exists('username', $d) || array_key_exists('display_name', $d))) {
            $m->user->forceFill(array_filter(['username' => $d['username'] ?? null, 'name' => $d['display_name'] ?? null], fn ($v) => $v !== null))->save();
        }
        if (array_key_exists('branch_ids', $d)) {
            $m->branchAssignments()->update(['is_active' => false]);
            foreach ($d['branch_ids'] as $branchId) {
                $m->branchAssignments()->updateOrCreate(['branch_id' => $branchId], ['account_id' => $accountId, 'is_active' => true]);
            }
        }
        app(GovernanceAudit::class)->record($r->user(), 'membership.updated', 'account_membership', $m->id, $a->id, $d);

        return response()->json(['data' => $m->load('user')]);
    }

    public function updateMemberEmail(Request $r, string $accountId, string $id)
    {
        $a = Account::findOrFail($accountId);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $m = $a->memberships()->with('user')->findOrFail($id);
        $d = $r->validate(['email' => 'required|email|max:254|unique:users,email,'.$m->user_id]);
        $m->user->forceFill(['email' => strtolower(trim($d['email']))])->save();

        return response()->json(['data' => $m->fresh('user')]);
    }

    public function resetMemberPassword(Request $r, string $accountId, string $id)
    {
        $a = Account::findOrFail($accountId);
        abort_unless(app(AccountAccessResolver::class)->canGovern($r->user(), $a), 403);
        $m = $a->memberships()->with('user')->findOrFail($id);
        $d = $r->validate(['password' => 'required|string|min:10|confirmed']);
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
