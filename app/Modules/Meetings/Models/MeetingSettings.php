<?php

namespace App\Modules\Meetings\Models;

use App\Modules\Core\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * إعدادات الاجتماعات لكل مؤسسة. صف واحد لكل organization،
 * يُنشأ تلقائياً بالقيم الافتراضية (من config/meetings.php) عند أول قراءة.
 */
class MeetingSettings extends Model
{
    protected $table = 'meeting_settings';

    protected $fillable = [
        'organization_id',
        'default_duration_minutes',
        'reminder_window_hours',
        'attendee_roles',
        'default_category_id',
        'agenda_request_enabled',
        'agenda_request_lead_hours',
        'decision_pending_expiry_days',
        'recommendation_overdue_grace_days',
    ];

    protected $casts = [
        'attendee_roles' => 'array',
        'agenda_request_enabled' => 'boolean',
    ];

    public const DEFAULT_ATTENDEE_ROLES = ['organizer', 'presenter', 'attendee', 'optional'];

    private static function cacheKey(?int $organizationId): string
    {
        return 'meeting_settings:org:'.($organizationId ?? 'global');
    }

    private static function defaults(?int $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'default_duration_minutes' => 60,
            'reminder_window_hours' => (int) config('meetings.meeting_reminder_window_hours', 24),
            'attendee_roles' => self::DEFAULT_ATTENDEE_ROLES,
            'default_category_id' => null,
            'agenda_request_enabled' => true,
            'agenda_request_lead_hours' => 48,
            'decision_pending_expiry_days' => (int) config('meetings.pending_decision_expiry_days', 30),
            'recommendation_overdue_grace_days' => (int) config('meetings.recommendation_overdue_grace_days', 0),
        ];
    }

    /**
     * إعدادات المؤسسة (singleton لكل org) — تُنشأ بالافتراضيات إن لم توجد.
     */
    public static function forOrganization(?int $organizationId): self
    {
        $cached = Cache::get(self::cacheKey($organizationId));
        if ($cached instanceof self) {
            return $cached;
        }

        $settings = static::firstOrCreate(
            ['organization_id' => $organizationId],
            self::defaults($organizationId),
        );

        Cache::put(self::cacheKey($organizationId), $settings, 3600);

        return $settings;
    }

    public static function clearCache(?int $organizationId): void
    {
        Cache::forget(self::cacheKey($organizationId));
    }

    protected static function booted(): void
    {
        static::saved(fn (self $m) => self::clearCache($m->organization_id));
        static::deleted(fn (self $m) => self::clearCache($m->organization_id));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(MeetingCategory::class, 'default_category_id');
    }
}
