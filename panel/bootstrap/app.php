<?php

use App\Console\Commands\LicenseSync;
use App\Console\Commands\NodeHealthCheck;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Sync license / feature flags every 12 hours at 04:15 and 16:15.
        $schedule->command(LicenseSync::class)->twiceDaily(4, 16);

        // Ping all nodes every 5 minutes and update their status.
        $schedule->command(NodeHealthCheck::class)->everyFiveMinutes();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureTwoFactorAuthenticated::class);

        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
