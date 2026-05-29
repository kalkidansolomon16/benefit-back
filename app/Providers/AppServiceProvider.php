<?php

namespace App\Providers;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
