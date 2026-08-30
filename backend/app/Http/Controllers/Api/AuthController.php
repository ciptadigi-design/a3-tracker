<?php
namespace App\Http\Controllers\Api;
use App\Models\User; use Illuminate\Http\Request; use Illuminate\Support\Facades\Auth; use Illuminate\Support\Facades\RateLimiter; use Illuminate\Validation\ValidationException;
class AuthController {
 public function login(Request $r) { $d=$r->validate(['identifier'=>'required|string|max:254','password'=>'required|string|max:1024']); $key=strtolower($d['identifier']).'|'.$r->ip(); if(RateLimiter::tooManyAttempts($key,5)) throw ValidationException::withMessages(['identifier'=>'Too many attempts.']); $u=User::where('email',$d['identifier'])->first(); if(!$u || !Auth::attempt(['email'=>$u->email,'password'=>$d['password']])) { RateLimiter::hit($key,60); throw ValidationException::withMessages(['identifier'=>'Invalid credentials.']); } RateLimiter::clear($key); $r->session()->regenerate(); return response()->json(['data'=>['user'=>$u->only(['id','name','email'])]]); }
 public function logout(Request $r) { Auth::guard('web')->logout(); $r->session()->invalidate(); $r->session()->regenerateToken(); return response()->noContent(); }
 public function me(Request $r) { return response()->json(['data'=>['user'=>$r->user()->only(['id','name','email']),'platform_privilege'=>null,'memberships'=>[],'branches'=>[]]]); }
}
