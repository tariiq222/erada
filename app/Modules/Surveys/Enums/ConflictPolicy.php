<?php

namespace App\Modules\Surveys\Enums;

enum ConflictPolicy: string
{
    case Skip = 'skip';
    case Overwrite = 'overwrite';
    case RequireReview = 'require_review';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'تجاهل',
            self::Overwrite => 'استبدال',
            self::RequireReview => 'طلب مراجعة',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Skip => 'تجاهل السجل في حالة وجود تعارض',
            self::Overwrite => 'استبدال البيانات الموجودة بالجديدة',
            self::RequireReview => 'إنشاء طلب مراجعة للحسم يدوياً',
        };
    }
}
