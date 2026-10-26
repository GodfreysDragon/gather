<?php

namespace App\Providers;

use App\GraphQL\ValidationRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Support\Contracts\ProvidesValidationRules;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProvidesValidationRules::class, ValidationRules::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user('sanctum')?->id ?: $request->ip()));

        // Credential endpoints get a much tighter budget, keyed on IP + e-mail, to slow brute forcing.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip().'|'.strtolower((string) $request->input('email'))));
    }
}
