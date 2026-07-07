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

class Blocker extends Model implements ScopeAware
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'blockable_type',
        'blockable_id',
        'organization_id',
        'severity',
        'status',
        'identified_date',
        'expected_resolution_date',
        'resolved_date',
        'resolution',
        'reported_by',
        'assigned_to',
    ];

    protected $casts = [
        'identified_date' => 'date',
        'expected_resolution_date' => 'date',
        'resolved_date' => 'date',
    ];

    /**
     * Severity constants
     */
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_LOW => 'منخفض',
        self::SEVERITY_MEDIUM => 'متوسط',
        self::SEVERITY_HIGH => 'عالي',
        self::SEVERITY_CRITICAL => 'حرج',
    ];

    /**
     * Status constants
     */
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        self::STATUS_OPEN => 'مفتوح',
        self::STATUS_IN_PROGRESS => 'قيد المعالجة',
        self::STATUS_ESCALATED => 'مُصعّد',
        self::STATUS_RESOLVED => 'تم الحل',
    ];

    /**
     * Get the blockable entity (initiative, project, or task).
     */
    public function blockable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who reported this blocker.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Get the user assigned to resolve this blocker.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Resolve the blocker.
     */
    public function resolve(string $resolution): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolution' => $resolution,
            'resolved_date' => now(),
        ]);
    }

    /**
     * Escalate the blocker.
     */
    public function escalate(): void
    {
        $this->update(['status' => self::STATUS_ESCALATED]);
    }

    /**
     * Check if the blocker is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === self::STATUS_RESOLVED) {
            return false;
        }

        if (! $this->expected_resolution_date) {
            return false;
        }

        return $this->expected_resolution_date->isPast();
    }

    /**
     * Get days overdue.
     */
    public function getDaysOverdueAttribute(): int
    {
        if (! $this->is_overdue) {
            return 0;
        }

        return $this->expected_resolution_date->diffInDays(now());
    }

    /**
     * Get the severity label.
     */
    public function getSeverityLabelAttribute(): string
    {
        return self::SEVERITIES[$this->severity] ?? 'غير محدد';
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? 'غير محدد';
    }

    /**
     * Scope for open blockers.
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            self::STATUS_OPEN,
            self::STATUS_IN_PROGRESS,
            self::STATUS_ESCALATED,
        ]);
    }

    /**
     * Scope for critical blockers.
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Scope for overdue blockers.
     */
    public function scopeOverdue($query)
    {
        return $query->open()
            ->whereNotNull('expected_resolution_date')
            ->where('expected_resolution_date', '<', now());
    }

    // ========== ScopeAware ==========
    // A blocker rolls up to its polymorphic blockable (Project / Program / Task).
    // It carries its own organization_id, so organization isolation is enforced
    // even when the polymorph cannot be resolved. The scope chain ascends through
    // the blockable when (and only when) that target is a ScopeAware model.

    public function scopeParent(): ?Model
    {
        return $this->resolveScopeAwareParent();
    }

    public function scopeTypeKey(): string
    {
        // Adopt the parent module's key when the polymorph resolves to a
        // ScopeAware model; fall back to 'project' (the common blockable) when it
        // does not. The leaf entry is cosmetic — the actual grant comes from the
        // ascending blockable on the chain.
        $parent = $this->resolveScopeAwareParent();

        return $parent?->scopeTypeKey() ?? 'project';
    }

    public function scopeOrganizationId(): ?int
    {
        // Prefer the blocker's own organization_id; fall back to the parent.
        if ($this->organization_id !== null) {
            return (int) $this->organization_id;
        }

        $parent = $this->resolveScopeAwareParent();

        return $parent?->scopeOrganizationId();
    }

    /**
     * Resolve the polymorphic blockable as a ScopeAware parent, or null.
     *
     * Polymorphic safety: never assume the target type is a real, loadable,
     * ScopeAware model. A missing/odd/legacy blockable_type (e.g. a dropped class)
     * or a deleted target yields null, and the engine then governs the record by
     * its own organization_id alone (org-level checks) instead of crashing.
     */
    private function resolveScopeAwareParent(): ?ScopeAware
    {
        $type = $this->blockable_type;
        $id = $this->blockable_id;

        if ($type === null || $id === null
            || ! is_subclass_of($type, Model::class)
            || ! is_subclass_of($type, ScopeAware::class)) {
            return null;
        }

        // Routed through the engine identity map (cached by class:id) so siblings
        // sharing one blockable trigger a single fetch (N+1 fix).
        $parent = AccessDecision::resolveScopeParent($type, (int) $id);

        return $parent instanceof ScopeAware ? $parent : null;
    }
}
