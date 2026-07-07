<?php

namespace App\Modules\Strategy\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model implements ScopeAware
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'reviewable_type',
        'reviewable_id',
        'organization_id',
        'type',
        'pdca_phase',
        'review_date',
        'period_start',
        'period_end',
        'progress_snapshot',
        'overall_status',
        'achievements',
        'challenges',
        'lessons_learned',
        'next_steps',
        'recommendations',
        'conducted_by',
        'attendees',
    ];

    protected $casts = [
        'review_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'progress_snapshot' => 'decimal:2',
        'attendees' => 'array',
    ];

    /**
     * Type constants
     */
    public const TYPE_MONTHLY = 'monthly';

    public const TYPE_QUARTERLY = 'quarterly';

    public const TYPE_ANNUAL = 'annual';

    public const TYPE_ADHOC = 'adhoc';

    public const TYPES = [
        self::TYPE_MONTHLY => 'شهري',
        self::TYPE_QUARTERLY => 'ربع سنوي',
        self::TYPE_ANNUAL => 'سنوي',
        self::TYPE_ADHOC => 'طارئ',
    ];

    /**
     * PDCA Phase constants
     */
    public const PDCA_PLAN = 'plan';

    public const PDCA_DO = 'do';

    public const PDCA_CHECK = 'check';

    public const PDCA_ACT = 'act';

    public const PDCA_PHASES = [
        self::PDCA_PLAN => 'التخطيط (Plan)',
        self::PDCA_DO => 'التنفيذ (Do)',
        self::PDCA_CHECK => 'المراجعة (Check)',
        self::PDCA_ACT => 'التحسين (Act)',
    ];

    /**
     * Overall Status constants
     */
    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_AT_RISK = 'at_risk';

    public const STATUS_OFF_TRACK = 'off_track';

    public const STATUS_COMPLETED = 'completed';

    public const OVERALL_STATUSES = [
        self::STATUS_ON_TRACK => 'على المسار',
        self::STATUS_AT_RISK => 'في خطر',
        self::STATUS_OFF_TRACK => 'متأخر',
        self::STATUS_COMPLETED => 'مكتمل',
    ];

    /**
     * Get the reviewable entity (objective, initiative, or project).
     */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who conducted this review.
     */
    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    /**
     * Get the type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? 'غير محدد';
    }

    /**
     * Get the PDCA phase label.
     */
    public function getPdcaPhaseLabelAttribute(): string
    {
        return self::PDCA_PHASES[$this->pdca_phase] ?? 'غير محدد';
    }

    /**
     * Get the overall status label.
     */
    public function getOverallStatusLabelAttribute(): string
    {
        return self::OVERALL_STATUSES[$this->overall_status] ?? 'غير محدد';
    }

    /**
     * Scope for specific review type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for specific PDCA phase.
     */
    public function scopePhase($query, string $phase)
    {
        return $query->where('pdca_phase', $phase);
    }

    /**
     * Scope for recent reviews.
     */
    public function scopeRecent($query, int $limit = 5)
    {
        return $query->orderBy('review_date', 'desc')->limit($limit);
    }

    // ========== ScopeAware ==========
    // A review rolls up to its polymorphic reviewable (Project / Program /
    // StrategicObjective). It carries its own organization_id, so organization
    // isolation is enforced even when the polymorph cannot be resolved. The scope
    // chain ascends through the reviewable when (and only when) that target is a
    // ScopeAware model. StrategicObjective is a legacy non-scoped class (its table
    // was dropped), so reviews on objectives are governed by organization_id alone.

    public function scopeParent(): ?Model
    {
        return $this->resolveScopeAwareParent();
    }

    public function scopeTypeKey(): string
    {
        // Adopt the parent module's key when the polymorph resolves to a
        // ScopeAware model; fall back to 'project' (the common reviewable) when it
        // does not. The leaf entry is cosmetic — the actual grant comes from the
        // ascending reviewable on the chain.
        $parent = $this->resolveScopeAwareParent();

        return $parent?->scopeTypeKey() ?? 'project';
    }

    public function scopeOrganizationId(): ?int
    {
        // Prefer the review's own organization_id; fall back to the parent.
        if ($this->organization_id !== null) {
            return (int) $this->organization_id;
        }

        $parent = $this->resolveScopeAwareParent();

        return $parent?->scopeOrganizationId();
    }

    /**
     * Resolve the polymorphic reviewable as a ScopeAware parent, or null.
     *
     * Polymorphic safety: never assume the target type is a real, loadable,
     * ScopeAware model. A missing/odd/legacy reviewable_type (e.g. the dropped
     * StrategicObjective) or a deleted target yields null, and the engine then
     * governs the record by its own organization_id alone (org-level checks)
     * instead of crashing.
     */
    private function resolveScopeAwareParent(): ?ScopeAware
    {
        $type = $this->reviewable_type;
        $id = $this->reviewable_id;

        if ($type === null || $id === null
            || ! is_subclass_of($type, Model::class)
            || ! is_subclass_of($type, ScopeAware::class)) {
            return null;
        }

        // Routed through the engine identity map (cached by class:id) so siblings
        // sharing one reviewable trigger a single fetch (N+1 fix).
        $parent = AccessDecision::resolveScopeParent($type, (int) $id);

        return $parent instanceof ScopeAware ? $parent : null;
    }
}
