<?php

namespace App\Modules\RiskManagement\Enums;

enum RiskResponseType: string
{
    case Avoid = 'avoid';
    case Mitigate = 'mitigate';
    case Transfer = 'transfer';
    case Accept = 'accept';

    public function label(): string
    {
        return match ($this) {
            self::Avoid => 'تجنب',
            self::Mitigate => 'تخفيف',
            self::Transfer => 'نقل',
            self::Accept => 'قبول',
        };
    }
}
