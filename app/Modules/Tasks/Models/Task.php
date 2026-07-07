<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\OwnerEditable;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model implements OwnerEditable, ScopeAware
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }

    protected $fillable = [
        // الحقول الأساسية
        'type',
        'title',
        'description',
        'status',
        'priority',
        'progress',
        'order',

        // التواريخ
        'start_date',
        'due_date',
        'completed_date',

        // حقول الإكمال
        'challenges',
        'lessons_learned',
        'status_comment',

        // الوقت المقدر والفعلي
        'estimated_hours',
        'actual_hours',

        // العلاقات
        'project_id',
        'milestone_id',
        'department_id',
        'parent_id',
        'assigned_to',
        'created_by',
        'owner_id',

        // خصائص إضافية
        'is_private',
        'recurrence_rule',
        'recurring_parent_id',
        'next_occurrence',

        // Phase 4 polymorphic source (AuthZ unification): the task can be
        // attached to any ScopeAware parent (Project, Department, Risk,
        // IncidentReport, Recommendation, Kpi, Milestone). The
        // migration 2026_07_05_171421_add_source_fields_to_tasks_table
        // backfills project_id / department_id rows so existing data keeps
        // behaving exactly the same.
        'source_type',
        'source_id',
        'source_sensitivity',
    ];

    protected $casts = [
        'type' => TaskType::class,
        'status' => TaskStatus::class,
        'priority' => TaskPriority::class,
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_date' => 'date',
        'next_occurrence' => 'date',
        'is_private' => 'boolean',
        'progress' => 'integer',
        'estimated_hours' => 'integer',
        'actual_hours' => 'integer',
    ];

    protected $appends = ['time_indicator', 'days_remaining', 'days_elapsed', 'total_days', 'time_progress'];

    // ========== العلاقات ==========
    // ملاحظة: تم نقل boot events إلى TaskObserver

    // المشروع (للمهام من نوع project)
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // القسم (للمهام من نوع department)
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // المرحلة
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    // المهمة الأم
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    // المهام الفرعية
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    // صاحب المهمة (للمهام الشخصية)
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // المستخدم المكلف
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // منشئ المهمة
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // المهمة المتكررة الأصلية
    public function recurringParent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'recurring_parent_id');
    }

    // المهام المتكررة المولدة
    public function recurringChildren(): HasMany
    {
        return $this->hasMany(Task::class, 'recurring_parent_id');
    }

    // التعليقات
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    // المرفقات
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // سجل النشاطات
    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'loggable');
    }

    // المصروفات المرتبطة بهذه المهمة
    public function expenses(): HasMany
    {
        return $this->hasMany(ProjectExpense::class);
    }

    // ========== Scopes ==========

    // مهام المشاريع
    public function scopeProjectTasks(Builder $query): Builder
    {
        return $query->where('type', TaskType::PROJECT);
    }

    // المهام الشخصية
    public function scopePersonalTasks(Builder $query): Builder
    {
        return $query->where('type', TaskType::PERSONAL);
    }

    // مهام الأقسام
    public function scopeDepartmentTasks(Builder $query): Builder
    {
        return $query->where('type', TaskType::DEPARTMENT);
    }

    // المهام المتكررة
    public function scopeRecurringTasks(Builder $query): Builder
    {
        return $query->where('type', TaskType::RECURRING);
    }

    // المهام الخاصة بمستخدم (شخصية أو مكلف بها)
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('owner_id', $userId)
                ->orWhere('assigned_to', $userId)
                ->orWhere('created_by', $userId);
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $orgId = $user->organization_id;
        $deptId = $user->department_id;
        $isAdmin = $user->isAdmin();
        $grantsOrgView = AccessDecision::grantsAtOrganization($user, Capability::TASKS_VIEW);
        $managedDeptIds = $user->getManagedDepartmentIds();
        $projectIdsWithRole = $user->getProjectsWithRoles()->pluck('id')->all();

        return $query->where(function (Builder $outer) use (
            $user,
            $orgId,
            $deptId,
            $isAdmin,
            $grantsOrgView,
            $managedDeptIds,
            $projectIdsWithRole
        ) {
            // Branch (1) — personal task floor (always permitted; user's own org
            // context not required for own personal tasks).
            $outer->where(function (Builder $q) use ($user) {
                $q->where('type', TaskType::PERSONAL->value)
                    ->where('owner_id', $user->id);
            });

            if ($orgId === null) {
                return;
            }

            // Branch (2) — legacy project/dept path. Applies to tasks WITHOUT
            // a polymorphic source (source_type null) AND to tasks whose
            // source_type is the auto-stamped "Project" / "Department"
            // sentinel that TaskObserver::stampLegacySourceIfAbsent writes
            // for new Eloquent-created rows. For tasks with a sensitive
            // source (OVR confidential, MeetingResolution, etc.), branch
            // (3) is the authoritative path — otherwise a confidential
            // OVR task would leak to any same-department user via the
            // department_id match below.
            $outer->orWhere(function (Builder $b) use (
                $user,
                $orgId,
                $deptId,
                $isAdmin,
                $grantsOrgView,
                $managedDeptIds,
                $projectIdsWithRole
            ) {
                $b->where(function (Builder $legacy) {
                    $legacy->whereNull('source_type')
                        ->orWhereIn('source_type', ['Project', 'Department']);
                })
                    ->where('type', '!=', TaskType::PERSONAL->value)
                    ->where(function (Builder $o) use ($orgId) {
                        $o->whereHas('project', fn (Builder $p) => $p->where('organization_id', $orgId))
                            ->orWhereHas('department', fn (Builder $d) => $d->where('organization_id', $orgId));
                    });

                if ($grantsOrgView && ! $isAdmin) {
                    return;
                }

                if ($grantsOrgView && $isAdmin) {
                    $b->where(function (Builder $r) use ($managedDeptIds, $deptId) {
                        $deptSet = array_values(array_unique(array_filter(array_merge($managedDeptIds, [$deptId]))));

                        $r->where(function (Builder $dq) use ($deptSet) {
                            if (empty($deptSet)) {
                                $dq->whereRaw('1 = 0');

                                return;
                            }

                            $dq->whereIn('department_id', $deptSet)
                                ->orWhereHas('project', fn (Builder $p) => $p->whereIn('department_id', $deptSet));
                        })->orWhere(function (Builder $n) {
                            $n->whereNull('department_id')
                                ->whereDoesntHave('project', fn (Builder $p) => $p->whereNotNull('department_id'));
                        });
                    });

                    return;
                }

                $b->where(function (Builder $r) use ($user, $projectIdsWithRole, $deptId) {
                    $r->where('assigned_to', $user->id)
                        ->orWhere('created_by', $user->id)
                        ->orWhere('owner_id', $user->id);

                    if (! empty($projectIdsWithRole)) {
                        $r->orWhereIn('project_id', $projectIdsWithRole);
                    }

                    if ($deptId !== null) {
                        $r->orWhere('department_id', $deptId)
                            ->orWhereHas('project', fn (Builder $p) => $p->where('department_id', $deptId));
                    }
                });
            });

            // Branch (3) — source-aware path. For tasks with a polymorphic
            // source_type/source_id, the source's own visibility rules
            // apply (delegated via source-specific predicates). The
            // OVR-confidential leak fix lives here: a task sourced from a
            // confidential IncidentReport is hidden from any user who
            // does not hold the OVR_CONFIDENTIAL capability, even if
            // they share the incident's department subtree.
            $outer->orWhere(function (Builder $b) use ($user, $orgId) {
                $this->sourceAwareScope($b, $user, $orgId);
            });
        });
    }

    /**
     * Branch (3) — source-aware visibility filter. Applies only to
     * tasks with a non-null source_type/source_id pair. Each
     * source_type gets a dedicated predicate; the OVR IncidentReport
     * predicate is the security-critical one (closes the
     * source_sensitivity leak path).
     */
    private function sourceAwareScope(Builder $b, User $user, ?int $orgId): Builder
    {
        $b->whereNotNull('tasks.source_type')
            ->whereNotNull('tasks.source_id');

        $userMayViewConfidential = $this->userMayViewConfidential($user);

        $b->where(function (Builder $byType) use ($orgId, $userMayViewConfidential) {
            // OVR IncidentReport — apply the confidential-sensitivity gate.
            // The list endpoint gates on the (source_type, source_sensitivity)
            // pair on the task row itself; per-record engine checks via
            // IncidentReport::scopeVisibleTo further narrow at the
            // show/policy layer. Accepts both legacy FQN tokens and the
            // kebab form for forward-compat.
            $byType->orWhere(function (Builder $i) use ($orgId, $userMayViewConfidential) {
                $i->whereIn('tasks.source_type', ['IncidentReport', 'incident_report', IncidentReport::class])
                    ->where('tasks.organization_id', $orgId);

                // Sensitivity gate — only filters when the user lacks
                // the OVR_CONFIDENTIAL capability. Users with the cap
                // fall through to the normal source-row visibility.
                if (! $userMayViewConfidential) {
                    $i->where(function (Builder $s) {
                        $s->whereNull('tasks.source_sensitivity')
                            ->orWhere('tasks.source_sensitivity', '!=', 'confidential');
                    });
                }
            });

            // Recommendation / MeetingResolution / Risk / Kpi / Milestone —
            // org isolation only (no sensitivity variant for these in the
            // current scope; their source's own scopeVisibleTo narrows further
            // via the engine at the per-record layer).
            $byType->orWhere(function (Builder $r) use ($orgId) {
                $r->whereIn('tasks.source_type', ['Recommendation', 'recommendation', Recommendation::class])
                    ->where('tasks.organization_id', $orgId);
            });

            // Phase 3 / Direction R: tasks spawned by a meeting resolution
            // carry `source_type = 'MeetingResolution'`. Their org is stamped
            // at create time from the resolution's organization_id; this
            // predicate isolates them by org only (the resolution's own
            // scopeVisibleTo narrows further at the per-record layer).
            $byType->orWhere(function (Builder $r) use ($orgId) {
                $r->whereIn('tasks.source_type', [
                    'MeetingResolution',
                    'meeting_resolution',
                    MeetingResolution::class,
                ])->where('tasks.organization_id', $orgId);
            });

            foreach ([
                ['Risk', Risk::class],
                ['Kpi', Kpi::class],
                ['Milestone', Milestone::class],
            ] as [$token, $class]) {
                $byType->orWhere(function (Builder $t) use ($token, $class, $orgId) {
                    $t->whereIn('tasks.source_type', [$token, strtolower((new \ReflectionClass($class))->getShortName()), $class])
                        ->where('tasks.organization_id', $orgId);
                });
            }
        });

        return $b;
    }

    /**
     * Does this user hold an explicit OVR confidential grant via any
     * active scoped role? Mirrors IncidentReport::userMayViewConfidential
     * so the SQL-side task filter agrees with the per-record gate — both
     * the list (here) and the show path (TaskPolicy::view) check the same
     * capability set, so a user who can view one confidential OVR row
     * can also see tasks attached to it.
     */
    private function userMayViewConfidential(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->activeScopedRoles()
            ->with('roleDefinition')
            ->get()
            ->contains(function ($scopedRole) {
                $def = $scopedRole->roleDefinition
                    ?? ScopedRoleDefinition::findByKey($scopedRole->scope_type, $scopedRole->role);

                return $def
                    && is_array($def->permissions ?? null)
                    && in_array(Capability::OVR_CONFIDENTIAL, $def->permissions, true);
            });
    }

    // المهام النشطة (غير مكتملة/ملغاة)
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', TaskStatus::activeStatuses());
    }

    // المهام المتأخرة
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', '!=', TaskStatus::COMPLETED)
            ->where('status', '!=', TaskStatus::CANCELLED)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now());
    }

    // المهام القادمة (خلال X أيام)
    public function scopeUpcoming(Builder $query, int $days = 7): Builder
    {
        return $query->whereIn('status', TaskStatus::activeStatuses())
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDays($days)]);
    }

    // المهام الرئيسية فقط (بدون الفرعية)
    public function scopeRootTasks(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    // حسب الأولوية
    public function scopeByPriority(Builder $query, string|TaskPriority $priority): Builder
    {
        $value = $priority instanceof TaskPriority ? $priority->value : $priority;

        return $query->where('priority', $value);
    }

    // حسب الحالة
    public function scopeByStatus(Builder $query, string|TaskStatus $status): Builder
    {
        $value = $status instanceof TaskStatus ? $status->value : $status;

        return $query->where('status', $value);
    }

    // ========== Helper Methods ==========

    public function isProjectTask(): bool
    {
        return $this->type === TaskType::PROJECT || $this->type?->value === 'project';
    }

    public function isPersonalTask(): bool
    {
        return $this->type === TaskType::PERSONAL || $this->type?->value === 'personal';
    }

    public function isDepartmentTask(): bool
    {
        return $this->type === TaskType::DEPARTMENT || $this->type?->value === 'department';
    }

    public function isRecurringTask(): bool
    {
        return $this->type === TaskType::RECURRING || $this->type?->value === 'recurring';
    }

    public function isOverdue(): bool
    {
        $status = $this->status instanceof TaskStatus ? $this->status : TaskStatus::tryFrom($this->status);

        return $status !== TaskStatus::COMPLETED
            && $status !== TaskStatus::CANCELLED
            && $this->due_date
            && $this->due_date->isPast();
    }

    public function isCompleted(): bool
    {
        $status = $this->status instanceof TaskStatus ? $this->status : TaskStatus::tryFrom($this->status);

        return $status === TaskStatus::COMPLETED;
    }

    public function hasIncompleteSubtasks(): bool
    {
        return $this->subtasks()
            ->whereNotIn('status', [TaskStatus::COMPLETED->value, TaskStatus::CANCELLED->value])
            ->exists();
    }

    public function canBeCompleted(): bool
    {
        return ! $this->hasIncompleteSubtasks();
    }

    // ========== Computed Attributes ==========

    public function getTotalDaysAttribute(): ?int
    {
        if (! $this->start_date || ! $this->due_date) {
            return null;
        }

        return $this->start_date->diffInDays($this->due_date);
    }

    public function getDaysElapsedAttribute(): ?int
    {
        if (! $this->start_date) {
            return null;
        }

        return $this->start_date->diffInDays(now(), false);
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return now()->diffInDays($this->due_date, false);
    }

    public function getTimeProgressAttribute(): ?float
    {
        if (! $this->start_date || ! $this->due_date) {
            return null;
        }

        $totalDays = $this->total_days;
        if ($totalDays <= 0) {
            return 100;
        }

        $elapsed = $this->days_elapsed;
        if ($elapsed <= 0) {
            return 0;
        }

        $progress = ($elapsed / $totalDays) * 100;

        return min(100, max(0, round($progress, 2)));
    }

    public function getTimeIndicatorAttribute(): array
    {
        $daysRemaining = $this->days_remaining;
        $daysElapsed = $this->days_elapsed;
        $totalDays = $this->total_days;
        $timeProgress = $this->time_progress;
        $hasDueDate = $this->due_date !== null;

        $status = 'normal';
        if ($this->isCompleted()) {
            $status = 'completed';
        } elseif ($daysRemaining !== null && $daysRemaining < 0) {
            $status = 'overdue';
        } elseif ($daysRemaining !== null && $daysRemaining <= 3) {
            $status = 'urgent';
        } elseif ($daysRemaining !== null && $daysRemaining <= 7) {
            $status = 'warning';
        }

        return [
            'days_remaining' => $daysRemaining,
            'days_elapsed' => $daysElapsed,
            'total_days' => $totalDays,
            'time_progress' => $timeProgress,
            'status' => $status,
            'has_due_date' => $hasDueDate,
        ];
    }

    // ========== Activity Logging ==========
    // تم نقل logActivity و logSubtaskActivity إلى TaskObserver

    // ========== OwnerEditable ==========

    /**
     * The owner may edit while the task is not completed.
     * Other abilities (delete/complete/assign) are never granted via the owner floor.
     */
    public function isOwnerEditable(): bool
    {
        $status = $this->status instanceof TaskStatus
            ? $this->status
            : TaskStatus::tryFrom((string) $this->status);

        return $status !== TaskStatus::COMPLETED;
    }

    // ========== ScopeAware ==========

    /**
     * Map of polymorphic source_type tokens to their concrete ScopeAware
     * model classes. The engine consults this map to resolve a task's
     * scope parent through source_type/source_id. Unknown tokens fall
     * through to project_id / department_id / personal floor.
     *
     * Adding a new sourceable parent = adding one entry here AND a row
     * in docs/authz/resource-authorization-graph.md.
     *
     * @var array<string, class-string<Model>>
     */
    private const SOURCE_CLASS_MAP = [
        'Project' => Project::class,
        'Department' => Department::class,
        'Risk' => Risk::class,
        'IncidentReport' => IncidentReport::class,
        'Recommendation' => Recommendation::class,
        'MeetingResolution' => MeetingResolution::class,
        'Kpi' => Kpi::class,
        'Milestone' => Milestone::class,
    ];

    public function scopeParent(): ?Model
    {
        // Phase 4 (AuthZ unification): the polymorphic source takes
        // priority over the legacy project_id / department_id fields. When
        // a task is attached to a Risk, IncidentReport, or
        // Recommendation, the engine walks THAT scope chain instead of
        // the project chain. Personal tasks (no source, no project, no
        // department) continue to ride the owner floor in TaskPolicy.
        if ($this->source_type !== null && $this->source_id !== null) {
            $sourceClass = self::SOURCE_CLASS_MAP[$this->source_type] ?? null;
            if ($sourceClass !== null) {
                $resolved = AccessDecision::resolveScopeParent($sourceClass, (int) $this->source_id);
                if ($resolved instanceof Model) {
                    return $resolved;
                }
                // source row missing (deleted/archived) — fall through to
                // the legacy chain rather than return null so the task
                // stays visible until ops resolves the dangling source.
            }
        }

        // Project task -> the project. Resolved through the engine (cached by id)
        // so a list of tasks sharing one project does not re-fetch it per row
        // (N+1 fix). A fully eager-loaded project relation is reused without a
        // query; a partial (column-projected) load falls through to the engine.
        if ($this->project_id !== null) {
            if (AccessDecision::scopeParentFullyLoaded($this, 'project')) {
                return $this->getRelation('project');
            }

            return AccessDecision::resolveScopeParent(Project::class, (int) $this->project_id);
        }

        // Department task -> the department. Same memoization path.
        if ($this->department_id !== null) {
            if (AccessDecision::scopeParentFullyLoaded($this, 'department')) {
                return $this->getRelation('department');
            }

            return AccessDecision::resolveScopeParent(Department::class, (int) $this->department_id);
        }

        // Personal task -> no parent.
        return null;
    }

    public function scopeTypeKey(): string
    {
        return 'task';
    }

    public function scopeOrganizationId(): ?int
    {
        // Derive from the project. Routed through the engine cache (by id) so it
        // reuses any project already hydrated for the scope chain instead of a
        // per-record fetch.
        if ($this->project_id !== null) {
            $project = AccessDecision::resolveScopeParent(Project::class, (int) $this->project_id);
            if ($project instanceof ScopeAware) {
                return $project->scopeOrganizationId();
            }
        }

        // Derive from the department (department task), same cache.
        if ($this->department_id !== null) {
            $department = AccessDecision::resolveScopeParent(Department::class, (int) $this->department_id);

            return $department?->organization_id ? (int) $department->organization_id : null;
        }

        return null;
    }
}
