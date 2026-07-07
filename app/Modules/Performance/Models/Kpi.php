<?php

namespace App\Modules\Performance\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Traits\LogsActivity;
use Database\Factories\KpiFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kpi extends Model implements ScopeAware
{
    use HasFactory, LogsActivity, SoftDeletes;

    // M-11: audit KPI value/state mutations (incl. measurement-driven
    // current_value recalcs) with before/after captured by LogsActivity.
    protected array $trackedFields = [
        'name', 'current_value', 'baseline', 'target', 'status', 'owner_id', 'direction',
    ];

    protected static function newFactory(): Factory
    {
        return KpiFactory::new();
    }

    public const DIRECTION_INCREASE = 'increase';

    public const DIRECTION_DECREASE = 'decrease';

    public const DIRECTION_MAINTAIN = 'maintain';

    public const FREQUENCY_LABELS = [
        'daily' => 'يومي',
        'weekly' => 'أسبوعي',
        'monthly' => 'شهري',
        'quarterly' => 'ربع سنوي',
        'yearly' => 'سنوي',
    ];

    public const DIRECTION_LABELS = [
        self::DIRECTION_INCREASE => 'الزيادة إيجابية',
        self::DIRECTION_DECREASE => 'النقصان إيجابي',
        self::DIRECTION_MAINTAIN => 'الحفاظ على المستوى',
    ];

    protected $fillable = [
        'code',
        'organization_id',
        'department_id',
        'name',
        'description',
        'measurement_method',
        'category',
        'baseline',
        'target',
        'current_value',
        'unit',
        'frequency',
        'direction',
        'status',
        'owner_id',
        'created_by',
        'order',
    ];

    protected $casts = [
        'baseline' => 'decimal:2',
        'target' => 'decimal:2',
        'current_value' => 'decimal:2',
        'order' => 'integer',
    ];

    protected $appends = [
        'achievement_percentage',
        'performance_status',
        'frequency_label',
        'direction_label',
    ];

    protected static function booted(): void
    {
        static::creating(function (Kpi $kpi) {
            if (empty($kpi->code)) {
                $kpi->code = static::generateCode();
            }
        });
    }

    public static function generateCode(): string
    {
        $year = date('Y');
        $prefix = "KPI-{$year}-";
        $last = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('CAST(SUBSTRING(code FROM LENGTH(code) - 3) AS INTEGER) DESC')
            ->first();

        $next = $last ? intval(substr($last->code, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(KpiMeasurement::class)->orderBy('measurement_date', 'desc')->orderBy('id', 'desc');
    }

    public function links(): HasMany
    {
        return $this->hasMany(KpiLink::class);
    }

    public function getAchievementPercentageAttribute(): float
    {
        $baseline = (float) ($this->baseline ?? 0);
        $target = (float) ($this->target ?? 0);
        $current = (float) ($this->current_value ?? 0);

        if ($this->direction === self::DIRECTION_DECREASE) {
            $range = $baseline - $target;
            $progress = $range == 0.0 ? 0 : (($baseline - $current) / $range) * 100;
        } elseif ($this->direction === self::DIRECTION_MAINTAIN) {
            if ($target == 0.0) {
                $progress = $current == 0.0 ? 100 : 0;
            } else {
                $progress = 100 - (abs($current - $target) / abs($target) * 100);
            }
        } else {
            $range = $target - $baseline;
            $progress = $range == 0.0 ? 0 : (($current - $baseline) / $range) * 100;
        }

        return round(max(0, min(100, $progress)), 2);
    }

    public function getPerformanceStatusAttribute(): string
    {
        $achievement = $this->achievement_percentage;

        if ($achievement >= 90) {
            return 'on_track';
        }

        if ($achievement >= 70) {
            return 'at_risk';
        }

        return 'off_track';
    }

    public function getFrequencyLabelAttribute(): string
    {
        return self::FREQUENCY_LABELS[$this->frequency] ?? 'غير محدد';
    }

    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTION_LABELS[$this->direction] ?? 'غير محدد';
    }

    public function updateFromLatestMeasurement(): void
    {
        $latest = $this->measurements()->first();

        if ($latest) {
            $this->update(['current_value' => $latest->value]);
        }
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // ========== ScopeAware ==========

    public function scopeParent(): ?Model
    {
        return AccessDecision::resolveScopeParent(Department::class, $this->department_id ?: null);
    }

    public function scopeTypeKey(): string
    {
        return 'kpi';
    }

    public function scopeOrganizationId(): ?int
    {
        return $this->organization_id ? (int) $this->organization_id : null;
    }
}
