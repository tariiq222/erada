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
        // Tag the module's CapabilityProvider so AuthController can iterate
        // all engined_capability_providers without referencing this module
        // directly. See App\Modules\Core\Contracts\CapabilityProvider.
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
