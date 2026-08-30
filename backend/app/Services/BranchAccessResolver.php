<?php

namespace App\Services;

use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\User;

class BranchAccessResolver
{
    public function __construct(private AccountAccessResolver $accounts, private PlatformPrivilegeService $platform) {}

    public function canAccess(User $user, Branch $branch): bool
    {
        if (! $user->isActive() || ! $branch->is_active || $branch->account->status !== 'active') {
            return false;
        } $m = $this->accounts->membership($user, $branch->account);
        if (! $m) {
            return false;
        }

        return $m->role === 'owner' || AccountMembershipBranch::where(['account_id' => $branch->account_id, 'membership_id' => $m->id, 'branch_id' => $branch->id, 'is_active' => true])->exists();
    }
}
