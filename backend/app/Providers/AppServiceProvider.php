<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\User;
use App\Services\EffectiveCapabilityResolver;
use App\Services\PlatformPrivilegeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('platform.manage', fn (User $user) => app(PlatformPrivilegeService::class)->isSuperuser($user));
        Gate::define('settings.access', fn (User $user, Account $account) => app(EffectiveCapabilityResolver::class)->allows($user, $account, 'settings.view'));
    }
}
