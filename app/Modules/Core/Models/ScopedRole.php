<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ScopedRole - نموذج الأدوار السياقية
 *
 * يربط المستخدم بدور معين في سياق محدد (مؤسسة، قسم، مشروع)
 * يدعم النظام الديناميكي الجديد مع التوافق مع الكود القديم
 */
class ScopedRole extends Model
{
    protected $table = 'model_has_scoped_roles';

    protected $fillable = [
        'user_id',
        'role',
        'role_definition_id',
        'scope_type',
        'scope_id',
        'inherit_to_children',
        'granted_by',
        'source',
        'expires_at',
    ];

    protected $casts = [
        'inherit_to_children' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * Keep the AccessDecision request-memoization honest: any per-model write to a
     * scoped role (create / update / delete via the model) drops the affected
     * user's cached roles so the next decision re-reads them. Mass query-builder
     * deletes bypass these events; their call sites flush explicitly (see
     * HasScopedRoles mutators, DepartmentObserver, project services).
     */
    protected static function booted(): void
    {
        $flush = function (self $role): void {
            if ($role->user_id !== null) {
                AccessDecision::flushUserCache((int) $role->user_id);
            } else {
                AccessDecision::flushCache();
            }
        };

        static::saved($flush);
        static::deleted($flush);
    }

    // ========== أنواع السياقات ==========

    const SCOPE_ORGANIZATION = 'organization';

    const SCOPE_DEPARTMENT = 'department';

    const SCOPE_PROJECT = 'project';

    // ========== أدوار المشاريع (موحّدة: مدير/عضو/مشاهد) ==========

    const PROJECT_MANAGER = 'manager';

    const PROJECT_MEMBER = 'member';

    const PROJECT_VIEWER = 'viewer';

    // ========== أدوار الأقسام ==========

    const DEPARTMENT_MANAGER = 'department_manager';

    const DEPARTMENT_SUPERVISOR = 'department_supervisor';

    const DEPARTMENT_MEMBER = 'department_member';

    // ========== العلاقات ==========

    /**
     * المستخدم صاحب الدور
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * من أعطى هذا الدور
     */
    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * السياق (Polymorphic)
     */
    public function scope(): MorphTo
    {
        return $this->morphTo('scope', 'scope_type', 'scope_id');
    }

    /**
     * تعريف الدور (للنظام الديناميكي)
     */
    public function roleDefinition(): BelongsTo
    {
        return $this->belongsTo(ScopedRoleDefinition::class, 'role_definition_id');
    }

    /**
     * نوع السياق (للنظام الديناميكي)
     */
    public function scopeTypeModel(): BelongsTo
    {
        return $this->belongsTo(ScopeType::class, 'scope_type', 'key');
    }

    // ========== Scopes ==========

    /**
     * فلترة حسب نوع السياق
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('scope_type', $type);
    }

    /**
     * فلترة حسب الدور
     */
    public function scopeWithRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * فلترة الأدوار السارية (غير منتهية)
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * فلترة حسب المستخدم
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * فلترة حسب السياق
     */
    public function scopeInScope($query, string $scopeType, int $scopeId)
    {
        return $query->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId);
    }

    /**
     * Filter to automatically granted rows (department capacity automation).
     */
    public function scopeAuto($query)
    {
        return $query->where('source', 'auto');
    }

    /**
     * Filter to manually granted rows (admin delegation).
     */
    public function scopeManual($query)
    {
        return $query->where('source', 'manual');
    }

    // ========== Helpers ==========

