<?php

namespace App\Providers;

use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\HR\Providers\HRServiceProvider;
use App\Modules\Meetings\Providers\MeetingsServiceProvider;
use App\Modules\OVR\Providers\OVRServiceProvider;
use App\Modules\Performance\Providers\PerformanceServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Modules\RiskManagement\Providers\RiskManagementServiceProvider;
use App\Modules\Shared\Providers\SharedServiceProvider;
use App\Modules\Strategy\Providers\StrategyServiceProvider;
use App\Modules\Surveys\Providers\SurveysServiceProvider;
use App\Modules\Tasks\Providers\TasksServiceProvider;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    /**
     * The module service providers to be registered.
     *
     * @var array<class-string>
     */
    protected array $moduleProviders = [
        CoreServiceProvider::class,
        ProjectsServiceProvider::class,
        PerformanceServiceProvider::class,
        HRServiceProvider::class,
        SharedServiceProvider::class,
        TasksServiceProvider::class,
        StrategyServiceProvider::class,
        SurveysServiceProvider::class,
        OVRServiceProvider::class,
        // RiskManagement Module (إدارة المخاطر المؤسسية)
        RiskManagementServiceProvider::class,
        // Meetings Module (الاجتماعات والقرارات والتوصيات)
        MeetingsServiceProvider::class,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        foreach ($this->moduleProviders as $provider) {
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
