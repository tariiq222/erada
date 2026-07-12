<?php

namespace App\Modules\OVR\Providers;

use App\Modules\OVR\Services\OvrCapabilityProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OVRServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tag the module's CapabilityProvider for the legacy
        // `engined_capability_providers` advisory tag. AuthController no
        // longer iterates this tag — the canonical /api/user projection
        // derives capabilities via User::canonicalCapabilityNames(). The
        // tag is kept so non-canonical advisory consumers can still
        // resolve the provider if they explicitly opt in. See
        // App\Modules\Core\Contracts\CapabilityProvider.
        $this->app->tag([OvrCapabilityProvider::class], 'engined_capability_providers');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/ovr'));
        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
