<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\EffectiveCapabilityResolver;
use App\Services\IdentityInput;
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
        foreach (['email', 'username'] as $field) {
            if ($r->has($field)) {
                $r->merge([$field => IdentityInput::normalize($r->input($field))]);
            }
        }
        $action = $r->validate(['action' => 'required|in:profile,email,password'])['action'];
        if ($action === 'profile') {
            $d = $r->validate(['displayName' => 'required|string|max:120', 'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,32}$/']]);
            IdentityInput::ensureAvailable('username', $d['username'], $user->id);
            $user->forceFill(['name' => trim($d['displayName']), 'username' => strtolower(trim($d['username']))])->save();
        } elseif ($action === 'email') {
            $d = $r->validate(['email' => ['required', 'email', 'max:254'], 'currentPassword' => 'required|string']);
            if (! Hash::check($d['currentPassword'], $user->password)) {
                throw ValidationException::withMessages(['currentPassword' => 'Current password is incorrect.']);
            }
            IdentityInput::ensureAvailable('email', $d['email'], $user->id);
            $user->forceFill(['email' => strtolower(trim($d['email']))])->save();
        } else {
            $d = $r->validate(['currentPassword' => 'required|string', 'password' => 'required|string|min:10|max:128|confirmed']);
            if (! Hash::check($d['currentPassword'], $user->password)) {
                throw ValidationException::withMessages(['currentPassword' => 'Current password is incorrect.']);
            }
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
        // Read the authenticated model: Auth::attempt may rehash the password.
        $r->session()->put('identity_session_version', (int) Auth::user()->session_version);

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

        return response()->json(['data' => ['user' => $u->only(['id', 'name', 'email', 'username', 'status']), 'platform' => ['is_superuser' => $super], 'memberships' => $memberships, 'accounts' => $accounts, 'capabilities' => $accounts->mapWithKeys(fn ($account) => [$account->id => app(EffectiveCapabilityResolver::class)->resolve($u, $account)])]]);
    }
}
