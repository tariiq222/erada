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
        // Tag the module's CapabilityProvider so AuthController can iterate
        // all engined_capability_providers without referencing this module
        // directly. See App\Modules\Core\Contracts\CapabilityProvider.
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
