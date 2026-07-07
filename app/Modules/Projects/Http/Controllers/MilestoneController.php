<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Requests\DeleteProjectMilestoneRequest;
use App\Modules\Projects\Http\Requests\StoreProjectMilestoneRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectMilestoneRequest;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\Project\MilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    public function __construct(protected MilestoneService $milestoneService) {}

    /**
     * عرض مراحل مشروع محدد
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
        ]);

        $project = Project::findOrFail($request->project_id);
        $this->authorize('view', $project);

        $milestones = Milestone::where('project_id', $request->project_id)
            ->orderBy('order')
            ->orderBy('start_date')
            ->get();

        return response()->json($milestones);
    }

    /**
     * إنشاء مرحلة جديدة
     */
    public function store(StoreProjectMilestoneRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Authz has already been enforced by the FormRequest against the
        // resolved project's `update` ability — re-resolve here only to keep
        // the business invariants (capacity, ordering) readable.
        $project = $request->getProject();

        // حساب المدة بالأيام
        $durationDays = $this->calculateDurationInDays(
            $validated['duration_value'],
            $validated['duration_unit']
        );

        // التحقق من أن المدة لا تتجاوز مدة المشروع
        if ($project->start_date && $project->end_date) {
            $projectDurationDays = $project->start_date->diffInDays($project->end_date);

            // حساب المدة المستخدمة من المراحل الموجودة
            $usedDays = Milestone::where('project_id', $project->id)
                ->whereNotNull('start_date')
                ->whereNotNull('due_date')
                ->get()
                ->sum(function ($m) {
                    return $m->start_date->diffInDays($m->due_date);
                });

            if (($usedDays + $durationDays) > $projectDurationDays) {
                return response()->json([
                    'message' => 'مدة المرحلة تتجاوز المدة المتبقية للمشروع',
                    'errors' => [
                        'duration_value' => ['مدة المرحلة تتجاوز المدة المتبقية للمشروع. المدة المتبقية: '.($projectDurationDays - $usedDays).' يوم'],
                    ],
                ], 422);
            }
        }

        // حساب تاريخ البداية والنهاية
        $lastMilestone = Milestone::where('project_id', $validated['project_id'])
            ->orderBy('order', 'desc')
            ->first();

        if ($lastMilestone && $lastMilestone->due_date) {
            $startDate = $lastMilestone->due_date->copy()->addDay();
        } elseif ($project->start_date) {
            $startDate = $project->start_date->copy();
        } else {
            $startDate = now();
        }

        $dueDate = $startDate->copy()->addDays($durationDays - 1);

        // تعيين الترتيب التلقائي
        $maxOrder = Milestone::where('project_id', $validated['project_id'])->max('order') ?? 0;

        $milestoneData = [
            'project_id' => $validated['project_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'order' => $maxOrder + 1,
            'status' => $validated['status'] ?? 'pending',
            'progress' => 0,
        ];

        $milestone = Milestone::create($milestoneData);

        return response()->json([
            'message' => 'تم إنشاء المرحلة بنجاح',
            'milestone' => $milestone,
        ], 201);
    }

    /**
     * حساب المدة بالأيام
     */
    private function calculateDurationInDays(int $value, string $unit): int
    {
        return match ($unit) {
            'day' => $value,
            'week' => $value * 7,
            'month' => $value * 30,
            default => $value,
        };
    }

    /**
     * عرض مرحلة محددة
     */
    public function show(string $id): JsonResponse
    {
        $milestone = Milestone::with(['project', 'tasks.assignee'])->findOrFail($id);

        $this->authorize('view', $milestone->project);

        return response()->json($milestone);
    }

    /**
     * تحديث مرحلة
     */
    public function update(UpdateProjectMilestoneRequest $request, string $id): JsonResponse
    {
        // Authz + resolution already done by the FormRequest against the
        // milestone's parent project's `update` ability.
        $milestone = $request->getMilestone();

        $validated = $request->validated();

        // تحديث تاريخ الإكمال تلقائياً
        if (isset($validated['status']) && $validated['status'] === 'completed' && ! $milestone->completed_date) {
            $validated['completed_date'] = now();
            $validated['progress'] = 100;
        }

        $milestone->update($validated);

        return response()->json([
            'message' => 'تم تحديث المرحلة بنجاح',
            'milestone' => $milestone,
        ]);
    }

    /**
     * حذف مرحلة
     */
    public function destroy(DeleteProjectMilestoneRequest $request, string $id): JsonResponse
    {
        // Authz + resolution already done by the FormRequest against the
        // milestone's parent project's `update` ability.
        $milestone = $request->getMilestone();

        // التحقق من عدم وجود مهام مرتبطة — قاعدة عمل، ليست تحققاً/تفويضاً.
        if ($milestone->tasks()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف مرحلة بها مهام. قم بنقل أو حذف المهام أولاً.',
            ], 422);
        }

        $this->milestoneService->deleteMilestone($milestone);

        return response()->json([
            'message' => 'تم حذف المرحلة بنجاح',
        ]);
    }
}
