<?php

namespace App\Modules\RiskManagement\Enums;

enum RiskAlertType: string
{
    case ReviewDue = 'review_due';
    case LevelEscalated = 'level_escalated';
    case ActionOverdue = 'action_overdue';

    public function label(): string
    {
        return match ($this) {
            self::ReviewDue => 'موعد مراجعة الخطر',
            self::LevelEscalated => 'تصاعد مستوى الخطر',
            self::ActionOverdue => 'إجراء متأخر',
        };
    }
}
