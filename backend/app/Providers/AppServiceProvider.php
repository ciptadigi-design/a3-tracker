<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\User;
use App\Services\EffectiveCapabilityResolver;
use App\Services\PdfExtraction\PdfTextExtractor;
use App\Services\PdfExtraction\SmalotPdfTextExtractor;
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
        // V1.5.3 - the only PdfTextExtractor implementation today. See
        // SmalotPdfTextExtractor's docblock for why (Hostinger shared-hosting
        // constraints) and DocumentExtractionService for how a future alternative
        // engine could be bound here instead without touching either class.
        $this->app->bind(PdfTextExtractor::class, SmalotPdfTextExtractor::class);
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
