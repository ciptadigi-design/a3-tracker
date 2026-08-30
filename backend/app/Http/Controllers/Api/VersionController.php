<?php
namespace App\Http\Controllers\Api;
use Illuminate\Http\JsonResponse; use Illuminate\Support\Facades\DB;
class VersionController { public function __invoke(): JsonResponse { $batch=null; try {$batch=DB::table('migrations')->max('batch');} catch (\Throwable) {} return response()->json(['data'=>['application'=>config('app.name'),'backend'=>'Laravel 11','git_sha'=>env('APP_GIT_SHA','unknown'),'schema_batch'=>$batch]]); } }
