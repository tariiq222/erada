<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\DashboardStatisticsService;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use App\Modules\Projects\Scopes\UserTaskScope;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DashboardController - API لوحة التحكم
 *
 * يعرض endpoints للإحصائيات والبيانات المستخدمة في Dashboard
 * منطق الحسابات موجود في DashboardStatisticsService
 */
class DashboardController extends Controller
{
    public function __construct(
        protected DashboardStatisticsService $statisticsService,
        protected UserProjectScope $projectScope,
        protected UserTaskScope $taskScope
    ) {}

    /**
     * إحصائيات لوحة التحكم الأساسية
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $stats = $this->statisticsService->getBasicStats($user, $startDate, $endDate);

        return response()->json($stats);
    }

    /**
     * أحدث المشاريع
     */
    public function recentProjects(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Project::with(['department'])
            ->orderBy('created_at', 'desc')
            ->limit(5);

        $this->projectScope->applySimple($query, $user);

        return response()->json($query->get());
    }

    /**
     * المهام المتأخرة
     */
    public function overdueTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Task::with(['project', 'assignee'])
            ->where('status', '!=', 'completed')
            ->where('due_date', '<', now())
            ->orderBy('due_date', 'asc')
            ->limit(10);

        $this->taskScope->applyViaProject($query, $user);

        return response()->json($query->get());
    }

    /**
     * مهامي القادمة
     */
    public function myUpcomingTasks(Request $request): JsonResponse
    {
        $tasks = Task::with(['project'])
            ->where('assigned_to', $request->user()->id)
            ->whereIn('status', ['todo', 'in_progress'])
            ->whereNull('deleted_at') // soft delete check
            ->whereDate('due_date', '>=', now()->toDateString())
            ->orderBy('due_date', 'asc')
            ->limit(10)
            ->get();

        return response()->json($tasks);
    }

    /**
     * إحصائيات المشاريع حسب الحالة (للرسم البياني)
     */
    public function projectsByStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $result = $this->statisticsService->getProjectsByStatus($user, $startDate, $endDate);

        return response()->json($result);
    }

    /**
     * إحصائيات متقدمة للمشاريع
     */
    public function advancedStats(Request $request): JsonResponse
    {
        $user = $request->user();

        $stats = $this->statisticsService->getAdvancedStats($user);

        return response()->json($stats);
    }
}
