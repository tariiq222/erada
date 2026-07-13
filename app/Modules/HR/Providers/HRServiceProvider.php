<?php

namespace App\Modules\HR\Providers;

use App\Modules\HR\Services\HRCapabilityProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HRServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Tag the module's CapabilityProvider as a legacy/advisory helper.
        // The canonical /api/user projection derives capabilities from
        // User::canonicalCapabilityNames() and does NOT iterate this tag.
        // See App\Modules\Core\Contracts\CapabilityProvider.
        $this->app->tag([HRCapabilityProvider::class], 'engined_capability_providers');
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

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }
}
