<?php

use App\Http\Middleware\RemoteSupportAdminToken;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // bootstrap/app.php runs before config bindings are available.
        $standaloneMode = filter_var((string) env('DMS_STANDALONE_MODE', 'true'), FILTER_VALIDATE_BOOL);
        if (! $standaloneMode) {
            $middleware->web(append: [
                ResolveTenantContext::class,
            ]);
            $middleware->api(append: [
                ResolveTenantContext::class,
            ]);
        }
        $middleware->alias([
            'permission' => RequirePermission::class,
            'remote.support.token' => RemoteSupportAdminToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
