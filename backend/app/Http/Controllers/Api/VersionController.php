<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VersionController
{
    public function __invoke(): JsonResponse
    {
        $batch = null;
        try {
            $batch = DB::table('migrations')->max('batch');
        } catch (\Throwable) {
        }
        // M2.17.5.3: config('release.git_sha'), never env('APP_GIT_SHA') directly - see
        // config/release.php for why. A value that isn't an exact 40-hex commit SHA
        // (missing, truncated, or a stray non-release string) must never be reported as
        // if it were real release identity - "unknown" is the only honest fallback.
        $sha = config('release.git_sha', 'unknown');
        if (! is_string($sha) || ! preg_match('/^[0-9a-f]{40}$/i', $sha)) {
            $sha = 'unknown';
        }

        return response()->json(['data' => ['application' => config('app.name'), 'backend' => 'Laravel 11', 'git_sha' => $sha, 'schema_batch' => $batch]]);
    }
}
