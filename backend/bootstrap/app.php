<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->alias(['request.id' => \App\Http\Middleware\RequestId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $e) => $request->is('api/*'));
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) return null;
            $id = $request->attributes->get('request_id');
            Log::error('API request failed', ['request_id' => $id, 'exception' => $e]);
            $status = $e instanceof \Illuminate\Validation\ValidationException ? 422 : ($e instanceof \Illuminate\Auth\AuthenticationException ? 401 : 500);
            return response()->json(['message' => $status === 500 ? 'An unexpected error occurred.' : $e->getMessage(), 'errors' => $status === 422 ? $e->errors() : (object) [], 'request_id' => $id], $status);
        });
    })->create();
