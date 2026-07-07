<?php

namespace App\Modules\Tasks\Observers;

use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Facades\Cache;

class TaskObserver
{
    /**
     * قبل الحفظ: مزامنة نسبة الإنجاز مع الحالة (مصدر واحد للحقيقة).
     *
     * - الانتقال إلى "مكتملة" ⇒ progress = 100 (يصحّح نقص نسبة المشروع).
     * - الخروج من "مكتملة" ⇒ progress = 0 ما لم تُضبط صراحةً في نفس الحفظة
     *   (يمنع تضخّم النسبة بعد إلغاء الإكمال).
     */
    public function saving(Task $task): void
    {
        // Phase 4 (AuthZ unification): when source_type is null and the
        // caller is creating a project- or department-scoped task, stamp
        // the polymorphic source from the legacy fields. Mirrors the
        // migration's backfill so controllers, seeders, and factories
        // that go through Eloquent (and skip the migration) get the same
        // shape. Explicit polymorphic sources (Risk / Recommendation / OVR)
        // are never overridden.
        $this->stampLegacySourceIfAbsent($task);

        // يُطبَّق على انتقالات الحالة للمهام الموجودة فقط — لا يدوس
        // نسبة الإنجاز الصريحة عند الإنشاء.
        if (! $task->exists || ! $task->isDirty('status')) {
            return;
        }

        $new = $task->status instanceof TaskStatus ? $task->status : TaskStatus::tryFrom((string) $task->status);
        $origRaw = $task->getOriginal('status');
        $orig = $origRaw instanceof TaskStatus ? $origRaw : TaskStatus::tryFrom((string) $origRaw);

        if ($new === TaskStatus::COMPLETED) {
            $task->progress = 100;
        } elseif ($orig === TaskStatus::COMPLETED && ! $task->isDirty('progress')) {
            $task->progress = 0;
        }
    }

    /**
     * Stamp the polymorphic source from project_id / department_id when the
     * caller did not set one explicitly. Idempotent: re-saving a task with
     * the same legacy fields does not reset a polymorphic source.
     */
    private function stampLegacySourceIfAbsent(Task $task): void
    {
        if ($task->source_type !== null) {
            return;
        }

        if ($task->project_id !== null) {
            $task->source_type = 'Project';
            $task->source_id = (int) $task->project_id;

            return;
        }

        if ($task->department_id !== null) {
            $task->source_type = 'Department';
            $task->source_id = (int) $task->department_id;
        }
    }

    /**
     * ترجمة أسماء الحقول للعربية
     */
    protected array $fieldLabels = [
        'title' => 'عنوان المهمة',
        'description' => 'الوصف',
        'status' => 'الحالة',
        'priority' => 'الأولوية',
        'assigned_to' => 'المكلف',
        'start_date' => 'تاريخ البدء',
        'due_date' => 'تاريخ الاستحقاق',
        'completed_date' => 'تاريخ الإكمال',
        'progress' => 'نسبة الإنجاز',
        'estimated_hours' => 'الساعات المقدرة',
        'actual_hours' => 'الساعات الفعلية',
    ];

