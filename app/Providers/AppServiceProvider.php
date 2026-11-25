<?php

namespace App\Providers;

use App\Ocean\Ocean;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ocean::class, fn () => new Ocean(config('ocean.data_path')));
    }

    public function boot(): void
    {
        RateLimiter::for('lancamentos', fn (Request $request) => Limit::perHour(config('ocean.launches_per_hour'))
            ->by($request->ip())
            ->response(fn () => response()->json(['message' => 'O mar pede calma. Espere um pouco antes de lançar outra garrafa.'], 429)));
    }
}
