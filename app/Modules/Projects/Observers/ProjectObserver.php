<?php

namespace App\Modules\Projects\Observers;

use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    /**
     * ترجمة أسماء الحقول للعربية
     */
    protected array $fieldLabels = [
        'name' => 'اسم المشروع',
        'code' => 'رمز المشروع',
        'description' => 'الوصف',
        'objectives' => 'الأهداف',
        'in_scope' => 'ضمن النطاق',
        'out_of_scope' => 'خارج النطاق',
        'department_id' => 'القسم',
        'status' => 'الحالة',
        'priority' => 'الأولوية',
        'start_date' => 'تاريخ البدء',
        'end_date' => 'تاريخ الانتهاء',
        'actual_start_date' => 'تاريخ البدء الفعلي',
        'actual_end_date' => 'تاريخ الانتهاء الفعلي',
        'progress' => 'نسبة الإنجاز',
        'budget' => 'الميزانية',
        'actual_cost' => 'التكلفة الفعلية',
        'human_resources' => 'الموارد البشرية',
        'technical_resources' => 'الموارد التقنية',
        'financial_resources' => 'الموارد المالية',
    ];

    /**
     * ترجمة قيم الحالة
     */
    protected array $statusLabels = [
        'draft' => 'مسودة',
        'planning' => 'تخطيط',
        'in_progress' => 'قيد التنفيذ',
        'on_hold' => 'معلق',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى',
    ];

    /**
     * ترجمة قيم الأولوية
     */
    protected array $priorityLabels = [
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'urgent' => 'عاجلة',
        'critical' => 'حرجة',
    ];

    /**
     * الحقول التي نريد تتبعها في سجل النشاط.
     *
     * Audit 2026-07-06 (deep analysis): methodology (PMBOK/FOCUS-PDCA) and closure
     * fields were missing from the tracked set, so edits to `current_pdca_phase`,
     * `lessons_learned`, `outcome_summary`, `business_case`, `problem_statement`
     * etc. were silent in the audit trail. The most load-bearing fields of the
     * FOCUS-PDCA methodology and the project closure lifecycle are now tracked.
     * The PDCA `advance()` path additionally writes its own ActivityLog entry
     * (see `ProjectPhaseService`) so the FSM transition is recorded even when
     * the field-level observer misses it.
     */
    protected array $trackedFields = [
        // Core
        'name', 'status', 'priority', 'department_id', 'program_id',
        'start_date', 'end_date', 'actual_start_date', 'actual_end_date',
        'progress', 'budget', 'actual_cost', 'objectives',
        'in_scope', 'out_of_scope', 'human_resources', 'technical_resources',
        'financial_resources', 'description',
        // Methodology (PMBOK)
        'business_case', 'success_criteria', 'requirements',
        'manager_authority', 'approval_criteria', 'exit_criteria',
        // Methodology (FOCUS-PDCA) — most load-bearing
        'problem_statement', 'target_process', 'root_cause', 'expected_benefits',
        'current_pdca_phase',
        // Closure
        'lessons_learned', 'outcome_summary', 'sustainability_plan',
        'achievement_percentage', 'achievement_status',
    ];

    /**
     * قبل تحديث مشروع: ختم تاريخ الإنجاز الفعلي (actual_end_date) عند الانتقال
     * إلى "مكتمل"، وإلغاؤه عند إعادة فتح مشروع مكتمل. مؤشّر "متوسط زمن الإنجاز"
     * في لوحة التحكم يعتمد على actual_end_date، فبدون ختمه يُستبعَد المشروع منه.
     */
    public function updating(Project $project): void
    {
        if (! $project->isDirty('status')) {
            return;
        }

        if ($project->status === 'completed') {
            if (empty($project->actual_end_date)) {
                $project->actual_end_date = now()->toDateString();
            }
            // اكتمال التنفيذ: نسبة الإنجاز 100% (اتساقاً مع المهام والمراحل).
            if ((int) $project->progress !== 100) {
                $project->progress = 100;
            }
        } elseif ($project->getOriginal('status') === 'completed') {
            // إعادة فتح مشروع مكتمل: ألغِ تاريخ الإنجاز الفعلي، وأعد حساب نسبة
            // الإنجاز من المهام الحالية بدلاً من إبقاء 100% المثبّتة. وإلا يظل
            // الـ dashboard وقائمة المشاريع يعرضان 100% لمشروع فعلياً 30%.
            $project->actual_end_date = null;
            $project->progress = $project->calculateProgress();
        }
    }

    /**
     * Data-integrity invariant (audit 2026-06-29, finding #4):
     * project.organization_id MUST equal its department's organization_id.
     *
     * Per project memory: a 2026-06-20 audit found 74 demos mis-tagged by
     * pasting the creator's org instead of the dept's; the org-switcher
     * becoming super_admin-only was a partial fix. The remaining hole is
     * the race window between FormRequest validation and the SQL UPDATE —
     * an Observer on saving closes it.
     *
     * Behavior: auto-correct (single source of truth = department). If
     * department_id is unset, leave organization_id untouched (the dept
     * assignment is the next step the user must do, and a stray null-org
     * is louder than an auto-pick). Auto-correct is preferred over throw
     * because throwing on production save mid-deploy would leave the user
     * in a half-saved state with no obvious recovery.
     */
    public function saving(Project $project): void
    {
        if (! $project->department_id) {
            return;
        }

        $dept = Department::query()
            ->whereKey($project->department_id)
            ->select(['id', 'organization_id'])
            ->first();

        if (! $dept || $dept->organization_id === null) {
            return;
        }

        if ((int) $project->organization_id !== (int) $dept->organization_id) {
            Log::warning('project.org invariant auto-corrected', [
                'project_id' => $project->id,
                'old_org' => $project->organization_id,
                'new_org' => $dept->organization_id,
                'dept_id' => $project->department_id,
            ]);

            $project->organization_id = $dept->organization_id;
        }
    }

    /**
     * عند إنشاء مشروع
     */
    public function created(Project $project): void
    {
        $this->clearDashboardCache($project);

        $this->logActivity($project, 'created', null, [
            'name' => $project->name,
            'code' => $project->code,
            'status' => $project->status,
        ]);
    }

    /**
     * عند تحديث مشروع
     */
    public function updated(Project $project): void
    {
        $this->clearDashboardCache($project);
        $changes = $project->getChanges();
        $original = $project->getOriginal();

        // تصفية التغييرات للحقول المهمة فقط
        $trackedChanges = array_intersect_key($changes, array_flip($this->trackedFields));

        if (empty($trackedChanges)) {
            return;
        }

        // بناء القيم القديمة والجديدة
        $oldValues = [];
        $newValues = [];

        foreach ($trackedChanges as $field => $newValue) {
            if ($field === 'updated_at') {
                continue;
            }

            $oldValues[$field] = $original[$field] ?? null;
            $newValues[$field] = $newValue;
        }

        $this->logActivity($project, 'updated', $oldValues, $newValues);
    }

    /**
     * عند حذف مشروع (soft delete)
     */
    public function deleted(Project $project): void
    {
        $this->clearDashboardCache($project);

        $this->logActivity($project, 'deleted', [
            'name' => $project->name,
            'code' => $project->code,
        ], null);
    }

    /**
     * عند استعادة مشروع محذوف
     */
    public function restored(Project $project): void
    {
        $this->clearDashboardCache($project);

        $this->logActivity($project, 'restored', null, [
            'name' => $project->name,
            'code' => $project->code,
        ]);
    }

    /**
     * مسح cache لوحة التحكم
     * يمسح cache الإحصائيات لمدير المشروع وأعضاء الفريق
     */
    protected function clearDashboardCache(Project $project): void
    {
        // مسح cache مدير المشروع (يُشتق من دور scoped)
        if ($project->manager) {
            Cache::forget("dashboard_stats_{$project->manager->id}");
        }

        // مسح cache منشئ المشروع
        if ($project->created_by) {
            Cache::forget("dashboard_stats_{$project->created_by}");
        }

        // مسح cache بيانات لوحة التحكم العامة
        Cache::forget('dashboard_stats');
        Cache::forget('projects_chart_data');
    }

    /**
     * تسجيل النشاط
     */
    protected function logActivity(Project $project, string $action, ?array $oldValues, ?array $newValues): void
    {
        // محاولة جلب المستخدم من guards مختلفة
        $userId = auth()->id()
            ?? auth('sanctum')->id()
            ?? request()->user()?->id;

        ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * الحصول على تسمية الحقل بالعربية
     */
    public function getFieldLabel(string $field): string
    {
        return $this->fieldLabels[$field] ?? $field;
    }

    /**
     * الحصول على تسمية القيمة بالعربية
     */
    public function getValueLabel(string $field, mixed $value): string
    {
        if ($value === null) {
            return 'غير محدد';
        }

        if ($field === 'status') {
            return $this->statusLabels[$value] ?? $value;
        }

        if ($field === 'priority') {
            return $this->priorityLabels[$value] ?? $value;
        }

        return (string) $value;
    }
}