    /**
     * ترجمة قيم الحالة
     */
    protected array $statusLabels = [
        'todo' => 'للتنفيذ',
        'in_progress' => 'قيد التنفيذ',
        'in_review' => 'قيد المراجعة',
        'completed' => 'مكتملة',
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
     * الحقول التي نريد تتبعها
     */
    protected array $trackedFields = [
        'title', 'status', 'priority', 'assigned_to',
        'start_date', 'due_date', 'completed_date',
        'progress', 'estimated_hours', 'actual_hours', 'description',
    ];

    /**
     * عند إنشاء مهمة
     */
    public function created(Task $task): void
    {
        $this->clearDashboardCache($task);

        $this->logActivity($task, 'created', null, [
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
        ]);

        // تسجيل نشاط المهمة الفرعية في المهمة الأم
        if ($task->parent_id) {
            $this->logSubtaskActivity($task, 'subtask_created');
        }
    }

    /**
     * عند تحديث مهمة
     */
    public function updated(Task $task): void
    {
        $this->clearDashboardCache($task);

        $changes = $task->getChanges();
        $original = $task->getOriginal();

        // تصفية التغييرات للحقول المهمة فقط
        $trackedChanges = array_intersect_key($changes, array_flip($this->trackedFields));

        if (! empty($trackedChanges)) {
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

            $this->logActivity($task, 'updated', $oldValues, $newValues);

            // تسجيل نشاط المهمة الفرعية
            if ($task->parent_id) {
                $this->logSubtaskActivity($task, 'subtask_updated');
            }
        }

        // ملاحظة: تحديث نسبة إنجاز المشروع يتم في saved() (يغطّي created+updated)،
        // فلا نكرّره هنا.
    }

    /**
     * عند حذف مهمة (soft delete)
     */
    public function deleted(Task $task): void
    {
        $this->clearDashboardCache($task);

        $this->logActivity($task, 'deleted', [
            'title' => $task->title,
        ], null);

        // تسجيل نشاط المهمة الفرعية
        if ($task->parent_id) {
            $this->logSubtaskActivity($task, 'subtask_deleted');
        }

        // تحديث نسبة إنجاز المشروع
        $this->updateProjectProgress($task);
    }

    /**
     * عند استعادة مهمة محذوفة
     */
    public function restored(Task $task): void
    {
        $this->clearDashboardCache($task);

        $this->logActivity($task, 'restored', null, [
            'title' => $task->title,
        ]);

        // تحديث نسبة إنجاز المشروع
        $this->updateProjectProgress($task);
    }

    /**
     * عند حفظ المهمة (created أو updated)
     *
     * هوك موحَّد لتحديث نسبة إنجاز المشروع — يغطّي create و update في موقع
     * واحد بدل استدعاء `updateProjectProgress` داخل كل من `created()` و
     * `updated()` (يقلّل خطر الازدواج ويُسنِد المسؤولية لمكان واضح).
     *
     * `deleted()` و `restored()` يتولّيان التحديث صراحةً، فنتخطّى هنا إذا
     * تغيّر `deleted_at` في نفس الحفظة (يخصّ restore الذي يستدعي save()
     * داخلياً ويُطلق saved() مع deleted_at=null بعد إزالته).
     */
    public function saved(Task $task): void
    {
        // soft-delete / restore: `deleted()` و `restored()` يحدّثان النسبة صراحةً
        if ($task->wasChanged('deleted_at')) {
            return;
        }

        $this->updateProjectProgress($task);

        // رفع نسبة المهمة الأم من متوسط المهام الفرعية غير الملغاة
        // (بعد تحديث نسبة المشروع لتبقى النسبتان متّسقتين)
        $this->updateParentProgress($task);
    }

    /**
     * تحديث نسبة إنجاز المشروع
     */
    protected function updateProjectProgress(Task $task): void
    {
        if ($task->isProjectTask() && $task->project) {
            $task->project->updateProgress();
        }
    }

    /**
     * رفع نسبة إنجاز المهمة الأم من متوسط نسب المهام الفرعية غير الملغاة.
     *
     * أمان من العودية: لا نستخدم Task::withoutEvents — نريد أن تتابع سلسلة
     * الرفع للأعلى بشكل طبيعي (parent.saved → جدّ الرفع → جدّه ...). سلسلة
     * parent_id هي DAG (لا دورات) لأن parent_id NULL على الجذر، فالتكرار
     * يتوقّف حتماً. كما نتخطّى الكتابة عندما لا تتغير النسبة فعلاً
     * لتفادي كتابة لا داعي لها في DB.
     */
    protected function updateParentProgress(Task $task): void
    {
        if (! $task->parent_id) {
            return;
        }

        $parent = Task::find($task->parent_id);
        if (! $parent) {
            return;
        }

        $cancelled = TaskStatus::CANCELLED;
        $subtasks = $parent->subtasks()
            ->where('status', '!=', $cancelled->value)
            ->get();

        if ($subtasks->isEmpty()) {
            return;
        }

        $avg = (int) round((float) $subtasks->avg('progress'));

        if ((int) $parent->progress === $avg) {
            return;
        }

        $parent->update(['progress' => $avg]);
    }

    /**
     * تسجيل نشاط المهمة الفرعية في المهمة الأم
     */
    protected function logSubtaskActivity(Task $task, string $action): void
    {
        if (! $task->parent_id) {
            return;
        }

        ActivityLog::create([
            'user_id' => auth()->id() ?? auth('sanctum')->id(),
            'action' => $action,
            'loggable_type' => Task::class,
            'loggable_id' => $task->parent_id,
            'description' => "مهمة فرعية: {$task->title}",
            'new_values' => [
                'subtask_id' => $task->id,
                'subtask_title' => $task->title,
            ],
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * مسح cache لوحة التحكم
     */
    protected function clearDashboardCache(Task $task): void
    {
        // مسح cache المكلف بالمهمة
        if ($task->assigned_to) {
            Cache::forget("dashboard_stats_{$task->assigned_to}");
        }

        // مسح cache منشئ المهمة
        if ($task->created_by) {
            Cache::forget("dashboard_stats_{$task->created_by}");
        }

        // مسح cache مدير المشروع إذا كان موجوداً (يُشتق من دور scoped)
        if ($task->project && $task->project->manager) {
            Cache::forget("dashboard_stats_{$task->project->manager->id}");
        }

        // مسح cache بيانات لوحة التحكم العامة
        Cache::forget('dashboard_stats');
    }

    /**
     * تسجيل النشاط
     */
    protected function logActivity(Task $task, string $action, ?array $oldValues, ?array $newValues): void
    {
        // محاولة جلب المستخدم من guards مختلفة
        $userId = auth()->id()
            ?? auth('sanctum')->id()
            ?? request()->user()?->id;

        ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'loggable_type' => Task::class,
            'loggable_id' => $task->id,
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
