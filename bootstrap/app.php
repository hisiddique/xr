<?php

use App\Http\Middleware\RedirectIfNotAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Livewire\Exceptions\PropertyNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => RedirectIfNotAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Diagnostics only — no behavior change. Captures the raw request so we
        // have real data (rather than guesses) the next time this recurs.
        $exceptions->reportable(function (PropertyNotFoundException $e): void {
            $request = request();

            Log::warning('PropertyNotFoundException diagnostics', [
                'message' => $e->getMessage(),
                'url' => $request->fullUrl(),
                'user_id' => $request->user()?->id,
                'raw_body' => $request->getContent(),
            ]);
        });
    })->create();
