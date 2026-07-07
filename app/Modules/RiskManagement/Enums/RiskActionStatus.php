<?php

namespace App\Modules\RiskManagement\Enums;

enum RiskActionStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار البدء',
            self::InProgress => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
            self::Blocked => 'متعثر',
            self::Cancelled => 'ملغي',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'default',
            self::InProgress => 'primary',
            self::Completed => 'success',
            self::Blocked => 'danger',
            self::Cancelled => 'default',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
