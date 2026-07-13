<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Contracts\ScopeAware;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model implements ScopeAware
{
    use HasFactory, HasOrganizationScope, SoftDeletes;

    protected static function newFactory(): MeetingFactory
    {
        return MeetingFactory::new();
    }

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED => 'مجدول',
        self::STATUS_IN_PROGRESS => 'قيد التنفيذ',
        self::STATUS_COMPLETED => 'مكتمل',
        self::STATUS_CANCELLED => 'ملغى',
    ];

    protected $fillable = [
        'reference_number', 'title', 'description',
        'scheduled_at', 'duration_minutes',
        'location', 'virtual_link',
        'agenda', 'minutes',
        'status', 'organizer_id',
        'subject_type', 'subject_id',
        'category_id',
        'organization_id',
        'department_id',
        'reminder_sent_at',
        'agenda_requested_at',
    ];

    protected $attributes = [
        'reference_number' => null,
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'agenda_requested_at' => 'datetime',
    ];

    /** Declares which column holds the unique auto-generated identifier. */
    public function uniqueIds(): array
    {
        return ['reference_number'];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_attendees')
            ->withPivot(['role', 'attended'])
            ->withTimestamps();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MeetingCategory::class, 'category_id');
    }

    /**
     * Recommendations raised in this meeting (both `kind=ruling` and
     * `kind=action_item` rows live on the unified recommendations table in
     * Direction B — no separate `decisions` table).
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class, 'meeting_id');
    }

    /**
     * Convenience helper — only the ruling-kind recommendations attached to
     * this meeting. A meeting-ruling represents a decision recorded during
     * the meeting (approval / change request / etc.).
     */
    public function rulings(): HasMany
    {
        return $this->recommendations()->where('kind', Recommendation::KIND_RULING);
    }

    public function agendaItems(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class, 'meeting_id');
    }

    /**
     * Phase 1 / Direction R: typed outputs of the meeting under the new
     * "resolutions" philosophy (kind = recommendation | decision). The legacy
     * `recommendations()` relationship above still exists for Direction B
     * data; the new flow writes exclusively through `resolutions()`.
     */
    public function resolutions(): HasMany
    {
        return $this->hasMany(MeetingResolution::class, 'meeting_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return match (true) {
            $this->status === self::STATUS_SCHEDULED => in_array($newStatus, [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED], true),
            $this->status === self::STATUS_IN_PROGRESS => in_array($newStatus, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true),
            default => false,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    /** @return array<int, string> */
    public static function statusValues(): array
    {
        return array_keys(self::STATUSES);
    }

    // ========== ScopeAware ==========

    /**
     * Map of polymorphic subject_type tokens to their concrete ScopeAware
     * model classes. Phase 4.3 (AuthZ unification) lets a meeting hang off
     * any ScopeAware subject — when the meeting is about a Risk, OVR
     * incident, Kpi, Project, or Milestone, the engine walks THAT scope
     * chain instead of the department chain. Adding a subjectable parent
     * = one entry here AND a row in docs/authz/resource-authorization-graph.md.
     *
     * @var array<string, class-string<Model>>
     */
    private const SUBJECT_CLASS_MAP = [
        'Project' => Project::class,
        'Department' => Department::class,
        'Risk' => Risk::class,
        'IncidentReport' => IncidentReport::class,
        'Kpi' => Kpi::class,
        'Milestone' => Milestone::class,
        'Portfolio' => Portfolio::class,
        'Program' => Program::class,
    ];

    public function scopeParent(): ?Model
    {
        // Phase 4.3 (AuthZ unification): the polymorphic subject takes
        // priority over the department chain. A meeting about a Risk or
        // OVR incident inherits the source's scope chain so sensitive
        // decisions and recommendations stay scoped to the right
        // people. Falls through to department when no subject is set or
        // the subject row is missing.
        if ($this->subject_type !== null && $this->subject_id !== null) {
            $subjectClass = self::SUBJECT_CLASS_MAP[$this->subject_type] ?? null;
            if ($subjectClass === null && in_array($this->subject_type, self::SUBJECT_CLASS_MAP, true)) {
                // API writes store the fully-qualified morph class, while
                // legacy rows store the short basename token. Resolve both
                // through the same explicit allowlist so scoped authorization
                // follows the actual meeting subject in either representation.
                $subjectClass = $this->subject_type;
            }
            if ($subjectClass !== null) {
                $resolved = AccessDecision::resolveScopeParent($subjectClass, (int) $this->subject_id);
                if ($resolved instanceof Model) {
                    return $resolved;
                }
                // Subject row missing — fall through to department so
                // the meeting stays visible until ops resolves the
                // dangling subject pointer.
            }
        }

        return AccessDecision::resolveScopeParent(Department::class, $this->department_id ?: null);
    }

    public function scopeTypeKey(): string
    {
        return 'meeting';
    }

    public function scopeOrganizationId(): ?int
    {
        return $this->organization_id ? (int) $this->organization_id : null;
    }
}
