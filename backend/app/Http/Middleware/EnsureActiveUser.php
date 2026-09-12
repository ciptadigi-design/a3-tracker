<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $r, Closure $next)
    {
        $user = $r->user()?->fresh();
        // Legacy sessions have version zero. They remain usable until the first
        // security change, and cannot acquire a new version without logging in.
        if (! $user?->isActive() || (int) $r->session()->get('identity_session_version', 0) !== (int) $user->session_version) {
            auth()->logout();
            $r->session()->invalidate();

            return response()->json(['message' => 'Unauthenticated.', 'errors' => (object) []], 401);
        }

        return $next($r);
    }
}
