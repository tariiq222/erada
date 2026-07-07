<?php

namespace App\Modules\Strategy\Models;

use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Program Model (البرنامج / المبادرة)
 *
 * يمثل المستوى الأوسط في السلسلة الذهبية:
 * Portfolio -> Program -> Project
 *
 * ملاحظة: يُعرض للمستخدم النهائي باسم "المبادرة"
 */
class Program extends Model implements ScopeAware
{
    use HasFactory, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProgramFactory
    {
        return ProgramFactory::new();
    }

    protected $fillable = [
        'code',
        'name',
        'description',
        'portfolio_id',
        'department_id',
        'budget',
        'spent_amount',
        'start_date',
        'end_date',
        'progress',
        'weight',
        'status',
        'priority',
        'total_program_budget',
        'progress_calculation_method',
        'created_by',
        'order',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'decimal:2',
        'spent_amount' => 'decimal:2',
        'total_program_budget' => 'decimal:2',
        'progress' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLANNING = 'planning';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_PLANNING => 'تخطيط',
        self::STATUS_IN_PROGRESS => 'قيد التنفيذ',
        self::STATUS_ON_HOLD => 'معلق',
        self::STATUS_COMPLETED => 'مكتمل',
        self::STATUS_CANCELLED => 'ملغي',
    ];

    /**
     * Priority constants
     */
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public const PRIORITIES = [
        self::PRIORITY_LOW => 'منخفض',
        self::PRIORITY_MEDIUM => 'متوسط',
        self::PRIORITY_HIGH => 'عالي',
        self::PRIORITY_CRITICAL => 'حرج',
    ];

    /**
     * Progress calculation method constants
     */
    public const PROGRESS_WEIGHTED = 'weighted';

    public const PROGRESS_AVERAGE = 'average';

    public const PROGRESS_MANUAL = 'manual';

    public const PROGRESS_METHODS = [
        self::PROGRESS_WEIGHTED => 'موزون بالأوزان',
        self::PROGRESS_AVERAGE => 'متوسط المشاريع',
        self::PROGRESS_MANUAL => 'يدوي',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->code)) {
                $model->code = static::generateCode();
            }
        });
    }

    /**
     * Generate a unique code for the program.
     */
    public static function generateCode(): string
    {
        $year = date('Y');
        $prefix = "PRG-{$year}-";
        $last = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(code FROM LENGTH(code) - 2) AS INTEGER) DESC')
            ->first();

        $next = $last ? intval(substr($last->code, -3)) + 1 : 1;

        return $prefix.str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get the portfolio for this program.
     * (الالتزام التنفيذي)
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class, 'portfolio_id');
    }

    /**
     * Get the department for this program.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the projects for this program.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'program_id');
    }

    /**
     * Get the KPIs for this program.
     */
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

    /**
     * Get the blockers for this program.
     */
    public function blockers(): MorphMany
    {
        return $this->morphMany(Blocker::class, 'blockable');
    }

    /**
     * Get open blockers.
     */
    public function openBlockers(): MorphMany
    {
        return $this->blockers()->whereIn('status', [
            Blocker::STATUS_OPEN,
            Blocker::STATUS_IN_PROGRESS,
            Blocker::STATUS_ESCALATED,
        ]);
    }

    /**
     * Get the recommendations for this program.
     *
     * Direction B: the program-level rulings live on the unified
     * `recommendations` table with `kind=ruling`; the polymorphic parent
     * pointer stays on `decidable_type`/`decidable_id` so engine-side scope
     * resolution remains identical.
     */
    public function recommendations(): MorphMany
    {
        return $this->morphMany(Recommendation::class, 'decidable');
    }

    /**
     * Get the reviews for this program.
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /**
     * Get the creator of this program.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate progress from projects.
     */
    public function calculateProgress(): float
    {
        // If manual, return stored progress
        if ($this->progress_calculation_method === self::PROGRESS_MANUAL) {
            return $this->progress;
        }

        $projects = $this->projects()
            ->whereNotIn('status', ['cancelled'])
            ->get();

        if ($projects->isEmpty()) {
            return $this->progress;
        }

        if ($this->progress_calculation_method === self::PROGRESS_WEIGHTED) {
            // Weighted average based on project budget
            $totalBudget = $projects->sum('budget');
            if ($totalBudget > 0) {
                $weightedProgress = $projects->sum(fn ($p) => ($p->progress ?? 0) * ($p->budget ?? 0));

                return round($weightedProgress / $totalBudget, 2);
            }
        }

        // Default: simple average
        return round($projects->avg('progress') ?? 0, 2);
    }

    /**
     * Update progress from projects.
     */
    public function updateProgress(): void
    {
        $this->update(['progress' => $this->calculateProgress()]);
    }

    /**
     * Get the budget utilization percentage.
     */
    public function getBudgetUtilizationAttribute(): float
    {
        $budget = $this->total_program_budget ?: $this->budget;
        if (! $budget || $budget == 0) {
            return 0;
        }

        return round(($this->spent_amount / $budget) * 100, 2);
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    /**
     * Get the priority label.
     */
    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITIES[$this->priority] ?? 'غير محدد';
    }

    /**
     * Get the progress method label.
     */
    public function getProgressMethodLabelAttribute(): string
    {
        return self::PROGRESS_METHODS[$this->progress_calculation_method] ?? 'غير محدد';
    }

    /**
     * Scope for active programs.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PLANNING,
            self::STATUS_IN_PROGRESS,
        ]);
    }

    /**
     * Scope for ordering.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    // ========== ScopeAware ==========

    public function scopeParent(): ?Model
    {
        return $this->portfolio_id ? $this->portfolio()->first() : null;
    }

    public function scopeTypeKey(): string
    {
        return 'program';
    }

    public function scopeOrganizationId(): ?int
    {
        // Prefer the program's own organization_id; fall back to the parent
        // portfolio's organization when it is not set directly.
        if ($this->organization_id !== null) {
            return (int) $this->organization_id;
        }

        if ($this->portfolio_id !== null) {
            $portfolio = $this->portfolio()->first();
            if ($portfolio instanceof ScopeAware) {
                return $portfolio->scopeOrganizationId();
            }
        }

        return null;
    }
}
