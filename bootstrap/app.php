<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Console\Commands\ConvertDocumentToPdf;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsurePasswordChanged;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ConvertDocumentToPdf::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login', ['pesan' => 'belum_login']));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
