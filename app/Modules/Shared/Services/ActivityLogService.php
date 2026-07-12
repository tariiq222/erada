<?php

namespace App\Modules\Shared\Services;

use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ActivityLogService - خدمة تسجيل الأنشطة
 *
 * DI friendly - لا تستخدم request() مباشرة
 * يتم تمرير ip و userAgent كـ parameters
 *
 * مستخرجة من ActivityLog model لتحسين testability وSRP
 */
class ActivityLogService
{
    /**
     * Cache للأعمدة الموجودة
     */
    protected static ?array $existingColumns = null;

    // ========== أحداث المصادقة ==========

    /**
     * تسجيل حدث دخول ناجح
     */
    public function logLogin(User $user, ?string $ip = null, ?string $userAgent = null): ?ActivityLog
    {
        return $this->createLog([
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_LOGIN,
            'description' => 'تسجيل دخول ناجح',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل حدث خروج
     */
    public function logLogout(User $user, ?string $ip = null, ?string $userAgent = null): ?ActivityLog
    {
        return $this->createLog([
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_LOGOUT,
            'description' => 'تسجيل خروج',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل محاولة دخول فاشلة
     */
    public function logFailedLogin(string $email, ?string $ip = null, ?string $userAgent = null): ?ActivityLog
    {
        return $this->createLog([
            'user_id' => null,
            'action' => ActivityLog::ACTION_LOGIN_FAILED,
            'description' => 'محاولة دخول فاشلة',
            'loggable_type' => User::class,
            'loggable_id' => null,
            'metadata' => ['email' => $email],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل تغيير كلمة المرور
     */
    public function logPasswordChange(User $user, ?string $ip = null, ?string $userAgent = null): ?ActivityLog
    {
        return $this->createLog([
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_PASSWORD_CHANGED,
            'description' => 'تغيير كلمة المرور',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل إعداد الحساب
     */
    public function logAccountSetup(User $user, ?string $ip = null, ?string $userAgent = null): ?ActivityLog
    {
        return $this->createLog([
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_ACCOUNT_SETUP,
            'description' => 'إعداد الحساب الأولي',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    // ========== أحداث الأدوار والصلاحيات ==========

    /**
     * تسجيل تعيين دور سياقي
     */
    public function logRoleAssigned(
        int $targetUserId,
        string $role,
        string $scopeType,
        int $scopeId,
        ?int $actorId = null,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        return $this->createLog([
            'user_id' => $actorId,
            'action' => ActivityLog::ACTION_ROLE_ASSIGNED,
            'description' => "تعيين دور {$role}",
            'loggable_type' => User::class,
            'loggable_id' => $targetUserId,
            'target_user_id' => $targetUserId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'role' => $role,
            'new_values' => ['role' => $role],
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل إزالة دور سياقي
     */
    public function logRoleRevoked(
        int $targetUserId,
        string $role,
        string $scopeType,
        int $scopeId,
        ?int $actorId = null,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        return $this->createLog([
            'user_id' => $actorId,
            'action' => ActivityLog::ACTION_ROLE_REVOKED,
            'description' => "إزالة دور {$role}",
            'loggable_type' => User::class,
            'loggable_id' => $targetUserId,
            'target_user_id' => $targetUserId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'role' => $role,
            'old_values' => ['role' => $role],
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل تعيين دور في نظام التفويض
     */
    public function logSystemRoleAssigned(
        int $targetUserId,
        string|array $roles,
        ?int $actorId = null,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        $roles = is_array($roles) ? $roles : [$roles];
        $rolesStr = implode(', ', $roles);

        return $this->createLog([
            'user_id' => $actorId,
            'action' => ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED,
            'description' => "تعيين دور نظام: {$rolesStr}",
            'loggable_type' => User::class,
            'loggable_id' => $targetUserId,
            'target_user_id' => $targetUserId,
            'role' => $rolesStr,
            'new_values' => ['roles' => $roles],
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل إزالة دور نظام
     */
    public function logSystemRoleRevoked(
        int $targetUserId,
        string|array $roles,
        ?int $actorId = null,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        $roles = is_array($roles) ? $roles : [$roles];
        $rolesStr = implode(', ', $roles);

        return $this->createLog([
            'user_id' => $actorId,
            'action' => ActivityLog::ACTION_SYSTEM_ROLE_REVOKED,
            'description' => "إزالة دور نظام: {$rolesStr}",
            'loggable_type' => User::class,
            'loggable_id' => $targetUserId,
            'target_user_id' => $targetUserId,
            'role' => $rolesStr,
            'old_values' => ['roles' => $roles],
            'reason' => $reason,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل رفض الوصول
     */
    public function logAccessDenied(
        int $userId,
        string $action,
        ?string $scopeType = null,
        ?int $scopeId = null,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        return $this->createLog([
            'user_id' => $userId,
            'action' => ActivityLog::ACTION_ACCESS_DENIED,
            'description' => "محاولة وصول غير مصرح: {$action}",
            'loggable_type' => User::class,
            'loggable_id' => $userId,
            'target_user_id' => $userId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'role' => $action,
            'reason' => $reason ?? 'محاولة وصول غير مصرح بها',
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    // ========== أحداث CRUD العامة ==========

    /**
     * تسجيل حدث إنشاء
     */
    public function logCreated(
        Model $model,
        ?int $userId = null,
        ?string $description = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        return $this->createLog([
            'user_id' => $userId,
            'action' => ActivityLog::ACTION_CREATED,
            'description' => $description ?? 'إنشاء عنصر جديد',
            'loggable_type' => get_class($model),
            'loggable_id' => $model->getKey(),
            'new_values' => $model->toArray(),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل حدث تحديث
     */
    public function logUpdated(
        Model $model,
        array $oldValues,
        ?int $userId = null,
        ?string $description = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        $changes = $model->getChanges();

        return $this->createLog([
            'user_id' => $userId,
            'action' => ActivityLog::ACTION_UPDATED,
            'description' => $description ?? 'تحديث عنصر',
            'loggable_type' => get_class($model),
            'loggable_id' => $model->getKey(),
            'old_values' => array_intersect_key($oldValues, $changes),
            'new_values' => $changes,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * تسجيل حدث حذف
     */
    public function logDeleted(
        Model $model,
        ?int $userId = null,
        ?string $description = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): ?ActivityLog {
        return $this->createLog([
            'user_id' => $userId,
            'action' => ActivityLog::ACTION_DELETED,
            'description' => $description ?? 'حذف عنصر',
            'loggable_type' => get_class($model),
            'loggable_id' => $model->getKey(),
            'old_values' => $model->toArray(),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    // ========== Helpers ==========

    /**
     * إنشاء سجل مع معالجة الأخطاء
     */
    protected function createLog(array $data): ?ActivityLog
    {
        try {
            return ActivityLog::create($this->buildLogData($data));
        } catch (\Exception $e) {
            Log::warning('Failed to create activity log: '.$e->getMessage(), [
                'action' => $data['action'] ?? 'unknown',
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * بناء بيانات السجل مع فلترة الأعمدة غير الموجودة
     */
    protected function buildLogData(array $data): array
    {
        $baseColumns = [
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
            'target_user_id',
            'scope_type',
            'scope_id',
            'role',
            'reason',
            'organization_id',
        ];

        if (static::$existingColumns === null) {
            try {
                static::$existingColumns = Schema::getColumnListing('activity_logs');
            } catch (\Exception $e) {
                static::$existingColumns = $baseColumns;
            }
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $baseColumns) || in_array($key, static::$existingColumns)) {
                $result[$key] = $value;
            }
        }

        // اشتقاق organization_id تلقائياً عندما لا يمرّره المستدعي صراحة.
        // الـ creating observer على الـ Model يضمن أن السجل المُنشأ يحمل org حتى لو
        // استدعى مسارٌ آخر ActivityLog::create() مباشرة دون المرور بـ createLog().
        if (! array_key_exists('organization_id', $result) || $result['organization_id'] === null) {
            $resolver = app(ActivityLogOrganizationResolver::class);
            $resolved = $resolver->resolve($result);
            if ($resolved !== null) {
                $result['organization_id'] = $resolved;
            }
        }

        return $result;
    }
}
