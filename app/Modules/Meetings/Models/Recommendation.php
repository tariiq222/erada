<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Carbon\CarbonInterface;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recommendation extends Model implements ScopeAware
{
    use HasFactory, HasOrganizationScope, SoftDeletes;

    protected static function newFactory(): RecommendationFactory
    {
        return RecommendationFactory::new();
    }

    public const KIND_RULING = 'ruling';

    public const KIND_ACTION_ITEM = 'action_item';

    public const KINDS = [
        self::KIND_RULING => 'قرار',
        self::KIND_ACTION_ITEM => 'إجراء',
    ];

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PROPOSED => 'مقترح',
        self::STATUS_ACCEPTED => 'مقبول',
        self::STATUS_PENDING => 'بانتظار القرار',
        self::STATUS_APPROVED => 'معتمد',
        self::STATUS_REJECTED => 'مرفوض',
        self::STATUS_DEFERRED => 'مؤجل',
        self::STATUS_COMPLETED => 'منجز',
    ];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public const PRIORITIES = [
        self::PRIORITY_LOW => 'منخفضة',
        self::PRIORITY_MEDIUM => 'متوسطة',
        self::PRIORITY_HIGH => 'عالية',
        self::PRIORITY_CRITICAL => 'حرجة',
    ];

    protected $fillable = [
        'reference_number', 'meeting_id', 'title', 'description',
        'priority', 'status', 'assignee_id', 'due_date',
        'completed_at', 'overdue_notified_at', 'organization_id',
        'decidable_type', 'decidable_id', 'kind', 'type',
        'requested_by', 'made_by', 'decision_date', 'effective_date',
        'impact', 'rationale',
        'defer_reason', 'deferred_until', 'deferred_by', 'deferred_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'decision_date' => 'date',
        'effective_date' => 'date',
    ];

    protected $attributes = [
        'reference_number' => null,
    ];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    public function uniqueIds(): array
    {
        return ['reference_number'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'made_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Local-scope helper for action_item recommendations awaiting accept/reject/defer
     * (proposed). For rulings this is intentionally not exposed — use status
     * filters directly because rulings go through `pending` then `approved` /
     * `rejected` / `deferred`, not `proposed`.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PROPOSED,
            self::STATUS_PENDING,
        ]);
    }

    /**
     * Convenience helper — rulings whose decision has not been recorded yet
     * (`pending` status). Used by dashboard counters that need a single
     * "decisions awaiting decision" metric across all modules.
     */
    public function scopePendingRulings(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_RULING)
            ->where('status', self::STATUS_PENDING);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return match ($this->kind) {
            self::KIND_RULING => match ($newStatus) {
                self::STATUS_APPROVED, self::STATUS_REJECTED => in_array($this->status, [self::STATUS_PENDING, self::STATUS_DEFERRED], true),
                self::STATUS_DEFERRED => in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true),
                default => false,
            },
            self::KIND_ACTION_ITEM => match (true) {
                $this->status === self::STATUS_PROPOSED => in_array($newStatus, [self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_DEFERRED], true),
                $this->status === self::STATUS_ACCEPTED => in_array($newStatus, [self::STATUS_COMPLETED, self::STATUS_DEFERRED], true),
                $this->status === self::STATUS_DEFERRED => in_array($newStatus, [self::STATUS_ACCEPTED, self::STATUS_REJECTED], true),
                default => false,
            },
            default => false,
        };
    }

    public function approve(int $madeBy, ?string $rationale = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'made_by' => $madeBy,
            'decision_date' => now(),
            'rationale' => $rationale ?? $this->rationale,
        ]);
    }

    public function reject(int $rejectedBy, ?string $rationale = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'made_by' => $rejectedBy,
            'decision_date' => now(),
            'rationale' => $rationale ?? $this->rationale,
        ]);
    }

    public function defer(int $deferredBy, ?string $reason = null, ?CarbonInterface $until = null): void
    {
        $this->update([
            'status' => self::STATUS_DEFERRED,
            'deferred_by' => $deferredBy,
            'defer_reason' => $reason,
            'deferred_until' => $until,
            'deferred_at' => now(),
        ]);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITIES[$this->priority] ?? 'غير محدد';
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? 'غير محدد';
    }

    public function getIsRulingAttribute(): bool
    {
        return $this->kind === self::KIND_RULING;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== self::STATUS_COMPLETED
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    /** @return array<int, string> */
    public static function statusValues(): array
    {
        return array_keys(self::STATUSES);
    }

    /** @return array<int, string> */
    public static function priorityValues(): array
    {
        return array_keys(self::PRIORITIES);
    }

    /** @return array<int, string> */
    public static function kindValues(): array
    {
        return array_keys(self::KINDS);
    }

    // ========== ScopeAware ==========
    // A recommendation rolls up directly to its meeting (no decision hop in
    // Direction B): the parent scope chain is meeting -> department, and the
    // meeting's organization_id / department_id drive the visibility rules.

    public function scopeParent(): ?Model
    {
        return AccessDecision::resolveScopeParent(Meeting::class, $this->meeting_id ?: null);
    }

    public function scopeTypeKey(): string
    {
        return 'meeting';
    }

    public function scopeOrganizationId(): ?int
    {
        if ($this->organization_id) {
            return (int) $this->organization_id;
        }

        return $this->meeting?->organization_id ? (int) $this->meeting->organization_id : null;
    }

    /**
     * Visibility scope — narrows a recommendations query to what the user may see,
     * mirroring UserRiskScope. Additive (OR) after organization isolation:
     *   - super_admin sees all;
     *   - an org-level grant of recommendations.view (admin) sees the whole org;
     *   - otherwise: recommendations assigned to the user, OR recommendations whose
     *     meeting rolls up to a department the user's scoped roles grant
     *     (subtree included).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        // Null-org fail-closed: a non-super user with no organization_id has no
        // scope to query against. Previously this branch was silently skipped,
        // causing the user to see ALL recommendations (or none, depending on
        // downstream grants) — replace with a force-empty result set.
        if ($user->organization_id === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('organization_id', $user->organization_id);

        $scopes = AccessDecision::grantingScopes($user, Capability::RECOMMENDATIONS_VIEW);
        if (AccessDecision::grantsAtOrganization($user, Capability::RECOMMENDATIONS_VIEW)
            || ($scopes['organization'] ?? []) !== []) {
            return $query;
        }

        $deptIds = AccessDecision::subtreeDepartmentIds($scopes['department'] ?? []);

        return $query->where(function (Builder $q) use ($user, $deptIds) {
            $q->where('assignee_id', $user->id);

            if ($deptIds !== []) {
                $q->orWhereHas('meeting', fn (Builder $m) => $m->whereIn('department_id', $deptIds));
            }
        });
    }
}
