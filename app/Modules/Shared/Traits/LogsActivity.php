<?php

namespace App\Modules\Shared\Traits;

use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait LogsActivity
 *
 * يسجل تلقائياً جميع العمليات على الـ Model
 * created, updated, deleted, restored
 */
trait LogsActivity
{
    /**
     * Boot the trait
     */
    public static function bootLogsActivity(): void
    {
        // تسجيل عند الإنشاء
        static::created(function (Model $model) {
            $model->logActivity('created', null, $model->getLoggableAttributes());
        });

        // تسجيل عند التحديث
        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            $original = $model->getOriginal();

            // تصفية الحقول المتتبعة فقط
            $trackedFields = $model->getTrackedFields();
            $trackedChanges = empty($trackedFields)
                ? $changes
                : array_intersect_key($changes, array_flip($trackedFields));

            // استبعاد timestamps
            unset($trackedChanges['updated_at'], $trackedChanges['created_at']);

            if (empty($trackedChanges)) {
                return;
            }

            $oldValues = [];
            $newValues = [];

            foreach ($trackedChanges as $field => $newValue) {
                $oldValues[$field] = $original[$field] ?? null;
                $newValues[$field] = $newValue;
            }

            $model->logActivity('updated', $oldValues, $newValues);
        });

        // تسجيل عند الحذف
        static::deleted(function (Model $model) {
            $model->logActivity('deleted', $model->getLoggableAttributes(), null);
        });

        // تسجيل عند الاستعادة (إذا كان يدعم SoftDeletes)
        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                $model->logActivity('restored', null, $model->getLoggableAttributes());
            });
        }
    }

    /**
     * الحقول المطلوب تتبعها
     * يمكن تجاوزها في الـ Model
     */
    public function getTrackedFields(): array
    {
        return $this->trackedFields ?? [];
    }

    /**
     * الحصول على السمات القابلة للتسجيل
     */
    protected function getLoggableAttributes(): array
    {
        $trackedFields = $this->getTrackedFields();

        if (empty($trackedFields)) {
            // إذا لم تحدد حقول، سجل الحقول الأساسية
            $attributes = $this->attributesToArray();
            unset($attributes['created_at'], $attributes['updated_at'], $attributes['deleted_at']);
            unset($attributes['password'], $attributes['remember_token']); // أمان

            return $attributes;
        }

        return array_intersect_key($this->attributesToArray(), array_flip($trackedFields));
    }

    /**
     * تسجيل النشاط
     */
    public function logActivity(string $action, ?array $oldValues, ?array $newValues): void
    {
        // محاولة جلب المستخدم من guards مختلفة
        $userId = auth()->id()
            ?? auth('sanctum')->id()
            ?? auth('web')->id()
            ?? request()->user()?->id;

        ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'loggable_type' => get_class($this),
            'loggable_id' => $this->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * الحصول على سجلات النشاط لهذا الـ Model
     */
    public function activityLogs()
    {
        return $this->morphMany(ActivityLog::class, 'loggable');
    }
}
