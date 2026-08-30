<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMembership;
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
}
