<?php

namespace App\Modules\RiskManagement\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use Database\Factories\RiskManagement\RiskActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskAction extends Model implements ScopeAware
{
    use HasFactory;

    protected $table = 'risk_actions';

    protected $fillable = [
        'risk_id',
        'organization_id',
        'title',
        'type',
        'description',
        'owner_id',
        'due_date',
        'status',
        'progress_pct',
        'notes',
        'overdue_notified_at',
    ];

    protected $casts = [
        'type' => RiskActionType::class,
        'status' => RiskActionStatus::class,
        'due_date' => 'date',
        'progress_pct' => 'integer',
        'overdue_notified_at' => 'datetime',
    ];

    protected static function newFactory(): RiskActionFactory
    {
        return RiskActionFactory::new();
    }

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(RiskActionUpdate::class, 'risk_action_id')->orderByDesc('created_at');
    }

    public function isOverdue(?string $today = null): bool
    {
        if ($this->status?->isTerminal() ?? false) {
            return false;
        }

        if (! $this->due_date) {
            return false;
        }

        $cutoff = $today ?? now()->toDateString();

        return $this->due_date->toDateString() < $cutoff;
    }

    // ========== ScopeAware ==========

    /**
     * الأب المباشر هو الـ Risk الأب — يتيح للمحرّك صعود السلسلة لفرض عزل org.
     */
    public function scopeParent(): ?Model
    {
        return AccessDecision::resolveScopeParent(Risk::class, $this->risk_id ?: null);
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
