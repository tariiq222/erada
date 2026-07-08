<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Tasks\Models\Task;
use Database\Factories\MeetingResolutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MeetingResolution — Phase 1 / Direction R.
 *
 * A typed output of a meeting. Each row carries one of two `kind` values:
 *   - `recommendation` — a soft suggestion, no approval gate
 *   - `decision`       — a hard resolution, no approval gate
 *
 * Status moves forward only: open → in_progress → (converted_to_tasks |
 * completed | cancelled). A `hold` is metadata only and does not change
 * status — held resolutions keep their current status until released.
 *
 * No `approve` / `reject` / `adopt` / `deliberate` / `endorsed` lifecycle
 * exists on this model by design. The legacy `Recommendation` model still
 * carries the Direction B lifecycle and is read-only for the new flow.
 */
class MeetingResolution extends Model implements ScopeAware
{
    use HasFactory, HasOrganizationScope, SoftDeletes;

    protected static function newFactory(): MeetingResolutionFactory
    {
        return MeetingResolutionFactory::new();
    }

    public const KIND_RECOMMENDATION = 'recommendation';

    public const KIND_DECISION = 'decision';

    public const KINDS = [
        self::KIND_RECOMMENDATION => 'توصية',
        self::KIND_DECISION => 'قرار',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CONVERTED_TO_TASKS = 'converted_to_tasks';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'مفتوح',
        self::STATUS_IN_PROGRESS => 'قيد التنفيذ',
        self::STATUS_CONVERTED_TO_TASKS => 'محول إلى مهام',
        self::STATUS_COMPLETED => 'مكتمل',
        self::STATUS_CANCELLED => 'ملغى',
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
        'reference_number',
        'organization_id',
        'meeting_id',
        'kind',
        'title',
        'description',
        'owner_id',
        'status',
        'priority',
        'due_date',
        'hold_reason',
        'hold_until',
        'hold_by',
        'hold_at',
        'created_by',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'hold_until' => 'datetime',
        'hold_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Phase 3: count/percentage accessors are always serialized so the
     * `show` endpoint and any JSON dump include the progress aggregates
     * without the controller having to call `$resolution->append(...)`.
     * Backed by the `tasks_count_cached` / `completed_tasks_count_cached`
     * attributes when the eager-loaded `withCount()` values are present
     * (faster path); otherwise the accessors fall back to live queries
     * via the morphMany relationship.
     */
    protected $appends = [
        'tasks_count',
        'completed_tasks_count',
        'pending_tasks_count',
        'completion_percentage',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hold_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ResolutionLink::class, 'resolution_id');
    }

    /**
     * Phase 3 / Direction R: real tasks created from this resolution.
     *
     * The polymorphic key on the tasks table is `source_type = 'MeetingResolution'`
     * (matching Task::SOURCE_CLASS_MAP) and `source_id = $this->id`. We mirror
     * the same short-token convention Recommendation uses — `source_type` is
     * the short model basename, NOT a fully-qualified class string. Task rows
     * stamped from this resolution therefore resolve their `scopeParent()`
     * through Task::SOURCE_CLASS_MAP['MeetingResolution'] back to this row,
     * and from there through `Meeting::scopeParent()` to the meeting's
     * department / organization chain.
     */
    public function tasks(): HasMany
    {
        // Phase 4: Laravel's morphMany auto-prepends `WHERE source_type =
        // '<FQCN>'` which collides with our short-basename token. We use a
        // plain hasMany constrained on `source_id` + a manual whereIn on
        // `source_type` to support all three valid tokens: the canonical
        // short basename, the kebab legacy form, and the FQCN. The
        // Task::SOURCE_CLASS_MAP still drives scope walking and engine
        // visibility on the Task side.
        return $this->hasMany(Task::class, 'source_id', 'id')
            ->whereIn('source_type', [
                'MeetingResolution',
                'meeting_resolution',
                Task::class,
            ]);
    }

    public function isOnHold(): bool
    {
        return $this->hold_at !== null
            && ($this->hold_until === null || $this->hold_until->isFuture());
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return match ($newStatus) {
            self::STATUS_IN_PROGRESS => $this->status === self::STATUS_OPEN,
            self::STATUS_CONVERTED_TO_TASKS => in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true),
            self::STATUS_COMPLETED => in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true),
            self::STATUS_CANCELLED => in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true),
            default => false,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? 'غير محدد';
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITIES[$this->priority] ?? 'غير محدد';
    }

    public function getIsOnHoldAttribute(): bool
    {
        return $this->isOnHold();
    }

    /**
     * Phase 3 — counts surfaced to the SPA on list + detail endpoints so the
     * Follow-up page can show completion progress without re-querying the
     * tasks table from the client. Backed by Task::SOURCE_CLASS_MAP tokens
     * so the same query that the engine uses for scope walking also powers
     * these aggregates.
     */
    public function getTasksCountAttribute(): int
    {
        // The controller uses `withCount(['tasks as tasks_count' => ...])`
        // which populates `$this->attributes['tasks_count']`. We check for
        // that key first so the eager-loaded value is reused without
        // triggering a per-row subquery (N+1 prevention).
        return (int) ($this->attributes['tasks_count'] ?? $this->tasks()->count());
    }

    public function getCompletedTasksCountAttribute(): int
    {
        return (int) ($this->attributes['completed_tasks_count']
            ?? $this->tasks()->where('status', 'completed')->count());
    }

    public function getPendingTasksCountAttribute(): int
    {
        $total = $this->getTasksCountAttribute();
        $done = $this->getCompletedTasksCountAttribute();

        return max(0, $total - $done);
    }

    public function getCompletionPercentageAttribute(): float
    {
        $total = $this->getTasksCountAttribute();
        if ($total === 0) {
            return 0.0;
        }

        $done = $this->getCompletedTasksCountAttribute();

        return round(($done / $total) * 100, 1);
    }

    /** @return array<int, string> */
    public static function kindValues(): array
    {
        return array_keys(self::KINDS);
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

    // ========== ScopeAware ==========
    // A resolution inherits its parent scope chain from the meeting it came
    // from. We deliberately do NOT walk through ResolutionLink targets — the
    // link is informational, not authoritative for visibility.

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
}
