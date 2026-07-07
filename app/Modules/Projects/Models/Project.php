<?php

namespace App\Modules\Projects\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\OwnerEditable;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Strategy\Models\Program;
use App\Modules\Strategy\Models\Review;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model implements OwnerEditable, ScopeAware
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (empty($project->code)) {
                $project->code = static::generateCode();
            }
        });
    }

    /**
     * توليد رمز تسلسلي للمشروع
     * الصيغة: PRJ-YYYY-XXXX (مثال: PRJ-2025-0001)
     */
    public static function generateCode(): string
    {
        $year = date('Y');
        $prefix = "PRJ-{$year}-";

        // البحث عن آخر رقم في هذه السنة
        $lastProject = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(code FROM LENGTH(code) - 3) AS INTEGER) DESC')
            ->first();

        if ($lastProject && preg_match('/(\d{4})$/', $lastProject->code, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'name',
        'code',
        'description',
        'objectives',
        'in_scope',
        'out_of_scope',
        'organization_id',
        'department_id',
        'program_id',
        'created_by',
        'status',
        'priority',
        'start_date',
        'end_date',
        'actual_start_date',
        'actual_end_date',
        'progress',
        'budget',
        'spent_amount',
        'actual_cost',
        'human_resources',
        'technical_resources',
        'financial_resources',
        // حقول المنهجية
        'type',
        'triage_answers',
        // حقول المشروع الجديد (PMBOK)
        'business_case',
        'success_criteria',
        'requirements',
        'manager_authority',
        'approval_criteria',
        'exit_criteria',
        // حقول المشروع التحسيني (FOCUS-PDCA)
        'problem_statement',
        'target_process',
        'root_cause',
        'expected_benefits',
        'current_pdca_phase',
        // حقول الإغلاق
        'lessons_learned',
        'outcome_summary',
        'sustainability_plan',
        'achievement_percentage',
        'achievement_status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
        'progress' => 'decimal:2',
        'budget' => 'decimal:2',
        'spent_amount' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'objectives' => 'array',
        'in_scope' => 'array',
        'out_of_scope' => 'array',
        // حقول المنهجية (JSON → array)
        'triage_answers' => 'array',
        'success_criteria' => 'array',
        'requirements' => 'array',
        'manager_authority' => 'array',
        'expected_benefits' => 'array',
        'achievement_percentage' => 'decimal:2',
    ];

    // القسم/الوحدة التابع لها المشروع
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // المبادرة/البرنامج (Program)
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    // مدير المشروع — يُمثَّل الآن كدور سياقي (scoped role) لا كعمود
    // accessor يرجع أول مستخدم له دور manager في هذا المشروع (User أو null)
    public function getManagerAttribute(): ?User
    {
        return $this->members()
            ->wherePivot('role', ScopedRole::PROJECT_MANAGER)
            ->select('users.*')
            ->first();
    }

    // منشئ المشروع
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // مؤشرات الأداء (نظام Performance عبر kpi_links)
    public function kpis(): MorphToMany
    {
        return $this->morphToMany(
            Kpi::class,
            'linkable',
            'kpi_links',
            'linkable_id',
            'kpi_id'
        )->withPivot(['relationship_type', 'weight'])->withTimestamps();
    }

    // المخاطر
    public function risks(): HasMany
    {
        return $this->hasMany(ProjectRisk::class)->orderBy('order');
    }

    // المراحل
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('order');
    }

    // المهام
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    // أصحاب المصلحة
    public function stakeholders(): HasMany
    {
        return $this->hasMany(Stakeholder::class);
    }

    // أعضاء الفريق
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'model_has_scoped_roles',
            'scope_id',
            'user_id'
        )
            ->wherePivot('scope_type', ScopedRole::SCOPE_PROJECT)
            ->withPivot('role', 'expires_at')
            ->withTimestamps();
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

    // المصروفات
    public function expenses(): HasMany
    {
        return $this->hasMany(ProjectExpense::class)->orderBy('expense_date', 'desc');
    }

    // سجل النشاطات
    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'loggable');
    }

    // مراجعات المشروع (دورية أو طارئة)
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    // قرارات/توصيات المشروع (تغيير نطاق، ميزانية، تصعيد، إلخ).
    // Direction B: rulings live on Recommendation via `kind=ruling`; the
    // polymorphic `decidable_type`/`decidable_id` parent pointer is preserved
    // so engine-side scope resolution keeps working.
    public function recommendations(): MorphMany
    {
        return $this->morphMany(Recommendation::class, 'decidable');
    }

    // الأدوار السياقية المرتبطة بهذا المشروع
    public function scopedRoles(): HasMany
    {
        return $this->hasMany(ScopedRole::class, 'scope_id')
            ->where('scope_type', ScopedRole::SCOPE_PROJECT);
    }

    // حساب نسبة الإنجاز تلقائياً من المهام (تُستبعد المهام الملغاة)
    public function calculateProgress(): float
    {
        $tasks = $this->tasks()
            ->whereNull('parent_id')
            ->where('status', '!=', TaskStatus::CANCELLED->value)
            ->get();

        if ($tasks->isEmpty()) {
            return 0;
        }

        return $tasks->avg('progress') ?? 0;
    }

    /**
     * حساب نسبة الإنجاز من المهام المحملة مسبقاً (بدون query إضافية)
     * استخدم هذه الطريقة عند تكرار مجموعة من المشاريع لتجنب N+1
     *
     * مثال:
     *   $projects = Project::with(['tasks' => fn($q) => $q->whereNull('parent_id')])->get();
     *   $projects->each(fn($p) => $p->calculateProgressFromLoaded());
     */
    public function calculateProgressFromLoaded(): float
    {
        $cancelled = TaskStatus::CANCELLED;
        $tasks = $this->relationLoaded('tasks')
            ? $this->tasks
                ->whereNull('parent_id')
                ->filter(fn ($t) => ($t->status instanceof TaskStatus ? $t->status : TaskStatus::tryFrom((string) $t->status)) !== $cancelled)
            : $this->tasks()->whereNull('parent_id')->where('status', '!=', $cancelled->value)->get();

        if ($tasks->isEmpty()) {
            return 0;
        }

        return $tasks->avg('progress') ?? 0;
    }

    // تحديث نسبة الإنجاز
    public function updateProgress(): void
    {
        // A completed project's progress is pinned to 100 by ProjectObserver::updating().
        // Skip recomputation while completed so a subsequent task edit (which fires
        // TaskObserver → updateProgress) cannot overwrite that forced 100. The guard
        // lifts automatically once the project leaves 'completed' (reopen sets the
        // status to in_progress), resuming normal recalculation from the tasks.
        //
        // We read the persisted status (not the in-memory attribute) because the
        // caller is usually a stale `$task->project` relation hydrated before the
        // project was completed; trusting the cached attribute would miss the pin.
        $persistedStatus = static::withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->value('status');

        if (($persistedStatus ?? $this->status) === 'completed') {
            return;
        }

        $this->update(['progress' => $this->calculateProgress()]);
    }

    // ========== OwnerEditable ==========

    /**
     * The owner may edit while the project is not completed/cancelled.
     * Other abilities (delete/manage/close) are never granted via the owner floor.
     */
    public function isOwnerEditable(): bool
    {
        return ! in_array($this->status, ['completed', 'cancelled'], true);
    }

    // ========== ScopeAware ==========

    public function scopeParent(): ?Model
    {
        // The department is the scope parent. Resolved through the engine (cached
        // by id) so the same department is not re-fetched for every project in a
        // list (N+1 fix). If the department relation is eager-loaded with the full
        // set of scope-chain columns we reuse it without a query; a partial
        // (id,name) load falls through to the engine to fetch the full row.
        if ($this->department_id !== null) {
            if (AccessDecision::scopeParentFullyLoaded($this, 'department')) {
                return $this->getRelation('department');
            }

            return AccessDecision::resolveScopeParent(Department::class, (int) $this->department_id);
        }

        return null;
    }

    public function scopeTypeKey(): string
    {
        return 'project';
    }

    public function scopeOrganizationId(): ?int
    {
        if ($this->organization_id !== null) {
            return (int) $this->organization_id;
        }

        // Fallback: derive the org from the department. Routed through the engine
        // cache (by id) so it reuses any department already hydrated for the scope
        // chain instead of issuing its own per-record fetch.
        $department = $this->department_id !== null
            ? AccessDecision::resolveScopeParent(Department::class, (int) $this->department_id)
            : null;

        return $department?->organization_id ? (int) $department->organization_id : null;
    }
}
