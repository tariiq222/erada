<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * ScopeType - نموذج أنواع السياقات
 *
 * يحدد أنواع السياقات المتاحة في النظام (قسم، مشروع، عقد، إلخ)
 */
class ScopeType extends Model
{
    /**
     * Cache tag — all entries written by this model share this tag so
     * clearCache() can drop just this model's cache without nuking unrelated keys.
     */
    public const CACHE_TAG = 'scope_types';

    /**
     * التحقق من وجود الجدول
     */
    public static function tableExists(): bool
    {
        static $exists = null;
        if ($exists === null) {
            try {
                $exists = Schema::hasTable('scope_types');
            } catch (\Exception $e) {
                $exists = false;
            }
        }

        return $exists;
    }

    protected $fillable = [
        'key',
        'label_ar',
        'label_en',
        'model_class',
        'icon',
        'color',
        'supports_hierarchy',
        'supports_expiry',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'supports_hierarchy' => 'boolean',
        'supports_expiry' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ========== العلاقات ==========

    /**
     * تعريفات الأدوار لهذا النوع
     */
    public function roleDefinitions(): HasMany
    {
        return $this->hasMany(ScopedRoleDefinition::class)->orderBy('sort_order');
    }

    /**
     * تعريفات الأدوار النشطة
     */
    public function activeRoleDefinitions(): HasMany
    {
        return $this->roleDefinitions()->where('is_active', true);
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
     * الحصول على instance من الموديل
     */
    public function getModelInstance()
    {
        if (class_exists($this->model_class)) {
            return new $this->model_class;
        }

        return null;
    }

    /**
     * الحصول على خيارات للـ Select
     */
    public function getOptionsForSelect(): array
    {
        $model = $this->getModelInstance();
        if (! $model) {
            return [];
        }

        // تحقق من وجود scope نشط
        $query = $model->newQuery();
        if (method_exists($model, 'scopeActive')) {
            $query->active();
        }

        // استخدم الحقل المناسب للعرض
        $labelColumn = 'name';
        if ($model->getTable() === 'projects') {
            $labelColumn = 'name';
        }

        return $query->pluck($labelColumn, 'id')->toArray();
    }

    // ========== Static Helpers ==========

    /**
     * الحصول على جميع الأنواع النشطة (مع cache)
     */
    public static function getAllActive(): Collection
    {
        try {
            return Cache::tags([self::CACHE_TAG])->remember('scope_types_active', 3600, function () {
                return static::active()->ordered()->with('activeRoleDefinitions')->get();
            });
        } catch (\Exception $e) {
            return new Collection;
        }
    }

    /**
     * الحصول على نوع بواسطة المفتاح
     */
    public static function findByKey(string $key): ?static
    {
        try {
            return Cache::tags([self::CACHE_TAG])->remember("scope_type_{$key}", 3600, function () use ($key) {
                return static::where('key', $key)->first();
            });
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * الحصول على أدوار نوع معين
     */
    public static function getRolesForType(string $typeKey): array
    {
        try {
            $type = static::findByKey($typeKey);
            if (! $type) {
                return [];
            }

            return $type->activeRoleDefinitions()
                ->pluck('label_ar', 'role_key')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
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
                $types = static::all();
                foreach ($types as $type) {
                    Cache::forget("scope_type_{$type->key}");
                }
            } catch (\Exception $ignored) {
                // Last-ditch: ignore; cache will self-expire on TTL.
            }
        }
    }

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function () {
            static::clearCache();
        });

        static::deleted(function () {
            static::clearCache();
        });
    }
}
