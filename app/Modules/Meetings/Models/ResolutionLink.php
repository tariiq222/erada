<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResolutionLink — Phase 1 / Direction R.
 *
 * Pivot row that binds a `meeting_resolutions` row to a Project or a Risk
 * (the two allowed `linkable_type` values, enforced by the DB CHECK
 * constraint and validated at the controller layer).
 *
 * The link is intentionally NOT polymorphic in the Eloquent sense — we
 * store `linkable_type` as a constrained alias (`project` | `risk`) and
 * resolve it to a FQCN at insert time. This avoids the FQN/short-token
 * drift that already affects Task::SOURCE_CLASS_MAP.
 */
class ResolutionLink extends Model
{
    protected $fillable = [
        'resolution_id',
        'linkable_type',
        'linkable_id',
        'link_role',
        'created_by',
    ];

    public const TYPE_PROJECT = 'project';

    public const TYPE_RISK = 'risk';

    public const TYPES = [
        self::TYPE_PROJECT => 'مشروع',
        self::TYPE_RISK => 'خطر',
    ];

    public const ROLE_RELATED_TO = 'related_to';

    public const ROLE_IMPLEMENTATION_SCOPE = 'implementation_scope';

    public const ROLES = [
        self::ROLE_RELATED_TO => 'مرتبط بـ',
        self::ROLE_IMPLEMENTATION_SCOPE => 'نطاق التنفيذ',
    ];

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(MeetingResolution::class, 'resolution_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getLinkableTypeLabelAttribute(): string
    {
        return self::TYPES[$this->linkable_type] ?? 'غير محدد';
    }

    public function getLinkRoleLabelAttribute(): string
    {
        return self::ROLES[$this->link_role] ?? 'غير محدد';
    }

    /** @return array<int, string> */
    public static function typeValues(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return array<int, string> */
    public static function roleValues(): array
    {
        return array_keys(self::ROLES);
    }

    /**
     * Resolve a `linkable_type` alias to a model FQCN. Returns null for
     * unknown aliases so the controller can 422 rather than blow up.
     */
    public static function resolveClass(string $alias): ?string
    {
        return match ($alias) {
            self::TYPE_PROJECT => Project::class,
            self::TYPE_RISK => Risk::class,
            default => null,
        };
    }
}
