<?php
namespace App\Http\Middleware; use Closure; use Illuminate\Http\Request;
class EnsureActiveUser { public function handle(Request $r,Closure $next){ if(!$r->user()?->isActive()){ auth()->logout(); $r->session()->invalidate(); return response()->json(['message'=>'Unauthenticated.','errors'=>(object)[]],401); } return $next($r); } }
