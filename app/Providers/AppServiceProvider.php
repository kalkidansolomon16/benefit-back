<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Services\SessionTokenService;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // INSA CWE-20: links the API generates never come from the request's
        // Host header. In production every absolute URL uses the configured
        // public API address over HTTPS (config/fitaccess.php).
        if (config('fitaccess.enforce_trusted_hosts')) {
            URL::forceRootUrl(config('fitaccess.api_url'));
            URL::forceScheme('https');
        }

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return config('fitaccess.api_url') . '/api/v1/auth/reset-password-redirect?token=' . urlencode($token)
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

        // Server-side session expiry (INSA CWE-613): on top of Sanctum's own
        // expires_at check, reject tokens that sat idle too long and tokens
        // issued before expiry existed that are past their absolute lifetime.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid && SessionTokenService::isStillValid($token)
        );

        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();
            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('register', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();
            return Limit::perMinute(3)->by($key);
        });
    }
}
