<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function (\App\Models\User $user, string $token) {
            return config('app.frontend_url', 'https://offerra.click') . '/reset-password?token=' . $token . '&email=' . $user->email;
        });

        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters used across the API. Apply with `throttle:<name>`.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            $email = (string) $request->input('email', '');
            $by = $email !== ''
                ? strtolower($email) . '|' . $request->ip()
                : $request->ip();
            return Limit::perMinute(5)->by($by);
        });

        RateLimiter::for('ai', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();
            return [
                Limit::perMinute(20)->by('ai:m:' . $key),
                Limit::perDay(200)->by('ai:d:' . $key),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(200)->by(optional($request->user())->id ?: $request->ip());
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
