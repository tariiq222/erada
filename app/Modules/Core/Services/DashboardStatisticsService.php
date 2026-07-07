<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use App\Modules\Projects\Scopes\UserTaskScope;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * خدمة إحصائيات لوحة التحكم
 *
 * مسؤولة عن حساب جميع الإحصائيات المستخدمة في Dashboard
 */
class DashboardStatisticsService
{
    public function __construct(
        protected UserProjectScope $projectScope,
        protected UserTaskScope $taskScope
    ) {}

    /**
     * الإحصائيات الأساسية (مشاريع + مهام + مستخدمين)
     */
    public function getBasicStats(User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = "dashboard_stats_{$user->id}_{$startDate}_{$endDate}";

        return Cache::remember($cacheKey, 300, function () use ($user, $startDate, $endDate) {
            $projectQuery = $this->buildProjectQuery($user, $startDate, $endDate);
            $taskQuery = $this->buildTaskQuery($user, $startDate, $endDate);

            return [
                'projects' => $this->calculateProjectStats($projectQuery),
                'tasks' => $this->calculateTaskStats($taskQuery),
                'users' => $user->isSuperAdmin() ? User::where('is_active', true)->count() : null,
            ];
        });
    }

    /**
     * الإحصائيات المتقدمة
     */
    public function getAdvancedStats(User $user): array
    {
        $cacheKey = "dashboard_advanced_stats_{$user->id}";

        return Cache::remember($cacheKey, 300, function () use ($user) {
            $projectQuery = Project::query();
            $this->projectScope->applySimple($projectQuery, $user);

            return [
                'avg_completion_time' => $this->calculateAvgCompletionTime(clone $projectQuery),
                'budget_summary' => $this->calculateBudgetSummary(clone $projectQuery),
                'departments_performance' => $this->calculateDepartmentsPerformance($user),
                'overdue_projects' => $this->countOverdueProjects(clone $projectQuery),
                'monthly_trends' => $this->calculateMonthlyTrends($user),
            ];
        });
    }

