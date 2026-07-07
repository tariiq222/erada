<?php

namespace App\Modules\Tasks\Providers;

use App\Modules\Tasks\Repositories\Contracts\TaskRepositoryInterface;
use App\Modules\Tasks\Repositories\EloquentTaskRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TasksServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(TaskRepositoryInterface::class, EloquentTaskRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadRoutes();
        $this->loadMigrations();
    }

    /**
     * تحميل مسارات الموديول
     */
    protected function loadRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');
    }

    /**
     * تحميل الـ Migrations
     */
    protected function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }
}
