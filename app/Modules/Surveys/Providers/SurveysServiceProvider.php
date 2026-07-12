<?php

namespace App\Modules\Surveys\Providers;

use App\Modules\Surveys\Services\SurveysCapabilityProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SurveysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tag the module's CapabilityProvider as a legacy/advisory helper.
        // The canonical /api/user projection derives capabilities from
        // User::canonicalCapabilityNames() and does NOT iterate this tag.
        // See App\Modules\Core\Contracts\CapabilityProvider.
        $this->app->tag([SurveysCapabilityProvider::class], 'engined_capability_providers');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        $this->configureRateLimiting();
        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('survey-submit', function (Request $request) {
            $ip = $request->ip();

            // M-05: the client-supplied X-Fingerprint-Hash must NOT be the primary
            // throttle key — an attacker rotates it to bypass the limit entirely.
            // A server-issued invitation token is trustworthy; otherwise key on IP.
            $token = $request->route('token');
            $primary = $token
                ? Limit::perMinute(10)->by('survey-submit:token:'.$token)
                : Limit::perMinute(10)->by('survey-submit:ip:'.$ip);

            return [
                $primary,
                // Coarse per-IP backstop that holds regardless of token/header rotation.
                Limit::perMinutes(15, 60)->by('survey-submit:ip-backstop:'.$ip),
            ];
        });
    }
}
