<?php

namespace App\Modules\Strategy\Models;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Kpi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StrategicObjective Model
 *
 * Note: The strategic_objectives table was archived and dropped in migration
 * 2026_01_16_200003_archive_and_drop_strategic_objectives.php. This model is
 * retained only because a couple of controllers still reference its class name
 * for polymorphic metadata. New code should use Portfolio and Program directly.
 */
class StrategicObjective extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'portfolio_id',  // FK to portfolios (الالتزام التنفيذي)
        'bsc_perspective',
        'target_value',
        'measurement_unit',
        'current_value',
        'baseline_value',
        'start_date',
        'end_date',
        'weight',
        'status',
        'order',
        'owner_id',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'baseline_value' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    /**
     * BSC Perspectives
     */
    public const BSC_FINANCIAL = 'financial';

    public const BSC_CUSTOMER = 'customer';

    public const BSC_INTERNAL_PROCESS = 'internal_process';

    public const BSC_LEARNING_GROWTH = 'learning_growth';

    public const BSC_PERSPECTIVES = [
        self::BSC_FINANCIAL => 'المالي',
        self::BSC_CUSTOMER => 'العملاء',
        self::BSC_INTERNAL_PROCESS => 'العمليات الداخلية',
        self::BSC_LEARNING_GROWTH => 'التعلم والنمو',
    ];

    /**
     * Status constants
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
     * Generate a unique code for the objective.
     */
    public static function generateCode(): string
    {
        $year = date('Y');
        $prefix = "SO-{$year}-";
        $last = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(code FROM LENGTH(code) - 2) AS INTEGER) DESC')
            ->first();

        $next = $last ? intval(substr($last->code, -3)) + 1 : 1;

        return $prefix.str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get the portfolio for this objective.
     * (الالتزام التنفيذي)
     */
    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class, 'portfolio_id');
    }

    /**
     * Alias: direction() for backward compatibility.
     *
     * @deprecated Use portfolio() instead
     */
    public function direction(): BelongsTo
    {
        return $this->portfolio();
    }

    /**
     * Get the programs for this objective through portfolio.
     * Note: Programs are now linked through Portfolio, not directly to Objective.
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'portfolio_id', 'portfolio_id')->orderBy('order');
    }

    /**
     * Get the KPIs for this objective.
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
     * Get the reviews for this objective.
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /**
     * Get the owner of this objective.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get the creator of this objective.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate progress.
     */
    public function calculateProgress(): float
    {
        // If we have a target value and current value, calculate from those
        if ($this->target_value > 0 && $this->current_value >= 0) {
            return min(100, round(($this->current_value / $this->target_value) * 100, 2));
        }

        // Otherwise, calculate from programs
        $programs = $this->programs()
            ->whereNotIn('status', [Program::STATUS_CANCELLED])
            ->get();

        if ($programs->isEmpty()) {
            return 0;
        }

        $totalWeight = $programs->sum('weight');
        $weightedProgress = $programs->sum(fn ($p) => $p->progress * $p->weight);

        return $totalWeight > 0 ? round($weightedProgress / $totalWeight, 2) : 0;
    }

    /**
     * Get the BSC perspective label.
     */
    public function getBscPerspectiveLabelAttribute(): string
    {
        return self::BSC_PERSPECTIVES[$this->bsc_perspective] ?? 'غير محدد';
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    /**
     * Scope for active objectives.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for BSC perspective.
     */
    public function scopePerspective($query, string $perspective)
    {
        return $query->where('bsc_perspective', $perspective);
    }

    /**
     * Scope for ordering.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }
}
