<?php

namespace App\Modules\Projects\Services;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Scopes\UserActivityLogScope;
use App\Modules\Shared\Services\ActivityLogOrganizationResolver;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProjectActivityService
{
    /**
     * اشتقاق organization_id من الـ Project للـ ActivityLog (دفاع-متعمّق + توثيق).
     */
    private function projectOrgId(int $projectId): ?int
    {
        return app(ActivityLogOrganizationResolver::class)
            ->resolveForLoggable(Project::class, $projectId);
    }

    /**
     * ترجمة أسماء الحقول
     */
    protected array $fieldLabels = [
        // حقول المشروع
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
        // حقول المهام
        'title' => 'عنوان المهمة',
        'assigned_to' => 'المكلف',
        'due_date' => 'تاريخ الاستحقاق',
        'estimated_hours' => 'الساعات المقدرة',
        'actual_hours' => 'الساعات الفعلية',
        'milestone_id' => 'المرحلة',
        'parent_id' => 'المهمة الأم',
        'completed_date' => 'تاريخ الإنجاز',
        'completed_at' => 'تاريخ الإنجاز',
        'started_at' => 'تاريخ البدء',
        'order' => 'الترتيب',
        'notes' => 'ملاحظات',
        'tags' => 'الوسوم',
        'is_recurring' => 'متكررة',
        'recurrence_pattern' => 'نمط التكرار',
    ];

    /**
     * ترجمة قيم الحالة
     */
    protected array $statusLabels = [
        // حالات المشروع
        'draft' => 'مسودة',
        'planning' => 'تخطيط',
        'in_progress' => 'قيد التنفيذ',
        'on_hold' => 'معلق',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى',
        // حالات المهام
        'pending' => 'للتنفيذ',
        'in_review' => 'قيد المراجعة',
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
     * جلب سجل نشاطات المشروع
     */
    public function getActivityLog(Project $project, Request $request): LengthAwarePaginator
    {
        // جلب IDs المهام التابعة للمشروع
        $taskIds = $project->tasks()->pluck('id')->toArray();

        $query = ActivityLog::where(function ($q) use ($project, $taskIds) {
            // سجلات المشروع
            $q->where(function ($sq) use ($project) {
                $sq->where('loggable_type', Project::class)
                    ->where('loggable_id', $project->id);
            })
            // سجلات المهام التابعة للمشروع
                ->orWhere(function ($sq) use ($taskIds) {
                    $sq->where('loggable_type', Task::class)
                        ->whereIn('loggable_id', $taskIds);
                });
        })
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc');

        // عزل المؤسسة عبر الفلتر الموحّد (organization_id مباشرة).
        // يتحقّق ProjectPolicy::view في Controller، والفلتر هنا defense-in-depth.
        $actor = $request->user();
        if ($actor instanceof User) {
            app(UserActivityLogScope::class)->apply($query, $actor);
        }

        // تصفية بنوع النشاط
        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        // تصفية بالتاريخ
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $logs = $query->paginate(min((int) $request->get('per_page', 20), 100));

        // تحميل مسبق للمهام المتعلقة بالصفحة الحالية (تجنب N+1)
        $taskLogsIds = $logs->getCollection()
            ->where('loggable_type', Task::class)
            ->pluck('loggable_id')
            ->unique()
            ->values()
            ->toArray();

        $tasksMap = collect();
        if (! empty($taskLogsIds)) {
            $tasksMap = Task::withTrashed()
                ->whereIn('id', $taskLogsIds)
                ->pluck('title', 'id');
        }

        // تحميل مسبق للمستخدمين من قيم التغييرات (تجنب N+1 في formatValue)
        $userIdsFromChanges = $logs->getCollection()->flatMap(function ($log) {
            if ($log->action !== 'updated' || ! $log->new_values) {
                return [];
            }

            return collect($log->new_values)
                ->filter(fn ($v, $k) => $k === 'assigned_to' && $v)
                ->values();
        })->merge(
            $logs->getCollection()->flatMap(function ($log) {
                if ($log->action !== 'updated' || ! $log->old_values) {
                    return [];
                }

                return collect($log->old_values)
                    ->filter(fn ($v, $k) => $k === 'assigned_to' && $v)
                    ->values();
            })
        )->unique()->filter()->values()->toArray();

        $usersMap = collect();
        if (! empty($userIdsFromChanges)) {
            $usersMap = User::whereIn('id', $userIdsFromChanges)
                ->pluck('name', 'id');
        }

        // إضافة ترجمة الحقول والقيم
        $logs->getCollection()->transform(function ($log) use ($tasksMap, $usersMap) {
            return $this->formatActivityLog($log, $tasksMap, $usersMap);
        });

        return $logs;
    }

    /**
     * تنسيق سجل النشاط للعرض
     *
     * @param  Collection  $tasksMap  map من id => title للمهام (محمّل مسبقاً)
     * @param  Collection  $usersMap  map من id => name للمستخدمين (محمّل مسبقاً)
     */
    public function formatActivityLog(ActivityLog $log, $tasksMap = null, $usersMap = null): array
    {
        $isTask = $log->loggable_type === Task::class;

        $actionLabels = $this->getActionLabels($isTask);

        // جلب اسم المهمة من الـ map المحمّل مسبقاً (بدون N+1)
        $taskTitle = null;
        if ($isTask) {
            $taskTitle = $tasksMap?->get($log->loggable_id)
                ?? $log->new_values['title']
                ?? $log->old_values['title']
                ?? 'مهمة محذوفة';
        }

        // بناء قائمة التغييرات المفصلة
        $changes = $this->buildChanges($log, $usersMap);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => $actionLabels[$log->action] ?? $log->action,
            'loggable_type' => $isTask ? 'task' : 'project',
            'loggable_type_label' => $isTask ? 'مهمة' : 'مشروع',
            'task_title' => $taskTitle,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'changes' => $changes,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at,
            'created_at_formatted' => $log->created_at->format('Y-m-d H:i'),
            'created_at_human' => $log->created_at->diffForHumans(),
        ];
    }

    /**
     * الحصول على ترجمات الإجراءات
     */
    protected function getActionLabels(bool $isTask): array
    {
        if ($isTask) {
            return [
                'created' => 'إنشاء مهمة',
                'updated' => 'تحديث مهمة',
                'deleted' => 'حذف مهمة',
                'restored' => 'استعادة مهمة',
                'subtask_created' => 'إضافة مهمة فرعية',
                'subtask_updated' => 'تحديث مهمة فرعية',
                'subtask_deleted' => 'حذف مهمة فرعية',
                'status_changed' => 'تغيير الحالة',
                'assigned' => 'تعيين مسؤول',
                'unassigned' => 'إلغاء تعيين مسؤول',
                'comment_added' => 'إضافة تعليق',
                'attachment_added' => 'إضافة مرفق',
                'attachment_deleted' => 'حذف مرفق',
            ];
        }

        return [
            'created' => 'إنشاء المشروع',
            'updated' => 'تحديث المشروع',
            'deleted' => 'حذف المشروع',
            'restored' => 'استعادة المشروع',
            'member_added' => 'إضافة عضو للفريق',
            'member_removed' => 'إزالة عضو من الفريق',
            'member_updated' => 'Member role updated',
            'expense_added' => 'إضافة مصروف',
            'expense_updated' => 'تحديث مصروف',
            'expense_deleted' => 'حذف مصروف',
            'risk_added' => 'إضافة خطر',
            'risk_updated' => 'تحديث خطر',
            'risk_deleted' => 'حذف خطر',
            'stakeholder_added' => 'إضافة صاحب مصلحة',
            'stakeholder_updated' => 'تحديث صاحب مصلحة',
            'stakeholder_deleted' => 'حذف صاحب مصلحة',
            'kpi_added' => 'إضافة مؤشر أداء',
            'kpi_updated' => 'تحديث مؤشر أداء',
            'kpi_deleted' => 'حذف مؤشر أداء',
            'comment_added' => 'إضافة تعليق',
            'comment_deleted' => 'حذف تعليق',
            'attachment_added' => 'إضافة مرفق',
            'attachment_deleted' => 'حذف مرفق',
        ];
    }

    /**
     * بناء قائمة التغييرات المفصلة
     *
     * @param  Collection|null  $usersMap  map من id => name (محمّل مسبقاً)
     */
    protected function buildChanges(ActivityLog $log, $usersMap = null): array
    {
        $changes = [];

        if ($log->action !== 'updated' || ! $log->old_values || ! $log->new_values) {
            return $changes;
        }

        foreach ($log->new_values as $field => $newValue) {
            $oldValue = $log->old_values[$field] ?? null;

            $displayOld = $this->formatValue($field, $oldValue, $usersMap);
            $displayNew = $this->formatValue($field, $newValue, $usersMap);

            $changes[] = [
                'field' => $field,
                'field_label' => $this->fieldLabels[$field] ?? $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'display_old' => $displayOld ?? 'غير محدد',
                'display_new' => $displayNew ?? 'غير محدد',
            ];
        }

        return $changes;
    }

    /**
     * تنسيق القيمة للعرض
     *
     * @param  Collection|null  $usersMap  map من id => name (محمّل مسبقاً لتجنب N+1)
     */
    protected function formatValue(string $field, $value, $usersMap = null): string
    {
        if ($field === 'status') {
            return $this->statusLabels[$value] ?? $value;
        }

        if ($field === 'priority') {
            return $this->priorityLabels[$value] ?? $value;
        }

        if ($field === 'progress') {
            return ($value ?? 0).'%';
        }

        if ($field === 'assigned_to') {
            if (! $value) {
                return 'غير محدد';
            }

            // استخدام الـ map المحمّل مسبقاً (بدون query إضافية)
            if ($usersMap) {
                return $usersMap->get($value) ?? 'غير محدد';
            }

            // fallback: query مباشرة (عند الاستدعاء المنفرد)
            return User::find($value)?->name ?? 'غير محدد';
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return (string) ($value ?? 'غير محدد');
    }

    /**
     * تسجيل نشاط إضافة عضو
     */
    public function logMemberAdded(Project $project, User $member, User $actor, string $role, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'member_added',
            'description' => "تم إضافة {$member->name} كعضو في الفريق",
            'new_values' => [
                'member_id' => $member->id,
                'member_name' => $member->name,
                'role' => $role,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط إزالة عضو
     */
    public function logMemberRemoved(Project $project, string $memberName, string $memberId, User $actor, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'member_removed',
            'description' => "تم إزالة {$memberName} من الفريق",
            'old_values' => [
                'member_id' => $memberId,
                'member_name' => $memberName,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * Record a member role update event.
     */
    public function logMemberUpdated(Project $project, User $member, User $actor, string $newRole, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'member_updated',
            'description' => "Member role updated for {$member->name}",
            'new_values' => [
                'member_id' => $member->id,
                'member_name' => $member->name,
                'role' => $newRole,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط إضافة صاحب مصلحة
     */
    public function logStakeholderAdded(Project $project, $stakeholder, User $actor, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'stakeholder_added',
            'description' => "تم إضافة صاحب مصلحة: {$stakeholder->name}",
            'new_values' => [
                'stakeholder_id' => $stakeholder->id,
                'name' => $stakeholder->name,
                'role' => $stakeholder->role,
                'organization' => $stakeholder->organization ?? null,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط حذف صاحب مصلحة
     */
    public function logStakeholderDeleted(Project $project, string $name, ?string $role, string $id, User $actor, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'stakeholder_deleted',
            'description' => "تم حذف صاحب مصلحة: {$name}",
            'old_values' => [
                'stakeholder_id' => $id,
                'name' => $name,
                'role' => $role,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط إضافة مؤشر أداء
     */
    public function logKPIAdded(Project $project, $kpi, User $actor, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'kpi_added',
            'description' => "تم إضافة مؤشر أداء: {$kpi->indicator}",
            'new_values' => [
                'kpi_id' => $kpi->id,
                'indicator' => $kpi->indicator,
                'target' => $kpi->target,
                'current_value' => $kpi->current_value,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط تحديث مؤشر أداء
     */
    public function logKPIUpdated(Project $project, $kpi, array $oldValues, User $actor, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'kpi_updated',
            'description' => "تم تحديث مؤشر أداء: {$kpi->indicator}",
            'old_values' => $oldValues,
            'new_values' => $kpi->toArray(),
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط حذف مؤشر أداء
     */
    public function logKPIDeleted(Project $project, string $indicator, ?array $kpiData, User $actor, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'kpi_deleted',
            'description' => "تم حذف مؤشر أداء: {$indicator}",
            'old_values' => $kpiData,
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط إضافة خطر
     */
    public function logRiskAdded(Project $project, $risk, User $actor, ?string $ip = null): void
    {
        $description = mb_substr($risk->risk, 0, 50).(mb_strlen($risk->risk) > 50 ? '...' : '');

        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'risk_added',
            'description' => "تم إضافة خطر: {$description}",
            'new_values' => [
                'risk_id' => $risk->id,
                'risk' => $risk->risk,
                'probability' => $risk->probability,
                'impact' => $risk->impact,
                'status' => $risk->status,
            ],
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط تحديث خطر
     */
    public function logRiskUpdated(Project $project, $risk, array $oldValues, User $actor, ?string $ip = null): void
    {
        $description = mb_substr($risk->risk, 0, 50).(mb_strlen($risk->risk) > 50 ? '...' : '');

        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'risk_updated',
            'description' => "تم تحديث خطر: {$description}",
            'old_values' => $oldValues,
            'new_values' => $risk->toArray(),
            'ip_address' => $ip,
        ]);
    }

    /**
     * تسجيل نشاط حذف خطر
     */
    public function logRiskDeleted(Project $project, string $description, ?array $riskData, User $actor, ?string $ip = null): void
    {
        ActivityLog::create([
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'organization_id' => $this->projectOrgId($project->id),
            'user_id' => $actor->id,
            'action' => 'risk_deleted',
            'description' => "تم حذف خطر: {$description}",
            'old_values' => $riskData,
            'ip_address' => $ip,
        ]);
    }
}
