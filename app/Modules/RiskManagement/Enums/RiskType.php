<?php

namespace App\Modules\RiskManagement\Enums;

enum RiskType: string
{
    case Operational = 'operational';
    case Clinical = 'clinical';
    case Financial = 'financial';
    case Technical = 'technical';
    case Compliance = 'compliance';
    case Reputational = 'reputational';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'تشغيلي',
            self::Clinical => 'سريري',
            self::Financial => 'مالي',
            self::Technical => 'تقني',
            self::Compliance => 'امتثال',
            self::Reputational => 'سمعة',
        };
    }
}