    /**
     * توزيع المشاريع حسب الحالة
     */
    public function getProjectsByStatus(User $user, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = Project::query();

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate.' 23:59:59']);
        }

        $this->projectScope->applySimple($query, $user);

        $data = $query->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $allStatuses = ['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'];
        $result = [];
        foreach ($allStatuses as $status) {
            $result[$status] = $data[$status] ?? 0;
        }

        return $result;
    }

    // ========== Query Builders ==========

    /**
     * بناء استعلام المشاريع مع الصلاحيات والفلترة
     */
    protected function buildProjectQuery(User $user, ?string $startDate, ?string $endDate): Builder
    {
        $query = Project::query();

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate.' 23:59:59']);
        }

        $this->projectScope->applySimple($query, $user);

        return $query;
    }

    /**
     * بناء استعلام المهام مع الصلاحيات والفلترة
     */
    protected function buildTaskQuery(User $user, ?string $startDate, ?string $endDate): Builder
    {
        $query = Task::query();

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate.' 23:59:59']);
        }

        $this->taskScope->apply($query, $user);

        return $query;
    }

    // ========== Statistics Calculators ==========

    /**
     * حساب إحصائيات المشاريع — query واحدة بدلاً من 7 queries
     */
    protected function calculateProjectStats(Builder $query): array
    {
        $row = (clone $query)->selectRaw("
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as active,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'on_hold' THEN 1 END) as on_hold,
            COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft,
            COUNT(CASE WHEN status = 'planning' THEN 1 END) as planning,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
        ")->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'on_hold' => (int) ($row->on_hold ?? 0),
            'draft' => (int) ($row->draft ?? 0),
            'planning' => (int) ($row->planning ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
        ];
    }

    /**
     * حساب إحصائيات المهام — query واحدة بدلاً من 5 queries
     */
    protected function calculateTaskStats(Builder $query): array
    {
        $now = now()->toDateTimeString();

        $row = (clone $query)->selectRaw("
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'todo' THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status != 'completed' AND due_date IS NOT NULL AND due_date < ? THEN 1 END) as overdue
        ", [$now])->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'in_progress' => (int) ($row->in_progress ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
        ];
    }

    /**
     * حساب متوسط وقت إنجاز المشاريع (بالأيام)
     */
    protected function calculateAvgCompletionTime(Builder $query): ?float
    {
        $completed = (clone $query)
            ->where('status', 'completed')
            ->whereNotNull('actual_end_date')
            ->whereNotNull('start_date')
            ->selectRaw('AVG(actual_end_date::date - start_date::date) as avg_days')
            ->first();

        return $completed?->avg_days ? round((float) $completed->avg_days, 1) : null;
    }

    /**
     * حساب ملخص الميزانية
     */
    protected function calculateBudgetSummary(Builder $query): array
    {
        $summary = (clone $query)
            ->selectRaw('
                COALESCE(SUM(budget), 0) as total_budget,
                COALESCE(SUM(actual_cost), 0) as total_actual,
                COUNT(CASE WHEN actual_cost > budget THEN 1 END) as over_budget_count
            ')
            ->first();

        $totalBudget = (float) ($summary?->total_budget ?? 0);
        $totalActual = (float) ($summary?->total_actual ?? 0);

        return [
            'total_budget' => $totalBudget,
            'total_actual' => $totalActual,
            'variance' => $totalBudget - $totalActual,
            'variance_percentage' => $totalBudget > 0
                ? round((($totalBudget - $totalActual) / $totalBudget) * 100, 1)
                : 0,
            'over_budget_count' => (int) ($summary?->over_budget_count ?? 0),
        ];
    }

    /**
     * حساب أداء الأقسام
     */
    protected function calculateDepartmentsPerformance(User $user): array
    {
        $query = Department::with(['projects' => function ($q) use ($user) {
            if (! $user->isSuperAdmin() && $user->isAdmin()) {
                $q->where('department_id', $user->department_id);
            }
        }])
            ->withCount([
                'projects',
                'projects as completed_projects_count' => fn ($q) => $q->where('status', 'completed'),
                'projects as active_projects_count' => fn ($q) => $q->where('status', 'in_progress'),
                'projects as overdue_projects_count' => fn ($q) => $q
                    ->where('status', '!=', 'completed')
                    ->whereNotNull('end_date')
                    ->where('end_date', '<', now()),
            ]);

        if (! $user->isSuperAdmin() && $user->isAdmin()) {
            $query->where('id', $user->department_id);
        }

        $departments = $query->limit(10)->get();

        return $departments->map(function ($dept) {
            $completionRate = $dept->projects_count > 0
                ? round(($dept->completed_projects_count / $dept->projects_count) * 100, 1)
                : 0;

            return [
                'id' => $dept->id,
                'name' => $dept->name,
                'total_projects' => $dept->projects_count,
                'completed' => $dept->completed_projects_count,
                'active' => $dept->active_projects_count,
                'overdue' => $dept->overdue_projects_count,
                'completion_rate' => $completionRate,
            ];
        })->toArray();
    }

    /**
     * عدد المشاريع المتأخرة
     */
    protected function countOverdueProjects(Builder $query): array
    {
        $overdue = (clone $query)
            ->where('status', '!=', 'completed')
            ->whereNotNull('end_date')
            ->where('end_date', '<', now());

        $critical = (clone $overdue)
            ->where('end_date', '<', now()->subDays(30));

        return [
            'total' => $overdue->count(),
            'critical' => $critical->count(),
        ];
    }

    /**
     * اتجاهات شهرية (آخر 6 أشهر)
     */
    protected function calculateMonthlyTrends(User $user): array
    {
        $months = [];
        $startDate = now()->subMonths(5)->startOfMonth();

        for ($i = 0; $i < 6; $i++) {
            $monthStart = $startDate->copy()->addMonths($i);
            $monthEnd = $monthStart->copy()->endOfMonth();

            $projectQuery = Project::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd]);

            $completedQuery = Project::query()
                ->where('status', 'completed')
                ->whereBetween('actual_end_date', [$monthStart, $monthEnd]);

            $tasksQuery = Task::query()
                ->where('status', 'completed')
                ->whereBetween('updated_at', [$monthStart, $monthEnd]);

            $this->applyUserFilter($projectQuery, $user);
            $this->applyUserFilter($completedQuery, $user);
            $this->applyTaskUserFilter($tasksQuery, $user);

            $months[] = [
                'month' => $monthStart->format('Y-m'),
                'month_name' => $this->getArabicMonthName($monthStart->month),
                'projects_started' => $projectQuery->count(),
                'projects_completed' => $completedQuery->count(),
                'tasks_completed' => $tasksQuery->count(),
            ];
        }

        return $months;
    }

    /**
     * تطبيق فلتر المستخدم على استعلام المشاريع
     */
    protected function applyUserFilter(Builder $query, User $user): void
    {
        if (! $user->isSuperAdmin() && $user->isAdmin()) {
            $query->where('department_id', $user->department_id);
        }
    }

    /**
     * تطبيق فلتر المستخدم على استعلام المهام
     */
    protected function applyTaskUserFilter(Builder $query, User $user): void
    {
        if (! $user->isSuperAdmin() && $user->isAdmin()) {
            $query->whereHas('project', fn ($q) => $q->where('department_id', $user->department_id));
        }
    }

    /**
     * الحصول على اسم الشهر بالعربية
     */
    public function getArabicMonthName(int $month): string
    {
        $months = [
            1 => 'يناير',
            2 => 'فبراير',
            3 => 'مارس',
            4 => 'أبريل',
            5 => 'مايو',
            6 => 'يونيو',
            7 => 'يوليو',
            8 => 'أغسطس',
            9 => 'سبتمبر',
            10 => 'أكتوبر',
            11 => 'نوفمبر',
            12 => 'ديسمبر',
        ];

        return $months[$month] ?? '';
    }
}
