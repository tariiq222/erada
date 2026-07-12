<?php

namespace App\Modules\Shared\Formatters;

use App\Modules\Shared\Models\ActivityLog;

/**
 * ActivityLogFormatter - تنسيق وترجمة سجلات النشاط
 *
 * يحتوي على:
 * - ترجمة أسماء الأحداث
 * - ترجمة أسماء الكيانات
 * - ألوان الأحداث
 *
 * مستخرج من ActivityLog model لتقليل حجمه وتحسين SRP
 */
class ActivityLogFormatter
{
    /**
     * ترجمة أسماء الأحداث
     */
    protected static array $actionLabels = [
        // CRUD
        ActivityLog::ACTION_CREATED => 'إنشاء',
        ActivityLog::ACTION_UPDATED => 'تحديث',
        ActivityLog::ACTION_DELETED => 'حذف',
        ActivityLog::ACTION_RESTORED => 'استعادة',
        // المصادقة
        ActivityLog::ACTION_LOGIN => 'تسجيل دخول',
        ActivityLog::ACTION_LOGOUT => 'تسجيل خروج',
        ActivityLog::ACTION_LOGIN_FAILED => 'محاولة دخول فاشلة',
        ActivityLog::ACTION_PASSWORD_CHANGED => 'تغيير كلمة المرور',
        ActivityLog::ACTION_ACCOUNT_SETUP => 'إعداد الحساب',
        // الصلاحيات والأدوار
        ActivityLog::ACTION_ROLE_ASSIGNED => 'تعيين دور',
        ActivityLog::ACTION_ROLE_REVOKED => 'إزالة دور',
        ActivityLog::ACTION_ROLE_UPDATED => 'تحديث دور',
        ActivityLog::ACTION_PERMISSION_GRANTED => 'منح صلاحية',
        ActivityLog::ACTION_PERMISSION_REVOKED => 'سحب صلاحية',
        ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED => 'تعيين دور نظام',
        ActivityLog::ACTION_SYSTEM_ROLE_REVOKED => 'إزالة دور نظام',
        ActivityLog::ACTION_ACCESS_DENIED => 'رفض وصول',
    ];

    /**
     * ترجمة أنواع الكيانات
     */
    protected static array $modelLabels = [
        'App\\Modules\\Core\\Models\\User' => 'مستخدم',
        'App\\Modules\\Core\\Models\\Organization' => 'مؤسسة',
        'App\\Modules\\HR\\Models\\Department' => 'قسم',
        'App\\Modules\\Projects\\Models\\Project' => 'مشروع',
        'App\\Modules\\Projects\\Models\\Milestone' => 'مرحلة',
        'App\\Modules\\Projects\\Models\\ProjectRisk' => 'خطر',
        'App\\Modules\\Projects\\Models\\ProjectExpense' => 'مصروف',
        'App\\Modules\\Shared\\Models\\Comment' => 'تعليق',
        'App\\Modules\\Shared\\Models\\Attachment' => 'مرفق',
        'App\\Modules\\Tasks\\Models\\Task' => 'مهمة',
    ];

    /**
     * ألوان الأحداث
     */
    protected static array $actionColors = [
        // CRUD
        ActivityLog::ACTION_CREATED => 'success',
        ActivityLog::ACTION_UPDATED => 'info',
        ActivityLog::ACTION_DELETED => 'danger',
        ActivityLog::ACTION_RESTORED => 'warning',
        // المصادقة
        ActivityLog::ACTION_LOGIN => 'success',
        ActivityLog::ACTION_LOGOUT => 'gray',
        ActivityLog::ACTION_LOGIN_FAILED => 'danger',
        ActivityLog::ACTION_PASSWORD_CHANGED => 'warning',
        ActivityLog::ACTION_ACCOUNT_SETUP => 'info',
        // الصلاحيات والأدوار
        ActivityLog::ACTION_ROLE_ASSIGNED => 'success',
        ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED => 'success',
        ActivityLog::ACTION_ROLE_REVOKED => 'danger',
        ActivityLog::ACTION_SYSTEM_ROLE_REVOKED => 'danger',
        ActivityLog::ACTION_ROLE_UPDATED => 'info',
        ActivityLog::ACTION_PERMISSION_GRANTED => 'success',
        ActivityLog::ACTION_PERMISSION_REVOKED => 'danger',
        ActivityLog::ACTION_ACCESS_DENIED => 'warning',
    ];

    /**
     * الحصول على تسمية الحدث بالعربية
     */
    public function getActionLabel(string $action): string
    {
        return static::$actionLabels[$action] ?? $action;
    }

    /**
     * الحصول على تسمية نوع الكيان بالعربية
     */
    public function getModelLabel(string $modelClass): string
    {
        return static::$modelLabels[$modelClass] ?? class_basename($modelClass);
    }

    /**
     * الحصول على لون الحدث
     */
    public function getActionColor(string $action): string
    {
        return static::$actionColors[$action] ?? 'gray';
    }

    /**
     * الحصول على جميع تسميات الأحداث
     */
    public function getAllActionLabels(): array
    {
        return static::$actionLabels;
    }

    /**
     * الحصول على جميع تسميات الكيانات
     */
    public function getAllModelLabels(): array
    {
        return static::$modelLabels;
    }

    /**
     * تنسيق سجل نشاط للعرض
     */
    public function format(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => $this->getActionLabel($log->action),
            'action_color' => $this->getActionColor($log->action),
            'model_label' => $this->getModelLabel($log->loggable_type ?? ''),
            'description' => $log->description,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'created_at' => $log->created_at?->toISOString(),
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
        ];
    }

    /**
     * تنسيق مجموعة سجلات
     */
    public function formatCollection(iterable $logs): array
    {
        $result = [];
        foreach ($logs as $log) {
            $result[] = $this->format($log);
        }

        return $result;
    }
}
