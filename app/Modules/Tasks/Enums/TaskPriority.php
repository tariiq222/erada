<?php

namespace App\Modules\Tasks\Enums;

enum TaskPriority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case URGENT = 'urgent';      // للتوافق مع الكود القديم
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'منخفضة',
            self::MEDIUM => 'متوسطة',
            self::HIGH => 'عالية',
            self::URGENT => 'عاجلة',
            self::CRITICAL => 'حرجة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::LOW => 'gray',
            self::MEDIUM => 'blue',
            self::HIGH => 'orange',
            self::URGENT => 'orange',
            self::CRITICAL => 'red',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::URGENT => 4,
            self::CRITICAL => 5,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
