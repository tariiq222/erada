<?php

namespace App\Modules\Surveys\Services;

use App\Modules\Surveys\Enums\DataTransform;

/**
 * سجل التحويلات الآمنة
 * يوفر طريقة آمنة لتطبيق التحويلات بدون eval
 */
class TransformRegistry
{
    /**
     * الحصول على كل التحويلات المتاحة
     */
    public static function getAvailableTransforms(): array
    {
        $transforms = [];

        foreach (DataTransform::cases() as $transform) {
            $transforms[$transform->value] = [
                'value' => $transform->value,
                'label' => $transform->label(),
            ];
        }

        return $transforms;
    }

    /**
     * تطبيق تحويل واحد
     */
    public static function apply(string $transform, mixed $value): mixed
    {
        $enum = DataTransform::tryFrom($transform);

        if (! $enum) {
            return $value; // تحويل غير معروف، إرجاع القيمة كما هي
        }

        return $enum->apply($value);
    }

    /**
     * تطبيق قائمة من التحويلات
     */
    public static function applyMany(array $transforms, mixed $value): mixed
    {
        return DataTransform::applyTransforms($value, $transforms);
    }

    /**
     * التحقق من صحة التحويلات
     */
    public static function validateTransforms(array $transforms): array
    {
        $errors = [];

        foreach ($transforms as $transform) {
            if (! DataTransform::tryFrom($transform)) {
                $errors[] = "تحويل غير معروف: {$transform}";
            }
        }

        return $errors;
    }

    /**
     * الحصول على التحويلات المناسبة لنوع حقل معين
     */
    public static function getTransformsForFieldType(string $fieldType): array
    {
        $all = [
            DataTransform::Trim,
        ];

        $textTransforms = [
            DataTransform::Trim,
            DataTransform::Lowercase,
            DataTransform::Uppercase,
        ];

        $emailTransforms = [
            DataTransform::Trim,
            DataTransform::Lowercase,
            DataTransform::NormalizeEmail,
            DataTransform::MapUserByEmail,
        ];

        $phoneTransforms = [
            DataTransform::Trim,
            DataTransform::NormalizePhone,
        ];

        $numberTransforms = [
            DataTransform::ToInteger,
        ];

        $dateTransforms = [
            DataTransform::ParseDate,
        ];

        return match ($fieldType) {
            'text', 'textarea' => $textTransforms,
            'email' => $emailTransforms,
            'phone' => $phoneTransforms,
            'number', 'rating', 'scale' => $numberTransforms,
            'date', 'datetime' => $dateTransforms,
            'select', 'radio' => array_merge($textTransforms, [
                DataTransform::MapDepartmentByName,
                DataTransform::MapUserByEmail,
            ]),
            'checkbox' => [DataTransform::ToBoolean],
            default => $all,
        };
    }
}
