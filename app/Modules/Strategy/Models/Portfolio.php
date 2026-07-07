<?php

namespace App\Modules\Strategy\Models;

use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Database\Factories\PortfolioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Portfolio Model (المحفظة / الالتزام التنفيذي)
 *
 * يمثل المستوى الأعلى في السلسلة الذهبية (PMI Standard):
 * Portfolio -> Program -> Project
 *
 * ملاحظة: يُعرض للمستخدم النهائي باسم "الالتزام التنفيذي"
 */
class Portfolio extends Model implements ScopeAware
{
    use HasFactory, SoftDeletes;

    protected $table = 'portfolios';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PortfolioFactory
    {
        return PortfolioFactory::new();
    }

    protected $fillable = [
        'code',
        'name',
        'description',
        'rationale',
        'strategic_plan_link',
        'directive_source',
        'directive_source_other',
        'start_date',
        'end_date',
        'status',
        'portfolio_status',
        'portfolio_progress',
        'order',
        'priority_rank',
        'weight',
        'created_by',
        'organization_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'weight' => 'decimal:2',
        'portfolio_progress' => 'decimal:2',
        'priority_rank' => 'integer',
        'order' => 'integer',
        'organization_id' => 'integer',
    ];

    /**
     * Operational Status constants (الحالة التشغيلية)
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'مسودة',
        self::STATUS_ACTIVE => 'نشط',
        self::STATUS_COMPLETED => 'مكتمل',
        self::STATUS_CANCELLED => 'ملغي',
    ];

    /**
     * Portfolio Strategic Status constants (الحالة الاستراتيجية للمحفظة)
     */
    public const PORTFOLIO_STATUS_ACTIVE = 'active';

    public const PORTFOLIO_STATUS_REBALANCING = 'rebalancing';

    public const PORTFOLIO_STATUS_FROZEN = 'frozen';

    public const PORTFOLIO_STATUS_CLOSED = 'closed_strategically';

    public const PORTFOLIO_STATUSES = [
        self::PORTFOLIO_STATUS_ACTIVE => 'نشطة',
        self::PORTFOLIO_STATUS_REBALANCING => 'إعادة توازن',
        self::PORTFOLIO_STATUS_FROZEN => 'مجمدة',
        self::PORTFOLIO_STATUS_CLOSED => 'مغلقة استراتيجياً',
    ];

    /**
     * Directive source constants (جهة التوجيه)
     */
    public const DIRECTIVE_SOURCE_CLUSTER_3 = 'cluster_3';

    public const DIRECTIVE_SOURCE_MOH = 'moh';

    public const DIRECTIVE_SOURCE_HOLDING = 'holding';

    public const DIRECTIVE_SOURCE_OTHER = 'other';

    public const DIRECTIVE_SOURCES = [
        self::DIRECTIVE_SOURCE_CLUSTER_3 => 'التجمع الثالث',
        self::DIRECTIVE_SOURCE_MOH => 'وزارة الصحة',
        self::DIRECTIVE_SOURCE_HOLDING => 'الصحة القابضة',
        self::DIRECTIVE_SOURCE_OTHER => 'جهة أخرى',
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
     * Generate a unique code for the portfolio.
     */
    public static function generateCode(): string
    {
        $year = date('Y');
        $prefix = "PF-{$year}-";
        $last = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(code FROM LENGTH(code) - 2) AS INTEGER) DESC')
            ->first();

        $next = $last ? intval(substr($last->code, -3)) + 1 : 1;

        return $prefix.str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get the programs for this portfolio (PMI Standard).
     * Direct relationship: Portfolio -> Programs
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'portfolio_id')->orderBy('order');
    }

    /**
     * Get the projects through programs.
     */
    public function projects(): HasManyThrough
    {
        return $this->hasManyThrough(
            Project::class,
            Program::class,
            'portfolio_id',    // FK on programs
            'program_id',      // FK on projects
            'id',              // Local key on portfolios
            'id'               // Local key on programs
        );
    }

    /**
     * Get the creator of this portfolio.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate progress based on programs (weighted).
     * PMI Standard: Progress calculated from direct child programs
     */
    public function calculateProgress(): float
    {
        $programs = $this->programs()
            ->whereNotIn('status', [Program::STATUS_CANCELLED])
            ->get();

        if ($programs->isEmpty()) {
            return 0;
        }

        $totalWeight = $programs->sum('weight');
        $weightedProgress = $programs->sum(fn ($p) => ($p->progress ?? 0) * ($p->weight ?? 1));

        return $totalWeight > 0 ? round($weightedProgress / $totalWeight, 2) : 0;
    }

    /**
     * Update the portfolio progress.
     */
    public function updateProgress(): void
    {
        $this->update(['portfolio_progress' => $this->calculateProgress()]);
    }

    /**
     * Check if portfolio can be closed strategically.
     */
    public function canBeClosedStrategically(): bool
    {
        // لا يمكن الإغلاق إذا كان هناك برامج نشطة
        $activePrograms = $this->programs()
            ->whereIn('status', [
                Program::STATUS_PLANNING,
                Program::STATUS_IN_PROGRESS,
            ])
            ->exists();

        return ! $activePrograms;
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    /**
     * Get the portfolio status label.
     */
    public function getPortfolioStatusLabelAttribute(): string
    {
        return self::PORTFOLIO_STATUSES[$this->portfolio_status] ?? 'غير محدد';
    }

    /**
     * Get the directive source label.
     */
    public function getDirectiveSourceLabelAttribute(): string
    {
        if ($this->directive_source === self::DIRECTIVE_SOURCE_OTHER && $this->directive_source_other) {
            return $this->directive_source_other;
        }

        return self::DIRECTIVE_SOURCES[$this->directive_source] ?? '';
    }

    /**
     * Scope for active portfolios (operational status).
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for strategically active portfolios.
     */
    public function scopeStrategicallyActive($query)
    {
        return $query->where('portfolio_status', self::PORTFOLIO_STATUS_ACTIVE);
    }

    /**
     * Scope for draft portfolios.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope for ordering by priority then order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority_rank', 'desc')
            ->orderBy('order')
            ->orderBy('created_at');
    }

    /**
     * Scope for ordering by weight.
     */
    public function scopeByWeight($query)
    {
        return $query->orderBy('weight', 'desc');
    }

    // ========== ScopeAware ==========

    public function scopeParent(): ?Model
    {
        // Portfolio هو أعلى السلسلة — لا أب
        return null;
    }

    public function scopeTypeKey(): string
    {
        return 'portfolio';
    }

    public function scopeOrganizationId(): ?int
    {
        return $this->organization_id ? (int) $this->organization_id : null;
    }

    /**
     * Get the organization for this portfolio.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
