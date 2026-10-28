<?php

namespace App\Providers;

use App\GraphQL\ValidationRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        // Auth endpoints get a much tighter budget, keyed on what each one guards: the e-mail
        // for credentials, the token for a refresh, the account for a confirmation link. People
        // behind one office IP don't share a bucket, and credential guessing is also capped
        // per IP, across addresses.
        RateLimiter::for('auth', function (Request $request) {
            $refreshToken = $request->input('refresh_token');
            $email = $request->input('email');

            if (is_string($refreshToken)) {
                return Limit::perMinute(10)->by($request->ip().'|refresh|'.hash('sha256', $refreshToken));
            }

            if ($request->route('id') !== null) {
                return Limit::perMinute(10)->by($request->ip().'|verify|'.$request->route('id'));
            }

            return [
                Limit::perMinute(10)->by($request->ip().'|email|'.Str::lower(is_string($email) ? $email : '')),
                Limit::perMinute(30)->by($request->ip().'|credentials'),
            ];
        });

        // Confirmation links are signed with the app key; an empty key would make them forgeable,
        // so refuse to sign or check anything without one.
        URL::setKeyResolver(function () {
            $key = config('app.key');

            if (blank($key)) {
                throw new MissingAppKeyException;
            }

            return [$key, ...(config('app.previous_keys') ?? [])];
        });
    }
}
