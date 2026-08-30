<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\BranchRequest;
use App\Http\Requests\ProvisionMemberRequest;
use App\Models\Account;
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
        abort_unless(app(AccountAccessResolver::class)->canAccess($r->user(), $a), 403);

        return response()->json(['data' => $a->branches()->orderBy('name')->paginate(min((int) $r->integer('per_page', 10), 50))]);
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
        $d = $r->validate(['role' => 'sometimes|in:owner,admin,technician,operator', 'status' => 'sometimes|in:invited,active,suspended,revoked']);
        $nextRole = $d['role'] ?? $m->role;
        $nextStatus = $d['status'] ?? $m->status;
        if ($nextRole === 'owner' && $m->role !== 'owner' && ! Gate::allows('platform.manage')) {
            abort(403);
        }if ($m->role === 'owner' && $m->status === 'active' && ($nextRole !== 'owner' || $nextStatus !== 'active') && $a->memberships()->where('role', 'owner')->where('status', 'active')->count() <= 1) {
            return response()->json(['message' => 'The last active owner cannot be suspended or demoted.'], 409);
        }$m->update($d);
        app(GovernanceAudit::class)->record($r->user(), 'membership.updated', 'account_membership', $m->id, $a->id, $d);

        return response()->json(['data' => $m->load('user')]);
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
        app(GovernanceAudit::class)->record($r->user(),'platform.privilege.granted','platform_user_privilege',$u->id);

        return response()->json(['data' => $p]);
    }
}
