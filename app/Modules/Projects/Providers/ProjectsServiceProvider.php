<?php

namespace App\Modules\Projects\Providers;

use App\Modules\Projects\Services\ProjectCapabilityProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProjectsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Tag the module's CapabilityProvider for the legacy
        // `engined_capability_providers` advisory tag. AuthController no
        // longer iterates this tag — the canonical /api/user projection
        // derives capabilities via User::canonicalCapabilityNames(). The
        // tag is kept so non-canonical advisory consumers can still
        // resolve the provider if they explicitly opt in. See
        // App\Modules\Core\Contracts\CapabilityProvider.
        $this->app->tag([ProjectCapabilityProvider::class], 'engined_capability_providers');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // تحميل Routes مع prefix /api
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');

        // Policies are registered in AppServiceProvider
    }
}
