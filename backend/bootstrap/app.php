<?php

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ApplyLocalizedUiStrings;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SetPreferredLocale;
use App\Http\Middleware\VerifyDeviceRequest;
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
        $webMiddleware = [
            SetPreferredLocale::class,
            ApplyLocalizedUiStrings::class,
        ];
        if (! $standaloneMode) {
            $webMiddleware[] = ResolveTenantContext::class;
            $middleware->api(append: [
                ResolveTenantContext::class,
            ]);
        }
        $middleware->web(append: $webMiddleware);
        $middleware->alias([
            'permission' => RequirePermission::class,
            'device.auth' => VerifyDeviceRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
