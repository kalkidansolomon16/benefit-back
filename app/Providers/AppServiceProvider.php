<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

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
        Gate::define('viewApiDocs', function (?User $user) {
        // Option A: If you have an admin panel login system
        // return in_array($user?->email, ['your-email@domain.com', 'mobile-dev@domain.com']);

        // Option B: Publicly accessible during active development (Turn off before launch!)
        return true;
    });
    Scramble::configure()
        ->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer')
            );
        });
        Builder::defaultStringLength(191);
    }
}
