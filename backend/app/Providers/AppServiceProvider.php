<?php

namespace App\Providers;

use App\Models\User;
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
        Gate::define('platform.manage', fn(User $user) => app(PlatformPrivilegeService::class)->isSuperuser($user));
        Gate::define('settings.access', fn(User $user) => app(PlatformPrivilegeService::class)->isSuperuser($user));
    }
}
