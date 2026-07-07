<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Authorization\AccessDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * ScopedRoleDefinition - نموذج تعريفات الأدوار السياقية
 *
 * يحدد الأدوار المتاحة لكل نوع سياق مع صلاحياتها
 */
class ScopedRoleDefinition extends Model
{
    /**
     * Cache tag — all entries written by this model share this tag so
     * clearCache() can drop just this model's cache without nuking unrelated keys.
     */
    public const CACHE_TAG = 'scoped_role_definitions';

    protected $fillable = [
        'scope_type_id',
        'role_key',
        'label_ar',
        'label_en',
        'description',
        'color',
        'permissions',
        'reach',
        'is_admin_role',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'permissions' => 'array',
        'reach' => 'array',
        'is_admin_role' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ========== العلاقات ==========

    /**
     * نوع السياق
     */
    public function scopeType(): BelongsTo
    {
        return $this->belongsTo(ScopeType::class);
    }

    /**
     * الأدوار المعينة بهذا التعريف
     */
    public function scopedRoles(): HasMany
    {
        return $this->hasMany(ScopedRole::class, 'role_definition_id');
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label_ar');
    }

    public function scopeAdminRoles($query)
    {
        return $query->where('is_admin_role', true);
    }

    public function scopeForScopeType($query, string $typeKey)
    {
        return $query->whereHas('scopeType', fn ($q) => $q->where('key', $typeKey));
    }

    // ========== Helpers ==========

    /**
     * الحصول على التسمية حسب اللغة
     */
    public function getLabel(): string
    {
        $locale = app()->getLocale();

        return $locale === 'ar' ? $this->label_ar : ($this->label_en ?? $this->label_ar);
    }

    /**
     * The reach cap this definition places on a module: own | department | all.
     * Defaults to 'all' when unset — so a definition with no `reach` keeps the
     * pre-Phase-6 org-wide behavior (least-privilege is opt-in per module).
     */
    public function reachForModule(string $module): string
    {
        $reach = $this->reach;
        if (! is_array($reach)) {
            return 'all';
        }

        $value = $reach[$module] ?? 'all';

        return in_array($value, ['own', 'department', 'all'], true) ? $value : 'all';
    }

    /**
     * هل لديه صلاحية معينة؟
     */
    public function hasPermission(string $permission): bool
    {
        if (! $this->permissions) {
            return false;
        }

        return in_array($permission, $this->permissions);
    }

    /**
     * الحصول على الصلاحيات كقائمة
     */
    public function getPermissionsList(): array
    {
        return $this->permissions ?? [];
    }

    // ========== Static Helpers ==========

    /**
     * الحصول على تعريف بواسطة المفتاح ونوع السياق
     */
    public static function findByKey(string $scopeTypeKey, string $roleKey): ?static
    {
        try {
            $cacheKey = "role_def_{$scopeTypeKey}_{$roleKey}";

            return Cache::tags([self::CACHE_TAG])->remember($cacheKey, 3600, function () use ($scopeTypeKey, $roleKey) {
                return static::forScopeType($scopeTypeKey)
                    ->where('role_key', $roleKey)
                    ->first();
            });
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * الحصول على جميع الأدوار لنوع سياق
     */
    public static function getRolesForType(string $scopeTypeKey): array
    {
        try {
            $cacheKey = "roles_for_type_{$scopeTypeKey}";

            return Cache::tags([self::CACHE_TAG])->remember($cacheKey, 3600, function () use ($scopeTypeKey) {
                return static::forScopeType($scopeTypeKey)
                    ->active()
                    ->ordered()
                    ->pluck('label_ar', 'role_key')
                    ->toArray();
            });
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * الحصول على أدوار الإدارة لنوع سياق
     */
    public static function getAdminRolesForType(string $scopeTypeKey): array
    {
        try {
            return static::forScopeType($scopeTypeKey)
                ->active()
                ->adminRoles()
                ->pluck('role_key')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * التحقق من أن دور معين إداري
     */
    public static function isAdminRole(string $scopeTypeKey, string $roleKey): bool
    {
        $definition = static::findByKey($scopeTypeKey, $roleKey);

        return $definition?->is_admin_role ?? false;
    }

    /**
     * الحصول على اسم العرض للدور
     */
    public static function getDisplayName(string $scopeTypeKey, string $roleKey): string
    {
        $definition = static::findByKey($scopeTypeKey, $roleKey);

        return $definition?->getLabel() ?? $roleKey;
    }

    /**
     * الحصول على لون الدور
     */
    public static function getColor(string $scopeTypeKey, string $roleKey): string
    {
        $definition = static::findByKey($scopeTypeKey, $roleKey);

        return $definition?->color ?? 'primary';
    }

    /**
     * مسح الـ cache
     */
    public static function clearCache(): void
    {
        try {
            Cache::tags([self::CACHE_TAG])->flush();
        } catch (\Exception $e) {
            // Tagged cache not supported by the current driver — fall back to
            // forgetting the specific keys we own.
            try {
                Cache::forget('scope_types_active');
            } catch (\Exception $ignored) {
                // Last-ditch: ignore; the engine flush below still runs.
            }
        }

        // A definition's permissions/flags feed every can() decision; drop the
        // engine's request memoization so the next decision re-reads them.
        AccessDecision::flushCache();
    }

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function () {
            static::clearCache();
            ScopeType::clearCache();
        });

        static::deleted(function () {
            static::clearCache();
            ScopeType::clearCache();
        });
    }
}
