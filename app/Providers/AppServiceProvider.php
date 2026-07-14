<?php

namespace App\Providers;

use App\Modules\Core\Models\SystemSettings;
use App\Modules\Core\Models\User;
use App\Modules\Core\Observers\UserObserver;
use App\Modules\Core\Policies\SystemSettingsPolicy;
use App\Modules\Core\Policies\UserPolicy;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Models\EmployeeCertificate;
use App\Modules\HR\Models\EmployeePersonalInfo;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Observers\DepartmentCapacityRoleObserver;
use App\Modules\HR\Observers\DepartmentObserver;
use App\Modules\HR\Policies\DepartmentPolicy;
use App\Modules\HR\Policies\EmployeeCertificatePolicy;
use App\Modules\HR\Policies\EmployeePersonalInfoPolicy;
use App\Modules\HR\Policies\EmployeeProfilePolicy;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Policies\MeetingPolicy;
use App\Modules\Meetings\Policies\MeetingResolutionPolicy;
use App\Modules\Meetings\Policies\RecommendationPolicy;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Policies\IncidentReportPolicy;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Performance\Models\KpiMeasurement;
use App\Modules\Performance\Policies\KpiLinkPolicy;
use App\Modules\Performance\Policies\KpiMeasurementPolicy;
use App\Modules\Performance\Policies\KpiPolicy;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Observers\ProjectObserver;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Policies\RiskActionPolicy;
use App\Modules\RiskManagement\Policies\RiskPolicy;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Shared\Policies\ActivityLogPolicy;
use App\Modules\Shared\Policies\AttachmentPolicy;
use App\Modules\Shared\Policies\CommentPolicy;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Observers\PortfolioObserver;
use App\Modules\Strategy\Observers\ProgramObserver;
use App\Modules\Strategy\Policies\PortfolioPolicy;
use App\Modules\Strategy\Policies\ProgramPolicy;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Policies\SurveyPolicy;
use App\Modules\Surveys\Policies\SurveyResponsePolicy;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Observers\TaskObserver;
use App\Modules\Tasks\Policies\TaskPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
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
        // Prevent silent N+1 queries in non-production environments. Any lazy-load
        // that fires without an explicit eager-load throws LazyLoadingViolationException
        // so it is caught in dev/staging before it reaches production.
        Model::preventLazyLoading(! app()->isProduction());

        // تعطيل wrapping للـ JsonResource (يُرجع البيانات مباشرة بدون "data" wrapper)
        JsonResource::withoutWrapping();

        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }

        // إجبار HTTPS في Production
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // تسجيل Observers
        Project::observe(ProjectObserver::class);
        Task::observe(TaskObserver::class);
        User::observe(UserObserver::class);
        Department::observe(DepartmentObserver::class);
        DepartmentCapacityRole::observe(DepartmentCapacityRoleObserver::class);
        Portfolio::observe(PortfolioObserver::class);
        Program::observe(ProgramObserver::class);

        // تسجيل Policies
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(EmployeeProfile::class, EmployeeProfilePolicy::class);
        Gate::policy(EmployeePersonalInfo::class, EmployeePersonalInfoPolicy::class);
        Gate::policy(EmployeeCertificate::class, EmployeeCertificatePolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(SystemSettings::class, SystemSettingsPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(Attachment::class, AttachmentPolicy::class);
        Gate::policy(Portfolio::class, PortfolioPolicy::class);
        Gate::policy(Program::class, ProgramPolicy::class);
        Gate::policy(IncidentReport::class, IncidentReportPolicy::class);
        Gate::policy(Kpi::class, KpiPolicy::class);
        Gate::policy(KpiMeasurement::class, KpiMeasurementPolicy::class);
        Gate::policy(KpiLink::class, KpiLinkPolicy::class);
        Gate::policy(Risk::class, RiskPolicy::class);
        Gate::policy(RiskAction::class, RiskActionPolicy::class);
        Gate::policy(SurveyResponse::class, SurveyResponsePolicy::class);
        Gate::policy(Survey::class, SurveyPolicy::class);
        Gate::policy(Meeting::class, MeetingPolicy::class);
        Gate::policy(Recommendation::class, RecommendationPolicy::class);
        Gate::policy(MeetingResolution::class, MeetingResolutionPolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        // Super admins bypass ordinary gates. Recommendation lifecycle actions
        // deliberately reach RecommendationPolicy so kind and four-eyes
        // invariants remain enforced even for a super admin.
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            if (! $user->isSuperAdmin()) {
                return null;
            }

            $target = $arguments[0] ?? null;
            if ($target instanceof Recommendation
                && in_array($ability, RecommendationPolicy::LIFECYCLE_ABILITIES, true)) {
                return null;
            }

            return true;
        });

        // Listen for scheduled task failures (Laravel 12: Illuminate\Console\Events\ScheduledTaskFailed).
        // Without this, scheduler failures only land in laravel.log with no alert path.
        Event::listen(
            ScheduledTaskFailed::class,
            function (ScheduledTaskFailed $event) {
                Log::error('Scheduled task failed', [
                    'command' => $event->task->command ?? null,
                    'exit_code' => $event->task->exitCode ?? null,
                    'output' => substr((string) ($event->task->output ?? ''), 0, 2000),
                ]);
                if (function_exists('Sentry\captureMessage')) {
                    \Sentry\captureMessage('Scheduled task failed: '.($event->task->command ?? 'unknown'));
                }
            }
        );

        // Listen for queue job failures (Laravel 12: Illuminate\Queue\Events\JobFailed).
        // Without this, exhausted jobs only land in `failed_jobs` with no alert path.
        Event::listen(
            JobFailed::class,
            function (JobFailed $event) {
                Log::error('Queue job failed', [
                    'job' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                    'exception' => $event->exception->getMessage(),
                ]);
                if (function_exists('Sentry\captureException')) {
                    \Sentry\captureException($event->exception);
                }
            }
        );

        // تكوين Rate Limiting
        $this->configureRateLimiting();
    }

    /**
     * تكوين حدود معدل الطلبات
     */
    protected function configureRateLimiting(): void
    {
        // حد تسجيل الدخول: 5 محاولات في الدقيقة. CI/E2E exercises several
        // isolated browser contexts from one runner IP, so keep the production
        // limit while giving the testing environment enough headroom to avoid
        // masking the actual authentication contract with a 429.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(app()->environment('testing') ? 100 : 5)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'لقد تجاوزت الحد المسموح من محاولات تسجيل الدخول. يرجى الانتظار دقيقة واحدة.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429);
                });
        });

        // حد API العام: 200 طلب في الدقيقة (زيادة لدعم SPA مع طلبات متعددة متزامنة)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(200)
                ->by($request->user()?->id ?: $request->ip());
        });

        // حد الـ Routes العامة (بدون مصادقة): 300 طلب في الدقيقة
        // هذه الـ routes تُستدعى بشكل متكرر من كل الزوار
        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        // حد رفع الملفات: 10 ملفات في الدقيقة
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip());
        });

        // حد كلمة المرور: 3 محاولات في 5 دقائق
        RateLimiter::for('password', function (Request $request) {
            return Limit::perMinutes(5, 3)
                ->by($request->user()?->id ?: $request->ip());
        });

        // حد العمليات الحساسة (إنشاء/تحديث/حذف): 30 عملية في الدقيقة
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'لقد تجاوزت الحد المسموح من العمليات. يرجى الانتظار.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429);
                });
        });

        // OTP issue/verify: 5 attempts per 15 minutes per email+IP, PLUS a coarse
        // per-IP ceiling (M-06) so one IP cannot bomb many distinct emails.
        RateLimiter::for('otp', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            $response = function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'لقد تجاوزت الحد المسموح من المحاولات. يرجى المحاولة لاحقاً.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429);
            };

            return [
                Limit::perMinutes(15, 5)->by('otp:'.$email.'|'.$request->ip())->response($response),
                Limit::perMinutes(15, 20)->by('otp:ip:'.$request->ip())->response($response),
            ];
        });

        // حد العمليات الإدارية (للمديرين): 20 عملية في الدقيقة
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'لقد تجاوزت الحد المسموح من العمليات الإدارية.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429);
                });
        });

        // حد الحذف: 10 عمليات حذف في الدقيقة
        RateLimiter::for('delete', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'لقد تجاوزت الحد المسموح من عمليات الحذف.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429);
                });
        });

        // Blade directive لإخراج nonce الخاص بـ CSP
        Blade::directive('cspNonce', function (): string {
            return "<?php echo e(request()?->attributes->get('csp_nonce') ?? ''); ?>";
        });

    }
}
