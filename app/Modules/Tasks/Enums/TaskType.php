<?php

namespace App\Modules\Tasks\Enums;

enum TaskType: string
{
    case PROJECT = 'project';           // مهمة مشروع
    case PERSONAL = 'personal';         // مهمة شخصية
    case DEPARTMENT = 'department';     // مهمة قسم/إدارة
    case RECURRING = 'recurring';       // مهمة متكررة

    public function label(): string
    {
        return match ($this) {
            self::PROJECT => 'مهمة مشروع',
            self::PERSONAL => 'مهمة شخصية',
            self::DEPARTMENT => 'مهمة إدارية',
            self::RECURRING => 'مهمة متكررة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PROJECT => 'blue',
            self::PERSONAL => 'green',
            self::DEPARTMENT => 'purple',
            self::RECURRING => 'orange',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
