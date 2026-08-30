<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\PlatformPrivilegeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function login(Request $r)
    {
        $d = $r->validate(['login' => 'required_without:identifier|string|max:254', 'identifier' => 'sometimes|string|max:254', 'password' => 'required|string|max:1024']);
        $login = strtolower(trim($d['login'] ?? $d['identifier']));
        $key = $login.'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        } $u = User::whereRaw('lower(email)=?', [$login])->orWhereRaw('lower(username)=?', [$login])->first();
        if (! $u || ! $u->isActive() || ! Auth::attempt(['id' => $u->id, 'password' => $d['password']])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        } RateLimiter::clear($key);
        $r->session()->regenerate();

        return response()->json(['data' => ['user' => $u->only(['id', 'name', 'email', 'username'])]]);
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $r)
    {
        $u = $r->user()->load(['memberships.account', 'memberships.branchAssignments']);
        $super = app(PlatformPrivilegeService::class)->isSuperuser($u);
        $memberships = $u->memberships->map(fn ($m) => ['id' => $m->id, 'account_id' => $m->account_id, 'role' => $m->role, 'status' => $m->status, 'account' => $m->account?->only(['id', 'code', 'name', 'status']), 'branch_ids' => $m->branchAssignments->where('is_active', true)->pluck('branch_id')->values()]);
        $accounts = $u->memberships->filter(fn ($m) => $m->status === 'active' && $m->account?->status === 'active')->pluck('account')->filter()->values();

        return response()->json(['data' => ['user' => $u->only(['id', 'name', 'email', 'username', 'status']), 'platform' => ['is_superuser' => $super], 'memberships' => $memberships, 'accounts' => $accounts]]);
    }
}
