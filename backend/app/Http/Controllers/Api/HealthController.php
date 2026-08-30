<?php
namespace App\Http\Controllers\Api;
use Illuminate\Http\JsonResponse; use Illuminate\Support\Facades\DB;
class HealthController { public function __invoke(): JsonResponse { $ok=true; try { DB::connection()->getPdo(); } catch (\Throwable) { $ok=false; } return response()->json(['data'=>['status'=>$ok?'ok':'degraded','database'=>$ok?'reachable':'unavailable','environment'=>app()->environment(),'version'=>env('APP_VERSION','dev')]],$ok?200:503); } }