    /**
     * هل الدور منتهي؟
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * هل الدور ساري؟
     */
    public function isActive(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * الحصول على اسم الدور للعرض
     */
    public function getDisplayNameAttribute(): string
    {
        // أولاً: حاول من التعريف الديناميكي
        if ($this->roleDefinition) {
            return $this->roleDefinition->getLabel();
        }

        // ثانياً: من قاعدة البيانات
        $dynamicName = ScopedRoleDefinition::getDisplayName($this->scope_type, $this->role);
        if ($dynamicName !== $this->role) {
            return $dynamicName;
        }

        // أخيراً: fallback للثوابت القديمة
        return self::getRoleDisplayName($this->role);
    }

    /**
     * أسماء الأدوار للعرض (مع دعم ديناميكي)
     */
    public static function getRoleDisplayName(string $role, ?string $scopeType = null): string
    {
        // حاول من قاعدة البيانات أولاً
        if ($scopeType) {
            $dynamicName = ScopedRoleDefinition::getDisplayName($scopeType, $role);
            if ($dynamicName !== $role) {
                return $dynamicName;
            }
        }

        // fallback للثوابت القديمة
        return match ($role) {
            // أدوار المشاريع (موحّدة)
            self::PROJECT_MANAGER => 'مدير المشروع',
            self::PROJECT_MEMBER => 'عضو',
            self::PROJECT_VIEWER => 'مشاهد',

            // أدوار الأقسام
            self::DEPARTMENT_MANAGER => 'مدير القسم',
            self::DEPARTMENT_SUPERVISOR => 'مشرف القسم',
            self::DEPARTMENT_MEMBER => 'عضو القسم',

            default => $role,
        };
    }

    /**
     * الحصول على جميع أدوار المشاريع (ديناميكي مع fallback)
     */
    public static function getProjectRoles(): array
    {
        // حاول من قاعدة البيانات أولاً
        $dynamicRoles = ScopedRoleDefinition::getRolesForType(self::SCOPE_PROJECT);
        if (! empty($dynamicRoles)) {
            return $dynamicRoles;
        }

        // fallback للثوابت الموحّدة
        return [
            self::PROJECT_MANAGER => 'مدير المشروع',
            self::PROJECT_MEMBER => 'عضو',
            self::PROJECT_VIEWER => 'مشاهد',
        ];
    }

    /**
     * الحصول على جميع أدوار الأقسام (ديناميكي مع fallback)
     */
    public static function getDepartmentRoles(): array
    {
        // حاول من قاعدة البيانات أولاً
        $dynamicRoles = ScopedRoleDefinition::getRolesForType(self::SCOPE_DEPARTMENT);
        if (! empty($dynamicRoles)) {
            return $dynamicRoles;
        }

        // fallback للثوابت القديمة
        return [
            self::DEPARTMENT_MANAGER => 'مدير القسم',
            self::DEPARTMENT_SUPERVISOR => 'مشرف القسم',
            self::DEPARTMENT_MEMBER => 'عضو القسم',
        ];
    }

    /**
     * الحصول على أدوار نوع سياق معين (ديناميكي بالكامل)
     */
    public static function getRolesForScopeType(string $scopeType): array
    {
        // حاول من قاعدة البيانات أولاً
        $dynamicRoles = ScopedRoleDefinition::getRolesForType($scopeType);
        if (! empty($dynamicRoles)) {
            return $dynamicRoles;
        }

        // fallback للأنواع المعروفة
        return match ($scopeType) {
            self::SCOPE_PROJECT => self::getProjectRoles(),
            self::SCOPE_DEPARTMENT => self::getDepartmentRoles(),
            default => [],
        };
    }

    /**
     * هل هذا دور إداري في المشروع؟ (ديناميكي مع fallback)
     */
    public static function isProjectAdminRole(string $role): bool
    {
        // حاول من قاعدة البيانات أولاً
        if (ScopedRoleDefinition::isAdminRole(self::SCOPE_PROJECT, $role)) {
            return true;
        }

        // fallback: المدير فقط إداري
        return in_array($role, [
            self::PROJECT_MANAGER,
        ]);
    }

    /**
     * هل هذا دور إداري في القسم؟ (ديناميكي مع fallback)
     */
    public static function isDepartmentAdminRole(string $role): bool
    {
        // حاول من قاعدة البيانات أولاً
        if (ScopedRoleDefinition::isAdminRole(self::SCOPE_DEPARTMENT, $role)) {
            return true;
        }

        // fallback للثوابت القديمة
        return in_array($role, [
            self::DEPARTMENT_MANAGER,
            self::DEPARTMENT_SUPERVISOR,
        ]);
    }

    /**
     * هل هذا دور إداري (عام - لأي نوع سياق)
     */
    public static function isAdminRole(string $scopeType, string $role): bool
    {
        // حاول من قاعدة البيانات أولاً
        if (ScopedRoleDefinition::isAdminRole($scopeType, $role)) {
            return true;
        }

        // fallback للأنواع المعروفة
        return match ($scopeType) {
            self::SCOPE_PROJECT => self::isProjectAdminRole($role),
            self::SCOPE_DEPARTMENT => self::isDepartmentAdminRole($role),
            default => false,
        };
    }

    /**
     * الحصول على جميع أنواع السياقات النشطة
     */
    public static function getActiveScopeTypes(): array
    {
        $types = ScopeType::getAllActive();

        if ($types->isEmpty()) {
            // fallback للثوابت القديمة
            return [
                self::SCOPE_DEPARTMENT => 'القسم',
                self::SCOPE_PROJECT => 'المشروع',
            ];
        }

        return $types->pluck('label_ar', 'key')->toArray();
    }

    /**
     * الحصول على تعريف الدور
     */
    public function getDefinition(): ?ScopedRoleDefinition
    {
        if ($this->roleDefinition) {
            return $this->roleDefinition;
        }

        return ScopedRoleDefinition::findByKey($this->scope_type, $this->role);
    }
}
