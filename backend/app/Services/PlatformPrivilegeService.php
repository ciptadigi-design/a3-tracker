<?php

namespace App\Services;

use App\Models\User;

class PlatformPrivilegeService
{
    public function has(User $user, string $privilege = 'platform_superuser'): bool
    {
        return $user->isActive() && $privilege === 'platform_superuser' && $user->platformPrivilege()->where('role', 'superuser')->where('is_active', true)->exists();
    }

    public function isSuperuser(User $user): bool
    {
        return $this->has($user);
    }
}
