<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API guests must be rendered as JSON even when a client omits Accept: application/json.
        // Returning null prevents Laravel's default route('login') fallback from throwing before
        // the API exception renderer can produce its deterministic 401 response.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->statefulApi();
        $middleware->alias(['request.id' => RequestId::class, 'active.user' => EnsureActiveUser::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*'));
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $id = $request->attributes->get('request_id');
            Log::error('API request failed', ['request_id' => $id, 'exception' => $e]);
            $status = $e instanceof ValidationException ? 422 : ($e instanceof AuthenticationException ? 401 : ($e instanceof AuthorizationException ? 403 : ($e instanceof ModelNotFoundException ? 404 : ($e instanceof ConflictHttpException ? 409 : ($e instanceof QueryException && in_array($e->getCode(), ['23000', '23505'], true) ? 409 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e instanceof HttpResponseException ? $e->getResponse()->getStatusCode() : 500)))))));
            $errors = $status === 422 ? $e->errors() : (object) [];
            $message = $status === 500 ? 'An unexpected error occurred.' : ($status === 403 ? 'Forbidden.' : ($status === 404 ? 'Not found.' : ($status === 401 ? 'Unauthenticated.' : ($status === 409 ? 'Conflict.' : $e->getMessage()))));

            return response()->json(['message' => $message, 'errors' => $errors, 'request_id' => $id], $status);
        });
    })->create();
