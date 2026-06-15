<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return 'http://192.168.1.12:8000/api/v1/auth/reset-password-redirect?token=' . $token
                . '&email=' . urlencode($user->email);
        });
    }
}
