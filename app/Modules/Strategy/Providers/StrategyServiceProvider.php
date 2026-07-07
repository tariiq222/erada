<?php

namespace App\Modules\Strategy\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class StrategyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // تحميل Routes مع prefix /api
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');

        // Load migrations from the shared migrations folder
        $this->loadMigrationsFrom(database_path('migrations'));
    }
}
