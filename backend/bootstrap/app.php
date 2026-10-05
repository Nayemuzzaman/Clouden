<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\SecureHeaders;
use App\Services\Databases\DatabaseException;
use App\Services\Docker\DockerException;
use App\Services\Routing\RoutingException;
use App\Services\Source\SourceException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1,172.16.0.0/12,10.0.0.0/8,192.168.0.0/16'));
        $middleware->append(SecureHeaders::class);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'password.recent' => RequireRecentPassword::class,
        ]);
        // GitHub cannot send a CSRF token; webhook requests are authenticated by their HMAC signature instead.
        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Expected, user-actionable failures are returned with their message.
        $exceptions->render(function (DomainException|DatabaseException|SourceException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
        $exceptions->render(function (RoutingException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 502);
        });
        $exceptions->render(function (DockerException|ConnectionException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            report($e);

            return response()->json(['message' => 'Docker could not complete the request: '.$e->getMessage()], 502);
        });
        $exceptions->dontReport([DomainException::class, DatabaseException::class, SourceException::class]);
    })->create();
