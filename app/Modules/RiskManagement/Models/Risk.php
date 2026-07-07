<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Enums\RiskType;
use Carbon\Carbon;
use Database\Factories\RiskManagement\RiskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Risk extends Model implements ScopeAware
{
    use HasFactory, SoftDeletes;

    protected $table = 'risks';

    protected $fillable = [
        'code',
        'organization_id',
        'title',
        'discovery_date',
        'type',
        'department_id',
        'description',
        'consequences',
        'initial_likelihood',
        'initial_impact',
        'owner_id',
        'stakeholder_ids',
        'preventive_measures',
        'target_close_date',
        'response_type',
        'riskable_type',
        'riskable_id',
        'created_by',
    ];

    protected $casts = [
        'discovery_date' => 'date',
        'initial_likelihood' => 'integer',
        'initial_impact' => 'integer',
        'current_likelihood' => 'integer',
        'current_impact' => 'integer',
        'current_score' => 'integer',
        'type' => RiskType::class,
        'status' => RiskStatus::class,
        'response_type' => RiskResponseType::class,
        'stakeholder_ids' => 'array',
        'target_close_date' => 'date',
    ];

    protected static function newFactory(): RiskFactory
    {
        return RiskFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Risk $risk) {
            if (empty($risk->code)) {
                $risk->code = static::generateCode();
            }
        });
    }

    /**
     * Auto-generated yearly code: RSK-YYYY-NNNN.
     */
    public static function generateCode(): string
    {
        $year = (string) Carbon::now()->year;
        $prefix = "RSK-{$year}-";

        $last = static::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('CAST(RIGHT(code, 4) AS INTEGER) DESC')
            ->first();

        $next = $last ? intval(substr($last->code, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    // ========================================
    // Relations
    // ========================================

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

    public function riskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('created_at');
    }

    public function latestAssessment(): HasMany
    {
        return $this->hasMany(RiskAssessment::class)->orderByDesc('created_at')->limit(1);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RiskAction::class);
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(RiskStatusChange::class)->orderByDesc('created_at');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(RiskAlert::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeForOrganization($query, int $organizationId)
    {
        // Strictly scopes to the given org id. Callers MUST pass a non-null id;
        // for null-org (super-admin) records, use scopeForNullOrgOnly() instead.
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForNullOrgOnly($query)
    {
        // Scopes to records whose organization_id is null (super-admin global
        // risks). The companion to scopeForOrganization() so callers must
        // explicitly opt in to cross-org visibility.
        return $query->whereNull('organization_id');
    }

    public function scopeByStatus($query, RiskStatus $status)
    {
        return $query->where('status', $status->value);
    }

    public function scopeByLevel($query, string $level)
    {
        return $query->where('current_level', $level);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [RiskStatus::Closed->value, RiskStatus::Accepted->value]);
    }

    // ========================================
    // Helpers
    // ========================================

    public function isTerminal(): bool
    {
        return $this->status?->isTerminal() ?? false;
    }

    public function canTransitionTo(RiskStatus $target): bool
    {
        return $this->status?->canTransitionTo($target) ?? false;
    }

    /**
     * Open actions (pending, in_progress, blocked) used by KPIs.
     */
    public function openActionsCount(): int
    {
        return $this->actions()
            ->whereNotIn('status', [
                RiskActionStatus::Completed->value,
                RiskActionStatus::Cancelled->value,
            ])
            ->count();
    }

    // ========== ScopeAware ==========

    public function scopeParent(): ?Model
    {
        // First parent: the department if set. Resolved through the engine (cached
        // by id) so the same department is not re-fetched for every record in a list
        // (N+1 fix). If the department relation is eager-loaded with the full set of
        // scope-chain columns we reuse it without a query; a partial (id,name) load
        // falls through to the engine to fetch the full row.
        if ($this->department_id !== null) {
            if (AccessDecision::scopeParentFullyLoaded($this, 'department')) {
                return $this->getRelation('department');
            }

            return AccessDecision::resolveScopeParent(Department::class, (int) $this->department_id);
        }

        // Second parent: riskable (may be a project) — also resolved by id via the cache.
        if ($this->riskable_type !== null && $this->riskable_id !== null) {
            return AccessDecision::resolveScopeParent($this->riskable_type, (int) $this->riskable_id);
        }

        return null;
    }

    public function scopeTypeKey(): string
    {
        return 'risk';
    }

    public function scopeOrganizationId(): ?int
    {
        return $this->organization_id ? (int) $this->organization_id : null;
    }
}
