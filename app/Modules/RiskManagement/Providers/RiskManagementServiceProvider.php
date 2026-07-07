<?php

namespace App\Modules\RiskManagement\Providers;

use App\Modules\RiskManagement\Services\RiskCapabilityProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RiskManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tag the module's CapabilityProvider so AuthController can iterate
        // all engined_capability_providers without referencing this module
        // directly. See App\Modules\Core\Contracts\CapabilityProvider.
        $this->app->tag([RiskCapabilityProvider::class], 'engined_capability_providers');
    }

    /**
     * Bootstrap the module: load migrations and register the API route
     * file under the `api` prefix with the `auth:sanctum` middleware
     * applied at the file level (matches OVR pattern at
     * app/Modules/OVR/Providers/OVRServiceProvider.php:21-26 —
     * migrations + routes only, nothing else in boot()).
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/risk_management'));

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
