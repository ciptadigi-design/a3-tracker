<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request; use Illuminate\Support\Str;
// ->headers->set() (Symfony's universal ResponseHeaderBag) rather than Laravel's
// ->header() shorthand - a file/stream download (MaintenanceDocumentController,
// V1.4) returns a raw Symfony BinaryFileResponse/StreamedResponse, which has no
// ->header() method at all; ->headers->set() works identically across every
// response type this middleware could ever see.
class RequestId { public function handle(Request $request, Closure $next) { $id=$request->header('X-Request-ID') ?: (string) Str::uuid(); $request->attributes->set('request_id',$id); $response = $next($request); $response->headers->set('X-Request-ID', $id); return $response; } }
