<?php

namespace App\Modules\Shared\Models;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Formatters\ActivityLogFormatter;
use App\Modules\Shared\Services\ActivityLogOrganizationResolver;
use App\Modules\Shared\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    /**
     * تحكم في تعبئة organization_id تلقائياً عبر الـ resolver في حدث creating.
     * فعّل/عطّل في setUp الاختبارات.
     */
    public static bool $fillOrganization = true;

    // أنواع الأحداث - CRUD
    const ACTION_CREATED = 'created';

    const ACTION_UPDATED = 'updated';

    const ACTION_DELETED = 'deleted';

    const ACTION_RESTORED = 'restored';

    // أنواع الأحداث - المصادقة
    const ACTION_LOGIN = 'login';

    const ACTION_LOGOUT = 'logout';

    const ACTION_LOGIN_FAILED = 'login_failed';

    const ACTION_PASSWORD_CHANGED = 'password_changed';

    const ACTION_ACCOUNT_SETUP = 'account_setup';

    // أنواع الأحداث - الصلاحيات والأدوار
    const ACTION_ROLE_ASSIGNED = 'role_assigned';

    const ACTION_ROLE_REVOKED = 'role_revoked';

    const ACTION_ROLE_UPDATED = 'role_updated';

    const ACTION_PERMISSION_GRANTED = 'permission_granted';

    const ACTION_PERMISSION_REVOKED = 'permission_revoked';

    const ACTION_SYSTEM_ROLE_ASSIGNED = 'system_role_assigned';

    const ACTION_SYSTEM_ROLE_REVOKED = 'system_role_revoked';

    const ACTION_ACCESS_DENIED = 'access_denied';

    protected $fillable = [
        'user_id',
        'action',
        'description',
        'loggable_type',
        'loggable_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        // حقول الصلاحيات (من PermissionAudit سابقاً)
        'target_user_id',
        'scope_type',
        'scope_id',
        'role',
        'reason',
        'organization_id',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];

    // Labels و colors موجودة الآن في ActivityLogFormatter
    // @see App\Modules\Shared\Formatters\ActivityLogFormatter

    /**
     * Boot — يفعّل تعبئة organization_id تلقائياً عبر Resolver.
     */
    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            if (! static::$fillOrganization) {
                return;
            }
            if ($log->organization_id !== null) {
                return;
            }
            $resolver = app(ActivityLogOrganizationResolver::class);
            $log->organization_id = $resolver->resolve($log->getAttributes());
        });
    }

    // ========== العلاقات ==========

    /**
     * المستخدم الذي قام بالعملية
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * العنصر المرتبط
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * المؤسسة المالكة للسجل (nullable للسجلات النظامية cross-org).
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ========== Scopes ==========

    /**
     * فلترة حسب نوع الحدث
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * فلترة حسب نوع الكيان
     */
    public function scopeForModel($query, string $modelClass)
    {
        return $query->where('loggable_type', $modelClass);
    }

    /**
     * فلترة حسب المستخدم
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * فلترة أحداث المصادقة فقط
     */
    public function scopeAuthEvents($query)
    {
        return $query->whereIn('action', [
            self::ACTION_LOGIN,
            self::ACTION_LOGOUT,
            self::ACTION_LOGIN_FAILED,
            self::ACTION_PASSWORD_CHANGED,
            self::ACTION_ACCOUNT_SETUP,
        ]);
    }

    // ========== Helpers ==========

    /**
     * الحصول على اسم الحدث بالعربية
     * يستخدم ActivityLogFormatter
     */
    public function getActionLabelAttribute(): string
    {
        return app(ActivityLogFormatter::class)
            ->getActionLabel($this->action);
    }

    /**
     * الحصول على اسم نوع الكيان بالعربية
     * يستخدم ActivityLogFormatter
     */
    public function getModelLabelAttribute(): string
    {
        return app(ActivityLogFormatter::class)
            ->getModelLabel($this->loggable_type ?? '');
    }

    /**
     * الحصول على لون الحدث للعرض
     * يستخدم ActivityLogFormatter
     */
    public function getActionColorAttribute(): string
    {
        return app(ActivityLogFormatter::class)
            ->getActionColor($this->action);
    }

    // ========== دوال التسجيل ==========
    // تم نقلها إلى ActivityLogService (DI friendly)
    // @see App\Modules\Shared\Services\ActivityLogService
    //
    // للتوافق مع الكود القديم، الدوال التالية تفوض للـ Service:

    /**
     * @deprecated استخدم ActivityLogService::logLogin() بدلاً
     */
    public static function logLogin(User $user, ?string $ip = null, ?string $userAgent = null): ?self
    {
        return app(ActivityLogService::class)
            ->logLogin($user, $ip ?? request()->ip(), $userAgent ?? request()->userAgent());
    }

    /**
     * @deprecated استخدم ActivityLogService::logLogout() بدلاً
     */
    public static function logLogout(User $user, ?string $ip = null, ?string $userAgent = null): ?self
    {
        return app(ActivityLogService::class)
            ->logLogout($user, $ip ?? request()->ip(), $userAgent ?? request()->userAgent());
    }

    /**
     * @deprecated استخدم ActivityLogService::logFailedLogin() بدلاً
     */
    public static function logFailedLogin(string $email, ?string $ip = null, ?string $userAgent = null): ?self
    {
        return app(ActivityLogService::class)
            ->logFailedLogin($email, $ip ?? request()->ip(), $userAgent ?? request()->userAgent());
    }

    /**
     * @deprecated استخدم ActivityLogService::logPasswordChange() بدلاً
     */
    public static function logPasswordChange(User $user, ?string $ip = null, ?string $userAgent = null): ?self
    {
        return app(ActivityLogService::class)
            ->logPasswordChange($user, $ip ?? request()->ip(), $userAgent ?? request()->userAgent());
    }

    /**
     * @deprecated استخدم ActivityLogService::logAccountSetup() بدلاً
     */
    public static function logAccountSetup(User $user, ?string $ip = null, ?string $userAgent = null): ?self
    {
        return app(ActivityLogService::class)
            ->logAccountSetup($user, $ip ?? request()->ip(), $userAgent ?? request()->userAgent());
    }

    /**
     * الحصول على جميع تسميات الأحداث
     */
    public static function getActionLabels(): array
    {
        return app(ActivityLogFormatter::class)
            ->getAllActionLabels();
    }

    /**
     * الحصول على جميع تسميات الكيانات
     */
    public static function getModelLabels(): array
    {
        return app(ActivityLogFormatter::class)
            ->getAllModelLabels();
    }

    /**
     * @deprecated استخدم ActivityLogService::logRoleAssigned() بدلاً
     */
    public static function logRoleAssigned(
        int $targetUserId,
        string $role,
        string $scopeType,
        int $scopeId,
        ?int $actorId = null,
        ?string $reason = null
    ): ?self {
        return app(ActivityLogService::class)
            ->logRoleAssigned(
                $targetUserId, $role, $scopeType, $scopeId,
                $actorId ?? auth()->id(), $reason,
                request()->ip(), request()->userAgent()
            );
    }

    /**
     * @deprecated استخدم ActivityLogService::logRoleRevoked() بدلاً
     */
    public static function logRoleRevoked(
        int $targetUserId,
        string $role,
        string $scopeType,
        int $scopeId,
        ?int $actorId = null,
        ?string $reason = null
    ): ?self {
        return app(ActivityLogService::class)
            ->logRoleRevoked(
                $targetUserId, $role, $scopeType, $scopeId,
                $actorId ?? auth()->id(), $reason,
                request()->ip(), request()->userAgent()
            );
    }

    /**
     * @deprecated استخدم ActivityLogService::logSystemRoleAssigned() بدلاً
     */
    public static function logSystemRoleAssigned(
        int $targetUserId,
        string|array $roles,
        ?int $actorId = null,
        ?string $reason = null
    ): ?self {
        return app(ActivityLogService::class)
            ->logSystemRoleAssigned(
                $targetUserId, $roles,
                $actorId ?? auth()->id(), $reason,
                request()->ip(), request()->userAgent()
            );
    }

    /**
     * @deprecated استخدم ActivityLogService::logSystemRoleRevoked() بدلاً
     */
    public static function logSystemRoleRevoked(
        int $targetUserId,
        string|array $roles,
        ?int $actorId = null,
        ?string $reason = null
    ): ?self {
        return app(ActivityLogService::class)
            ->logSystemRoleRevoked(
                $targetUserId, $roles,
                $actorId ?? auth()->id(), $reason,
                request()->ip(), request()->userAgent()
            );
    }

    /**
     * @deprecated استخدم ActivityLogService::logAccessDenied() بدلاً
     */
    public static function logAccessDenied(
        int $userId,
        string $action,
        ?string $scopeType = null,
        ?int $scopeId = null,
        ?string $reason = null
    ): ?self {
        return app(ActivityLogService::class)
            ->logAccessDenied(
                $userId, $action, $scopeType, $scopeId, $reason,
                request()->ip(), request()->userAgent()
            );
    }

    // ========== Scopes إضافية للصلاحيات ==========

    /**
     * فلترة أحداث الصلاحيات فقط
     */
    public function scopePermissionEvents($query)
    {
        return $query->whereIn('action', [
            self::ACTION_ROLE_ASSIGNED,
            self::ACTION_ROLE_REVOKED,
            self::ACTION_ROLE_UPDATED,
            self::ACTION_PERMISSION_GRANTED,
            self::ACTION_PERMISSION_REVOKED,
            self::ACTION_SYSTEM_ROLE_ASSIGNED,
            self::ACTION_SYSTEM_ROLE_REVOKED,
            self::ACTION_ACCESS_DENIED,
        ]);
    }

    /**
     * فلترة حسب نوع السياق
     */
    public function scopeInScope($query, string $scopeType, ?int $scopeId = null)
    {
        $query->where('scope_type', $scopeType);
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }

        return $query;
    }

    /**
     * فلترة حسب المستخدم المتأثر
     */
    public function scopeForTargetUser($query, int $userId)
    {
        return $query->where('target_user_id', $userId);
    }

    /**
     * فلترة حسب organization_id (يُعيد null-org events عند تمرير null).
     */
    public function scopeForOrganization($query, ?int $organizationId)
    {
        if ($organizationId === null) {
            return $query->whereNull('organization_id');
        }

        return $query->where('organization_id', $organizationId);
    }

    // ========== علاقات إضافية ==========

    /**
     * المستخدم المتأثر (في أحداث الصلاحيات)
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
