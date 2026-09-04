<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\User;

class AccountAccessResolver
{
    public function __construct(private PlatformPrivilegeService $platform) {}

    public function membership(User $user, Account $account): ?AccountMembership
    {
        if (! $user->isActive() || $account->status !== 'active') {
            return null;
        }

        return AccountMembership::whereBelongsTo($account)->whereBelongsTo($user)->where('status', 'active')->first();
    }

    public function canAccess(User $user, Account $account): bool
    {
        return $account->status === 'active' && $user->isActive() && ($this->platform->isSuperuser($user) || $this->membership($user, $account) !== null);
    }

    public function canGovern(User $user, Account $account): bool
    {
        return $user->isActive() && ($this->platform->isSuperuser($user) || $this->membership($user, $account)?->role === 'owner');
    }

    public function canManageOperational(User $user, Account $account): bool
    {
        return $account->status === 'active' && $user->isActive() && ($this->platform->isSuperuser($user) || in_array($this->membership($user, $account)?->role, ['owner', 'admin'], true));
    }

    /**
     * The branch ids this user is authorized to see within the given account.
     * Returns null when the user has unrestricted (all-branch) visibility -
     * platform superuser or account owner - matching BranchAccessResolver's
     * own owner bypass. Returns an array (possibly empty) of active branch
     * ids otherwise, so members are scoped to exactly what they were
     * assigned in Settings -> Members.
     */
    public function authorizedBranchIds(User $user, Account $account): ?array
    {
        if ($this->platform->isSuperuser($user)) {
            return null;
        }
        $m = $this->membership($user, $account);
        if (! $m) {
            return [];
        }
        if ($m->role === 'owner') {
            return null;
        }

        return AccountMembershipBranch::where(['account_id' => $account->id, 'membership_id' => $m->id, 'is_active' => true])->pluck('branch_id')->all();
    }
}
