<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\User;
use App\Services\EffectiveCapabilityResolver;
use App\Services\PdfExtraction\PdfTextExtractor;
use App\Services\PdfExtraction\ProfileAwarePdfTextExtractor;
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
        // V1.5.5 - ProfileAwarePdfTextExtractor dispatches unencrypted PDFs to
        // SmalotPdfTextExtractor (unchanged since V1.5.3) and the one supported
        // secured profile (Standard Security Handler /V4/R4/AESV2, empty user
        // password) to SecuredPdfTextExtractor; everything else still ends up
        // UNSUPPORTED_PDF_SECURITY exactly as before. See
        // docs/maintenance/V1.5.5_AESV2_EXTRACTION.md.
        $this->app->bind(PdfTextExtractor::class, ProfileAwarePdfTextExtractor::class);
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
