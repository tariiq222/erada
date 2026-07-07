<?php

namespace App\Modules\Surveys\Enums;

enum SurveyType: string
{
    case Initial = 'initial';   // استبيان أولي - جمع بيانات
    case Periodic = 'periodic'; // استبيان دوري - قياس

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'استبيان أولي',
            self::Periodic => 'استبيان دوري',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Initial => 'لجمع البيانات الأساسية مثل الإدارات والأقسام',
            self::Periodic => 'للمتابعة والقياس الدوري',
        };
    }

    public function createsImportRequest(): bool
    {
        return $this === self::Initial;
    }
}
