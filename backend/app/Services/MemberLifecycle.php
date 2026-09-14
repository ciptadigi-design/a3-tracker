<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MemberLifecycle
{
    public function validateBranches(Account $account, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (Branch::where('account_id', $account->id)->where('is_active', true)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['branch_ids' => 'Select active branches in this account.']);
        }

        return $ids;
    }

    public function authorizeTarget(User $actor, User $target, bool $global = false): void
    {
        $platform = app(PlatformPrivilegeService::class)->isSuperuser($actor);
        // Protect platform identities even when disabled or their grant is inactive.
        abort_if(! $platform && ($global || $target->platformPrivilege()->exists()), 403);
    }

    public function assign(AccountMembership $membership, array $ids): void
    {
        $membership->branchAssignments()->update(['is_active' => false]);
        foreach ($ids as $id) {
            $membership->branchAssignments()->updateOrCreate(['branch_id' => $id], ['account_id' => $membership->account_id, 'is_active' => true]);
        }
    }

    public function update(User $actor, Account $account, string $id, array $data): AccountMembership
    {
        if (array_key_exists('branch_ids', $data)) {
            $data['branch_ids'] = $this->validateBranches($account, $data['branch_ids']);
        }

        return DB::transaction(function () use ($actor, $account, $id, $data) {
            // A single stable parent row serializes all membership transitions, including
            // two different Owners. Lock before authorization and use current reads.
            $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccountAccessResolver::class)->canGovern($actor, $account), 403);
            $m = $account->memberships()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorizeTarget($actor, $m->user, isset($data['username']) || isset($data['display_name']));
            $nextRole = $data['role'] ?? $m->role;
            $nextStatus = $data['status'] ?? $m->status;
            abort_if($nextRole === 'owner' && $m->role !== 'owner' && ! app(PlatformPrivilegeService::class)->isSuperuser($actor), 403);
            if ($m->role === 'owner' && $m->status === 'active' && ($nextRole !== 'owner' || $nextStatus !== 'active')) {
                $owners = $account->memberships()->where('role', 'owner')->where('status', 'active')->lockForUpdate()->get();
                if ($owners->count() <= 1) {
                    throw new ConflictHttpException('The last active owner cannot be removed.');
                }
            }
            if (array_key_exists('branch_ids', $data)) {
                $this->validateBranches($account, $data['branch_ids']);
            }
            if (isset($data['username'])) {
                IdentityInput::ensureAvailable('username', $data['username'], $m->user_id);
            }
            $before = ['role' => $m->role, 'status' => $m->status, 'branch_ids' => $m->branchAssignments()->where('is_active', true)->orderBy('branch_id')->pluck('branch_id')->all()];
            $m->update(array_intersect_key($data, array_flip(['role', 'status'])));
            $profile = [];
            if (isset($data['username'])) {
                $profile['username'] = $data['username'];
            }
            if (isset($data['display_name'])) {
                $profile['name'] = trim($data['display_name']);
            }
            if ($profile) {
                $m->user->forceFill($profile);
                if ($m->user->isDirty()) {
                    $m->user->save();
                    app(GovernanceAudit::class)->record($actor, 'identity.profile_updated', 'user', $m->user_id, $account->id);
                }
            }
            if (array_key_exists('branch_ids', $data)) {
                $this->assign($m, $data['branch_ids']);
            }
            $after = ['role' => $m->role, 'status' => $m->status, 'branch_ids' => $m->branchAssignments()->where('is_active', true)->orderBy('branch_id')->pluck('branch_id')->all()];
            foreach (['role' => 'role_changed', 'status' => 'status_changed', 'branch_ids' => 'branch_assignments_changed'] as $field => $event) {
                app(GovernanceAudit::class)->changed($actor, 'membership.'.$event, 'account_membership', $m->id, $account->id, [$field => $before[$field]], [$field => $after[$field]]);
            }

            return $m->load('user', 'branchAssignments');
        });
    }

    public function attach(User $actor, Account $account, array $data): AccountMembership
    {
        abort_unless(app(PlatformPrivilegeService::class)->isSuperuser($actor), 403);
        $ids = $this->validateBranches($account, $data['branch_ids']);
        if ($data['role'] !== 'owner' && $ids === []) {
            throw ValidationException::withMessages(['branch_ids' => 'Select at least one active branch for a non-owner member.']);
        }

        return DB::transaction(function () use ($actor, $account, $data, $ids) {
            Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            $existing = $account->memberships()->where('user_id', $user->id)->first();
            if ($existing) {
                throw new ConflictHttpException('Membership already exists.');
            }
            $this->validateBranches($account, $ids);
            $m = $account->memberships()->create(['user_id' => $user->id, 'role' => $data['role'], 'status' => 'active', 'accepted_at' => now()]);
            $this->assign($m, $ids);
            sort($ids);
            app(GovernanceAudit::class)->changed($actor, 'membership.attached', 'account_membership', $m->id, $account->id, [], ['user_id' => $user->id, 'role' => $m->role, 'status' => $m->status, 'branch_ids' => $ids]);

            return $m->load('user', 'branchAssignments');
        });
    }
}
