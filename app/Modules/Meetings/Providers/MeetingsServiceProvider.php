<?php

namespace App\Modules\Meetings\Providers;

use App\Modules\Meetings\Console\Commands\CheckOverdueRecommendationsCommand;
use App\Modules\Meetings\Console\Commands\SendMeetingRemindersCommand;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Observers\MeetingObserver;
use App\Modules\Meetings\Observers\MeetingResolutionObserver;
use App\Modules\Meetings\Observers\RecommendationObserver;
use App\Modules\Meetings\Services\MeetingsCapabilityProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MeetingsServiceProvider extends ServiceProvider
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
        $this->app->tag([MeetingsCapabilityProvider::class], 'engined_capability_providers');
    }

    /**
     * Boot the Meetings service provider.
     *
     * Migrated tables: database/migrations/meetings/ (loaded via loadMigrationsFrom).
     *
     * Permissions:
     *   - view-meetings, manage-meetings, record-decisions
     *   - Installed by database/migrations/meetings/*add_meetings_permissions*.php
     *     AND database/seeders/Meetings/MeetingsPermissionsSeeder.php (idempotent).
     *   - Auto-picked up by RolesAndPermissionsSeeder::run() because they live in
     *     App\Modules\Core\Enums\Permission (Permission::cases()).
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/meetings'));

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SendMeetingRemindersCommand::class,
                CheckOverdueRecommendationsCommand::class,
            ]);
        }

        Meeting::observe(MeetingObserver::class);
        Recommendation::observe(RecommendationObserver::class);
        MeetingResolution::observe(MeetingResolutionObserver::class);
    }

    protected function registerRoutes(): void
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
