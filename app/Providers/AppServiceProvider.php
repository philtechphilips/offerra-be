<?php

namespace App\Providers;

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
            return config('app.frontend_url', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . $user->email;
        });
    }
}
