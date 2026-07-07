<?php

namespace App\Modules\Projects\Services;

use App\Modules\Core\Models\SystemSettings;
use Illuminate\Support\Facades\Cache;

/**
 * خدمة إعدادات المشاريع
 * تقرأ الإعدادات من cluster_settings وتوفرها للنظام
 */
class ProjectSettingsService
{
    /**
     * مدة التخزين المؤقت (ساعة واحدة)
     */
    private const CACHE_TTL = 3600;

    /**
     * Cache keys - فريدة لتجنب التعارض مع SystemSettings Model
     */
    private const CACHE_KEY_PROJECT = 'settings:project';

    private const CACHE_KEY_SYSTEM = 'settings:system_config';

    /**
     * الإعدادات الافتراضية للمشاريع
     */
    private array $defaultProjectSettings = [
        'default_project_status' => 'planning',
        'max_attachments_size' => 10, // MB
        'allowed_file_types' => 'pdf,doc,docx,xls,xlsx,jpg,png,gif',
    ];

    /**
     * الإعدادات الافتراضية للنظام
     */
    private array $defaultSystemSettings = [
        'date_format' => 'DD/MM/YYYY',
        'time_format' => '24h',
        'timezone' => 'Asia/Riyadh',
        'default_language' => 'ar',
        'session_timeout' => 60,
        'enable_notifications' => true,
        'enable_email_notifications' => true,
        'maintenance_mode' => false,
    ];

    /**
     * الحصول على جميع إعدادات المشاريع
     */
    public function getProjectSettings(): array
    {
        return Cache::remember(self::CACHE_KEY_PROJECT, self::CACHE_TTL, function () {
            $cluster = SystemSettings::first();

            if (! $cluster || ! $cluster->settings || ! isset($cluster->settings['projects'])) {
                return $this->defaultProjectSettings;
            }

            return array_merge($this->defaultProjectSettings, $cluster->settings['projects']);
        });
    }

    /**
     * الحصول على جميع إعدادات النظام
     */
    public function getSystemSettings(): array
    {
        return Cache::remember(self::CACHE_KEY_SYSTEM, self::CACHE_TTL, function () {
            $cluster = SystemSettings::first();

            if (! $cluster || ! $cluster->settings || ! isset($cluster->settings['system'])) {
                return $this->defaultSystemSettings;
            }

            return array_merge($this->defaultSystemSettings, $cluster->settings['system']);
        });
    }

    /**
     * الحصول على إعداد محدد للمشاريع
     */
    public function getProjectSetting(string $key, $default = null)
    {
        $settings = $this->getProjectSettings();

        return $settings[$key] ?? $default ?? ($this->defaultProjectSettings[$key] ?? null);
    }

    /**
     * الحصول على إعداد محدد للنظام
     */
    public function getSystemSetting(string $key, $default = null)
    {
        $settings = $this->getSystemSettings();

        return $settings[$key] ?? $default ?? ($this->defaultSystemSettings[$key] ?? null);
    }

    // ==============================
    // إعدادات المشاريع
    // ==============================

    /**
     * الحالة الافتراضية للمشروع الجديد
     */
    public function getDefaultProjectStatus(): string
    {
        return $this->getProjectSetting('default_project_status', 'planning');
    }

    // ==============================
    // إعدادات المرفقات
    // ==============================

    /**
     * الحد الأقصى لحجم الملف بالبايت
     */
    public function getMaxAttachmentSize(): int
    {
        $sizeMB = (int) $this->getProjectSetting('max_attachments_size', 10);

        return $sizeMB * 1024 * 1024; // تحويل إلى بايت
    }

    /**
     * الحد الأقصى لحجم الملف بالميجابايت
     */
    public function getMaxAttachmentSizeMB(): int
    {
        return (int) $this->getProjectSetting('max_attachments_size', 10);
    }

    /**
     * أنواع الملفات المسموحة كمصفوفة
     */
    public function getAllowedFileTypes(): array
    {
        $types = $this->getProjectSetting('allowed_file_types', 'pdf,doc,docx,xls,xlsx,jpg,png');

        return array_map('trim', explode(',', $types));
    }

    /**
     * أنواع الملفات المسموحة كنص
     */
    public function getAllowedFileTypesString(): string
    {
        return $this->getProjectSetting('allowed_file_types', 'pdf,doc,docx,xls,xlsx,jpg,png');
    }

    /**
     * التحقق من نوع الملف المسموح
     */
    public function isFileTypeAllowed(string $extension): bool
    {
        $allowed = $this->getAllowedFileTypes();

        return in_array(strtolower($extension), array_map('strtolower', $allowed));
    }

    // ==============================
    // إعدادات النظام
    // ==============================

    /**
     * هل وضع الصيانة مفعل؟
     */
    public function isMaintenanceMode(): bool
    {
        return (bool) $this->getSystemSetting('maintenance_mode', false);
    }

    /**
     * هل الإشعارات مفعلة؟
     */
    public function areNotificationsEnabled(): bool
    {
        return (bool) $this->getSystemSetting('enable_notifications', true);
    }

    /**
     * هل إشعارات البريد الإلكتروني مفعلة؟
     */
    public function areEmailNotificationsEnabled(): bool
    {
        return (bool) $this->getSystemSetting('enable_email_notifications', true);
    }

    /**
     * Whitelist of storage keys that callers are allowed to write through the
     * public update API. Anything outside this list is dropped before it reaches
     * the SystemSettings JSON blob so unknown keys cannot pollute it.
     */
    private const PROJECT_SETTING_KEYS = [
        'default_project_status',
        'max_attachments_size',
        'allowed_file_types',
    ];

    /**
     * Persist a partial update of the project settings blob. Caller-supplied
     * keys are filtered against the whitelist, merged on top of the existing
     * stored settings (so unspecified keys keep their value), written via
     * SystemSettings::setValue() (which itself invalidates the SystemSettings
     * model cache), and finally the service-level cache key is cleared so the
     * next getProjectSettings() re-reads from the DB. Returns the fresh flat
     * settings array in the same shape as getProjectSettings().
     *
     * @param  array<string, mixed>  $settings  storage keys (e.g. 'default_project_status')
     * @return array<string, mixed> the merged settings after the write
     */
    public function updateProjectSettings(array $settings): array
    {
        $filtered = array_intersect_key($settings, array_flip(self::PROJECT_SETTING_KEYS));

        $current = $this->getProjectSettings();
        $merged = array_merge($current, $filtered);

        // SystemSettings::setValue writes the merged blob and clears its own
        // model cache, but the service-level cache key (CACHE_KEY_PROJECT) is
        // separate and must be cleared explicitly.
        SystemSettings::setValue('projects', $merged);
        $this->clearCache();

        return $this->getProjectSettings();
    }

    /**
     * مسح الكاش عند تحديث الإعدادات
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_PROJECT);
        Cache::forget(self::CACHE_KEY_SYSTEM);
        Cache::forget('cluster_settings');

        // مسح كاش SystemSettings Model أيضاً
        SystemSettings::clearCache();
    }
}
