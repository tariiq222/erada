<?php

namespace App\Modules\Core\Providers;

use App\Modules\Core\Services\CoreCapabilityProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Phase 8-C: tag the Core module's CapabilityProvider so
        // AuthController can iterate all engined_capability_providers
        // without referencing Core directly. See
        // App\Modules\Core\Contracts\CapabilityProvider. Today the
        // provider surfaces Capability::DASHBOARD_VIEW for the
        // /api/dashboard/* route gate and the SPA's useCan('dashboard.view').
        $this->app->tag([CoreCapabilityProvider::class], 'engined_capability_providers');
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
        // Super Admin bypass is handled in AppServiceProvider
    }
}
