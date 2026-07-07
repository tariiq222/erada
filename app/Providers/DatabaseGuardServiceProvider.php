<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * DatabaseGuardServiceProvider
 *
 * يمنع استخدام SQLite في التطبيق.
 * PostgreSQL هي قاعدة البيانات الوحيدة المدعومة.
 */
class DatabaseGuardServiceProvider extends ServiceProvider
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
        // Skip check during artisan commands that don't need DB
        if ($this->app->runningInConsole() && $this->isNonDbCommand()) {
            return;
        }

        // Check database driver after connection is established
        $this->app->booted(function () {
            $this->guardAgainstSqlite();
        });
    }

    /**
     * التحقق من عدم استخدام SQLite
     */
    protected function guardAgainstSqlite(): void
    {
        try {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'sqlite') {
                throw new RuntimeException(
                    "SQLite is not supported. Please configure PostgreSQL.\n".
                    "Set DB_CONNECTION=pgsql in your .env file.\n".
                    'Run: docker compose up -d postgres'
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Database not yet configured, skip check
        }
    }

    /**
     * الأوامر التي لا تحتاج DB
     */
    protected function isNonDbCommand(): bool
    {
        $nonDbCommands = [
            'key:generate',
            'config:cache',
            'config:clear',
            'route:cache',
            'route:clear',
            'view:cache',
            'view:clear',
            'cache:clear',
            'optimize:clear',
            'package:discover',
            'vendor:publish',
        ];

        $command = $_SERVER['argv'][1] ?? null;

        return in_array($command, $nonDbCommands, true);
    }
}
