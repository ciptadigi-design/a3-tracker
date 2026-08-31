<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\PlatformPrivilegeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function updateAccount(Request $r)
    {
        $user = $r->user();
        $action = $r->validate(['action' => 'required|in:profile,email,password'])['action'];
        if ($action === 'profile') {
            $d = $r->validate(['displayName' => 'required|string|max:120', 'username' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:users,username,'.$user->id]]);
            $user->forceFill(['name' => trim($d['displayName']), 'username' => strtolower(trim($d['username']))])->save();
        } elseif ($action === 'email') {
            $d = $r->validate(['email' => ['required', 'email', 'max:254', 'unique:users,email,'.$user->id], 'currentPassword' => 'required|string']);
            abort_unless(Hash::check($d['currentPassword'], $user->password), 422, 'Current password is incorrect.');
            $user->forceFill(['email' => strtolower(trim($d['email']))])->save();
        } else {
            $d = $r->validate(['currentPassword' => 'required|string', 'password' => 'required|string|min:10|confirmed']);
            abort_unless(Hash::check($d['currentPassword'], $user->password), 422, 'Current password is incorrect.');
            $user->forceFill(['password' => $d['password']])->save();
        }

        return response()->json(['data' => ['user' => $user->only(['id', 'name', 'email', 'username', 'status'])]]);
    }

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
