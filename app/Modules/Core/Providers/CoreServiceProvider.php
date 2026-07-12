<?php

namespace App\Modules\Core\Providers;

use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Services\CanonicalAuthorizationAssignmentActorGuard;
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
        $this->app->bind(
            AuthorizationAssignmentActorGuard::class,
            CanonicalAuthorizationAssignmentActorGuard::class,
        );

        // Tag the Core module's CapabilityProvider under
        // engined_capability_providers for backwards compatibility with the
        // older /me iteration path. AuthController no longer iterates this
        // tag — canonical /api/user capabilities come from
        // User::canonicalCapabilityNames(). See
        // App\Modules\Core\Contracts\CapabilityProvider.
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
