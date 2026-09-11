<?php

/*
 * M2.17.5.3: this is the canonical source for the deployed release's exact Git
 * commit SHA. VersionController reads it via config('release.git_sha'), never
 * env() directly - a raw env() call outside a config/*.php file returns null
 * once `php artisan config:cache` has run (Laravel skips loading .env entirely
 * when a config cache exists), which is exactly how /api/v1/version regressed
 * to reporting "unknown" in Production despite a correctly deployed release.
 * Because this file is evaluated (and its env() call resolved) at config-cache
 * time, the frozen value survives caching - and because bootstrap/cache/config.php
 * is generated fresh per release directory (never shared/symlinked across
 * releases), each release's cache permanently keeps ITS OWN deployed SHA, so a
 * rollback that simply re-points the `current` symlink to an older release
 * (without re-running config:cache against it) correctly keeps reporting that
 * older release's own identity.
 */
return [
    'git_sha' => env('APP_GIT_SHA', 'unknown'),
];
