<?php

use App\Http\Controllers\SeaController;
use App\Http\Middleware\IdentifySailor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // O SSE fica fora do grupo web: conexão longa, sem sessão nem cookies.
        then: fn () => Route::get('/mar/eventos', [SeaController::class, 'events']),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [IdentifySailor::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('mar', 'mar/*', 'garrafas*') || $request->expectsJson(),
        );
    })->create();
