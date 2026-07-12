<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\OwnerEditable;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
use App\Modules\Core\Models\Organization;
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

class Task extends Model implements OwnerEditable, ScopeAware, SensitivelyScoped
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
        // attached to a source (Project, Department, Risk, IncidentReport,
        // Recommendation, Kpi, Milestone). The
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
        $hasAdminCapability = AccessDecision::can($user, Capability::SETTINGS_MANAGE);
        $grantsOrgView = AccessDecision::grantsAtOrganization($user, Capability::TASKS_VIEW);
        $grantingScopes = AccessDecision::grantingScopes($user, Capability::TASKS_VIEW);
        $managedDeptIds = AccessDecision::subtreeDepartmentIds($grantingScopes['department'] ?? []);
        $projectIdsWithRole = $grantingScopes['project'] ?? [];

        // Phase CFA-08 — Cluster floor widening. When the actor holds BOTH
        // Capability::TASKS_VIEW + Capability::CLUSTER_TREE_VIEW on
        // actor.organization_id, the strict same-org floor widens to
        // include descendants via Organization::descendantIds(). Missing
        // either grant ⇒ strict same-org (preserves pre-CFA-08 behavior).
        // Personal task floor (branch 1) and any direct-relation predicates
        // remain UNCHANGED — they do not depend on the org-id list.
        $visibleOrgIds = ($orgId === null)
            ? []
            : $this->resolveClusterVisibleOrgIds($user, $orgId);

        // Phase 2A — detect the cluster widening case. The is_private
        // non-personal floor blocks private rows from widening through
        // cluster access (design §Phase 2). Same-org access (visibleOrgIds
        // == [orgId]) routes through the existing assignee / owner /
        // creator / role predicates instead, and the floor is NOT
        // applied there — a same-org assignee still sees a private
        // task they own.
        $clusterWidening = count($visibleOrgIds) > 1;

        // Top-level confidential filter (CFA-08 invariant 1, lifted out
        // of the branches closure so it ANDs across EVERY branch). A task
        // stamped source_sensitivity='confidential' is NEED-TO-KNOW
        // regardless of source_type — the per-row stamp is the
        // authoritative signal here. Users with the OVR_CONFIDENTIAL
        // capability fall through (cluster widening grants do NOT imply
        // OVR_CONFIDENTIAL — defense-in-depth).
        if (! $this->userMayViewConfidential($user)) {
            $query->where(function (Builder $c) {
                $c->whereNull('tasks.source_sensitivity')
                    ->orWhere('tasks.source_sensitivity', '!=', 'confidential');
            });
        }

        return $query->where(function (Builder $outer) use (
            $user,
            $orgId,
            $deptId,
            $hasAdminCapability,
            $grantsOrgView,
            $managedDeptIds,
            $projectIdsWithRole,
            $visibleOrgIds,
            $clusterWidening,
        ) {
            // Branch (1) — personal task floor (always permitted; user's own org
            // context not required for own personal tasks). NEVER widens.
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
            //
            // CFA-08: the project/department organization floor widens to
            // clusterVisibleOrgIds when the cluster rescue is admitted.
            // The dept-managed / project-role / direct-relation branches
            // below are unchanged (they always operated at strict same-org
            // for the actor).
            $outer->orWhere(function (Builder $b) use (
                $user,
                $deptId,
                $hasAdminCapability,
                $grantsOrgView,
                $managedDeptIds,
                $projectIdsWithRole,
                $visibleOrgIds,
                $clusterWidening,
            ) {
                $b->where(function (Builder $legacy) {
                    $legacy->whereNull('source_type')
                        ->orWhereIn('source_type', ['Project', 'Department']);
                })
                    ->where('type', '!=', TaskType::PERSONAL->value)
                    ->where(function (Builder $o) use ($visibleOrgIds) {
                        $o->whereHas('project', fn (Builder $p) => $p->whereIn('organization_id', $visibleOrgIds))
                            ->orWhereHas('department', fn (Builder $d) => $d->whereIn('organization_id', $visibleOrgIds));
                    });

                // Phase 2A — is_private non-personal floor for cluster
                // widening. Private rows are surfaced to the cluster
                // widening branches only when the actor is widening;
                // strict same-org access uses the assignee / owner /
                // creator / role predicates below and is NOT filtered
                // here (the predicates themselves shape visibility for
                // private tasks in the actor's own org).
                if ($clusterWidening) {
                    $b->where(function (Builder $priv) {
                        $priv->where('is_private', false);
                    });
                }

                if ($grantsOrgView && ! $hasAdminCapability) {
                    return;
                }

                if ($grantsOrgView && $hasAdminCapability) {
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
            //
            // CFA-08: the source's organization matching widens to the
            // cluster visible set when both grants are held. The
            // source-sensitivity filter remains scoped to the user's
            // OVR_CONFIDENTIAL entitlement — cluster widening grants do
            // NOT imply OVR_CONFIDENTIAL.
            $outer->orWhere(function (Builder $b) use ($user, $orgId, $visibleOrgIds, $clusterWidening) {
                $this->sourceAwareScope($b, $user, $orgId, $visibleOrgIds, $clusterWidening);
            });
        });
    }

    /**
     * Resolve the cluster_visible_org_ids list for the visible-to scope.
     *
     * Returns [actor.organization_id] when EITHER TASKS_VIEW or
     * CLUSTER_TREE_VIEW is missing on actor.org (strict same-org floor).
     * Returns actor.org + descendant ids when BOTH TASKS_VIEW +
     * CLUSTER_TREE_VIEW are held. Returns [] for null-org actors.
     *
     * Mirrors UserProjectScope::clusterVisibleOrgIds / CFA-04 exactly.
     *
     * @return list<int>
     */
    private function resolveClusterVisibleOrgIds(User $user, int $orgId): array
    {
        $visible = [$orgId];

        $hasTasksView = AccessDecision::can($user, Capability::TASKS_VIEW);
        $hasClusterTreeView = AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);
        if (! $hasTasksView || ! $hasClusterTreeView) {
            return $visible;
        }

        $org = Organization::query()->find($orgId);
        if (! $org instanceof Organization) {
            return $visible;
        }

        return array_values(array_unique(array_merge($visible, $org->descendantIds())));
    }

    /**
     * Branch (3) — source-aware visibility filter. Applies only to
     * tasks with a non-null source_type/source_id pair. Each
     * source_type gets a dedicated predicate; the OVR IncidentReport
     * predicate is the security-critical one (closes the
     * source_sensitivity leak path).
     *
     * CFA-08: when the cluster widening floor is admitted, source-row
     * organization matching widens to $visibleOrgIds. The
     * source-sensitivity gate (OVR confidential filter) remains scoped
     * to the user's OVR_CONFIDENTIAL entitlement — cluster widening
     * grants do NOT imply OVR_CONFIDENTIAL, so a cluster actor without
     * OVR_CONFIDENTIAL gets the same confidential exclusion a same-org
     * user without that capability already gets. Both are independently
     * correct (defense-in-depth via UserTaskScope::applyUnconditionalConfidentialFloor
     * for the SQL layer; this filter for the list layer's existing
     * pattern).
     *
     * Phase 2A — when cluster widening is in force, the is_private
     * floor also rides on the source-aware branches. Private
     * source-only tasks do not widen through cluster access (design
     * §Phase 2); the personal-task floor already keeps personal rows
     * owner-only so it is NOT re-applied here.
     *
     * @param  list<int>  $visibleOrgIds  cluster-resolved org list — [orgId] when
     *                                    cluster widening is not admitted,
     *                                    [orgId + descendants] when both grants are held.
     * @param  bool  $clusterWidening  whether visibleOrgIds actually widens
     *                                 (count > 1). Drives the is_private floor.
     */
    private function sourceAwareScope(Builder $b, User $user, ?int $orgId, array $visibleOrgIds = [], bool $clusterWidening = false): Builder
    {
        if ($visibleOrgIds === [] && $orgId !== null) {
            $visibleOrgIds = [$orgId];
        }

        $b->whereNotNull('tasks.source_type')
            ->whereNotNull('tasks.source_id');

        $userMayViewConfidential = $this->userMayViewConfidential($user);

        $b->where(function (Builder $byType) use ($visibleOrgIds, $userMayViewConfidential, $clusterWidening) {
            // OVR IncidentReport — apply the confidential-sensitivity gate.
            // The list endpoint gates on the (source_type, source_sensitivity)
            // pair on the task row itself; per-record engine checks via
            // IncidentReport::scopeVisibleTo further narrow at the
            // show/policy layer. Accepts both legacy FQN tokens and the
            // kebab form for forward-compat.
            $byType->orWhere(function (Builder $i) use ($visibleOrgIds, $userMayViewConfidential, $clusterWidening) {
                $i->whereIn('tasks.source_type', ['IncidentReport', 'incident_report', IncidentReport::class])
                    ->whereIn('tasks.organization_id', $visibleOrgIds);

                // Sensitivity gate — only filters when the user lacks
                // the OVR_CONFIDENTIAL capability. Users with the cap
                // fall through to the normal source-row visibility.
                if (! $userMayViewConfidential) {
                    $i->where(function (Builder $s) {
                        $s->whereNull('tasks.source_sensitivity')
                            ->orWhere('tasks.source_sensitivity', '!=', 'confidential');
                    });
                }

                // Phase 2A — is_private non-personal floor for cluster
                // widening. Same-org (non-cluster) source-only rows
                // route through the per-record engine + their source's
                // own scope; the floor only fires when the actor is
                // actually widening cross-org.
                if ($clusterWidening) {
                    $i->where(function (Builder $priv) {
                        $priv->where('tasks.is_private', false);
                    });
                }
            });

            // Recommendation / MeetingResolution / Risk / Kpi / Milestone —
            // org isolation only (no sensitivity variant for these in the
            // current scope; their source's own scopeVisibleTo narrows further
            // via the engine at the per-record layer).
            $byType->orWhere(function (Builder $r) use ($visibleOrgIds, $clusterWidening) {
                $r->whereIn('tasks.source_type', ['Recommendation', 'recommendation', Recommendation::class])
                    ->whereIn('tasks.organization_id', $visibleOrgIds);

                // Phase 2A — is_private floor for cluster widening.
                if ($clusterWidening) {
                    $r->where('tasks.is_private', false);
                }
            });

            // Phase 3 / Direction R: tasks spawned by a meeting resolution
            // carry `source_type = 'MeetingResolution'`. Their org is stamped
            // at create time from the resolution's organization_id; this
            // predicate isolates them by org only (the resolution's own
            // scopeVisibleTo narrows further at the per-record layer).
            $byType->orWhere(function (Builder $r) use ($visibleOrgIds, $clusterWidening) {
                $r->whereIn('tasks.source_type', [
                    'MeetingResolution',
                    'meeting_resolution',
                    MeetingResolution::class,
                ])->whereIn('tasks.organization_id', $visibleOrgIds);

                // Phase 2A — is_private floor for cluster widening.
                if ($clusterWidening) {
                    $r->where('tasks.is_private', false);
                }
            });

            foreach ([
                ['Risk', Risk::class],
                ['Kpi', Kpi::class],
                ['Milestone', Milestone::class],
            ] as [$token, $class]) {
                $byType->orWhere(function (Builder $t) use ($token, $class, $visibleOrgIds, $clusterWidening) {
                    $t->whereIn('tasks.source_type', [$token, strtolower((new \ReflectionClass($class))->getShortName()), $class])
                        ->whereIn('tasks.organization_id', $visibleOrgIds);

                    // Phase 2A — is_private floor for cluster widening.
                    if ($clusterWidening) {
                        $t->where('tasks.is_private', false);
                    }
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

        return AccessDecision::can($user, Capability::OVR_CONFIDENTIAL);
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
        // Phase 2A — direct tasks.organization_id is authoritative.
        //
        // Pre-Phase-2A, this method only inspected project_id /
        // department_id. That left source-only tasks (Recommendation /
        // MeetingResolution / Risk / Kpi / Milestone / OVR) returning
        // null even though their organization_id column was stamped at
        // create time. The cluster widening scopeVisibleTo() filter
        // already consulted tasks.organization_id directly, so the
        // per-record AuthZ decision (null ⇒ deny) contradicted the SQL
        // floor. Reading the column first closes that contradiction.
        if ($this->organization_id !== null) {
            return (int) $this->organization_id;
        }

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

    // ========== SensitivelyScoped (Phase CFA-08) ==========

    /**
     * A task is sensitive when EITHER:
     *   - its source carries the explicit source_sensitivity='confidential'
     *     stamp (CFA-08 invariant 1), OR
     *   - it is a private NON-PERSONAL task (Phase 2A — design §Phase 2:
     *     private non-personal tasks do not widen through cluster access
     *     unless an existing explicit need-to-know rule grants access).
     *
     * Authoritative task-side signal because tasks.source_id is bigint
     * while OVR incident IDs are UUIDs; Task cannot safely resolve an
     * OVR source row at runtime. The producer that creates or updates
     * an OVR-sourced task must maintain the copied stamp.
     *
     * Sensitivity is independent of the cluster rescue — a cluster actor
     * with TASKS_VIEW + CLUSTER_TREE_VIEW STILL gets a deny for sensitive
     * tasks (the engine's clusterTreeRescueApplies pre-flight checks
     * isSensitive and short-circuits to false; the scope applies the
     * unconditional filter at the SQL layer). This dual-layer enforcement
     * means a sensitive task leaks to a cluster actor on neither the
     * per-record nor the list path.
     *
     * Personal tasks (type = personal) are NEVER sensitive (their owner
     * floor is the only gate; nothing confidential to inherit). The
     * is_private column on a personal task is also irrelevant — the
     * owner floor is the only thing that ever gates a personal task
     * anyway, so `is_private=true` on a personal row is not a cluster
     * concern.
     */
    public function isSensitive(): bool
    {
        if ($this->isPersonalTask()) {
            return false;
        }

        // Phase 2A — private non-personal floor (design §Phase 2).
        if ($this->is_private === true) {
            return true;
        }

        return $this->source_sensitivity === 'confidential';
    }

    /**
     * Need-to-know access grant for a sensitive task.
     *
     * Mirrors TaskPolicy::isConfidentialSource + userMayViewConfidential —
     * a sensitive task is accessible to:
     *   - super_admin (handled by the engine before() short-circuit).
     *   - the task creator, owner, or assignee.
     *   - any user holding an active scoped role whose
     *     permissions[] contains Capability::OVR_CONFIDENTIAL — the
     *     same need-to-know capability OVR requires for the underlying
     *     IncidentReport.
     *
     * Cluster widening grants (CLUSTER_TREE_VIEW / MANAGE / EXPORT) do
     * NOT grant sensitive access. The cluster pair widens the org floor
     * only — Task::isSensitive() remains the FINAL gate.
     *
     * Returning false here means the engine treats the sensitive row as
     * denied to this user even if their scoped role carries
     * CLUSTER_TREE_VIEW or even TASKS_VIEW at the org level. This is
     * intentional: the engine's additive floor (sensitiveStructuralFloor
     * + hook) still applies separately, so a missed super_admin bypass
     * is caught by the belt; this hook returning false means the braces
     * caught it.
     */
    public function mayAccessSensitive(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Creator, owner, and assignee have task-level need-to-know access.
        if ((int) ($this->created_by ?? 0) === (int) $user->id
            || (int) ($this->owner_id ?? 0) === (int) $user->id
            || (int) ($this->assigned_to ?? 0) === (int) $user->id) {
            return true;
        }

        // Confidential-cleared scoped role — the only path through the
        // permission system that opens sensitive OVR-derived tasks.
        // Mirrors TaskPolicy::userMayViewConfidential and
        // IncidentReport::userHasOvrConfidentialCapability so the three
        // decision surfaces agree.
        return AccessDecision::can($user, Capability::OVR_CONFIDENTIAL, $this);
    }
}
