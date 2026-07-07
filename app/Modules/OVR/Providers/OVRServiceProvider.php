<?php

namespace App\Modules\OVR\Providers;

use App\Modules\OVR\Services\OvrCapabilityProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OVRServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tag the module's CapabilityProvider so AuthController can iterate
        // all engined_capability_providers without referencing this module
        // directly. See App\Modules\Core\Contracts\CapabilityProvider.
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
