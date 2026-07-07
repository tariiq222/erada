<?php

namespace App\Modules\RiskManagement\Enums;

enum RiskActionType: string
{
    case Preventive = 'preventive';
    case Corrective = 'corrective';

    public function label(): string
    {
        return match ($this) {
            self::Preventive => 'وقائي',
            self::Corrective => 'تصحيحي',
        };
    }
}
