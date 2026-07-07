<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Database\Factories\RiskManagement\RiskAssessmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model implements ScopeAware
{
    use HasFactory;

    protected $table = 'risk_assessments';

    protected $fillable = [
        'risk_id',
        'organization_id',
        'likelihood',
        'impact',
        'score',
        'level',
        'residual_likelihood',
        'residual_impact',
        'residual_score',
        'residual_level',
        'assessor_id',
        'notes',
        'next_review_at',
        'review_due_notified_at',
    ];

    protected $casts = [
        'likelihood' => 'integer',
        'impact' => 'integer',
        'score' => 'integer',
        'residual_likelihood' => 'integer',
        'residual_impact' => 'integer',
        'residual_score' => 'integer',
        'next_review_at' => 'date',
        'review_due_notified_at' => 'datetime',
    ];

    protected static function newFactory(): RiskAssessmentFactory
    {
        return RiskAssessmentFactory::new();
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function isOverdue(?string $today = null): bool
    {
        if (! $this->next_review_at) {
            return false;
        }

        $cutoff = $today ?? now()->toDateString();

        return $this->next_review_at->toDateString() <= $cutoff;
    }

    // ========== ScopeAware ==========
    // An assessment rolls up to its parent Risk: it inherits the risk's
    // department/riskable visibility and organization through the chain.

    public function scopeParent(): ?Model
    {
        // The parent Risk (ScopeAware). Resolved through the engine (cached by id)
        // so the same risk is not re-fetched for every assessment in a list (N+1 fix).
        return $this->risk_id !== null
            ? AccessDecision::resolveScopeParent(Risk::class, (int) $this->risk_id)
            : null;
    }

    public function scopeTypeKey(): string
    {
        // Adopt the parent module's key; assessments have no separate scope_type.
        return 'risk';
    }

    public function scopeOrganizationId(): ?int
    {
        // Prefer the assessment's own organization_id; fall back to the parent risk.
        if ($this->organization_id !== null) {
            return (int) $this->organization_id;
        }

        $risk = $this->risk_id !== null
            ? AccessDecision::resolveScopeParent(Risk::class, (int) $this->risk_id)
            : null;

        return $risk instanceof ScopeAware ? $risk->scopeOrganizationId() : null;
    }
}
