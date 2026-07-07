<?php

namespace App\Modules\Core\Models;

use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * إعدادات النظام - Singleton Pattern
 * يحتوي على البيانات العامة للنظام والمستشفى
 */
class SystemSettings extends Model
{
    protected $table = 'system_settings';

    /**
     * Cache key - فريد لتجنب التعارض مع ProjectSettingsService
     */
    public const CACHE_KEY = 'system_settings:model';

    public const CACHE_TTL = 3600;

    /**
     * Cache tag — lets clearCache() drop just this model's cache without
     * nuking unrelated keys (the old forget(self::CACHE_KEY) was already
     * scoped, but the tag lets us extend the pattern if more keys are added).
     */
    public const CACHE_TAG = 'system_settings';

    protected $fillable = [
        'name',
        'name_en',
        'code',
        'logo',
        'region',
        'city',
        'address',
        'phone',
        'email',
        'website',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * الحصول على إعدادات النظام (Singleton Pattern)
     */
    public static function get(): self
    {
        // محاولة الحصول من الكاش أولاً
        try {
            $cached = Cache::tags([self::CACHE_TAG])->get(self::CACHE_KEY);
            if ($cached instanceof self) {
                return $cached;
            }
        } catch (\Exception $e) {
            Log::warning('SystemSettings cache read failed', ['error' => $e->getMessage()]);

            // Tagged cache not supported by the current driver — try untagged.
            try {
                $cached = Cache::get(self::CACHE_KEY);
                if ($cached instanceof self) {
                    return $cached;
                }
            } catch (\Exception $ignored) {
                // Ignore; fall through to DB.
            }
        }

        // الحصول من قاعدة البيانات
        $settings = static::first() ?? static::create([
            'name' => 'نظام إدارة المشاريع',
            'name_en' => 'Project Management System',
        ]);

        // محاولة حفظ في الكاش
        try {
            Cache::tags([self::CACHE_TAG])->put(self::CACHE_KEY, $settings, self::CACHE_TTL);
        } catch (\Exception $e) {
            Log::warning('SystemSettings cache write failed', ['error' => $e->getMessage()]);

            // Tagged cache not supported by the current driver — try untagged.
            try {
                Cache::put(self::CACHE_KEY, $settings, self::CACHE_TTL);
            } catch (\Exception $ignored) {
                // Last-ditch: ignore.
            }
        }

        return $settings;
    }

    /**
     * تحديث إعدادات النظام
     */
    public static function updateSettings(array $data): self
    {
        $settings = static::get();
        $settings->update($data);

        static::clearCache();

        return $settings->fresh();
    }

    /**
     * مسح الكاش
     */
    public static function clearCache(): void
    {
        try {
            Cache::tags([self::CACHE_TAG])->forget(self::CACHE_KEY);
        } catch (\Exception $e) {
            // Tagged cache not supported by the current driver — fall back to
            // plain Cache::forget so the singleton can still be invalidated.
            try {
                Cache::forget(self::CACHE_KEY);
            } catch (\Exception $ignored) {
                Log::warning('SystemSettings cache forget failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * الحصول على قيمة إعداد معين
     */
    public static function getValue(string $key, $default = null)
    {
        $settings = static::get();

        // أولاً نبحث في الحقول المباشرة
        if (isset($settings->$key)) {
            return $settings->$key;
        }

        // ثم في حقل settings JSON
        if ($settings->settings && isset($settings->settings[$key])) {
            return $settings->settings[$key];
        }

        return $default;
    }

    /**
     * تعيين قيمة إعداد في حقل settings JSON
     */
    public static function setValue(string $key, $value): void
    {
        $settings = static::get();
        $currentSettings = $settings->settings ?? [];
        $currentSettings[$key] = $value;

        $settings->update(['settings' => $currentSettings]);
        static::clearCache();
    }

    /**
     * إحصائيات النظام
     */
    public function getStatistics(): array
    {
        return [
            'departments_count' => Department::count(),
            'users_count' => User::count(),
            'active_projects_count' => Project::where('status', 'in_progress')->count(),
            'total_projects_count' => Project::count(),
        ];
    }
}
