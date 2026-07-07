<?php

namespace App\Modules\Tasks\Enums;

enum TaskStatus: string
{
    case TODO = 'todo';
    case IN_PROGRESS = 'in_progress';
    case IN_REVIEW = 'in_review';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case ON_HOLD = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::TODO => 'للتنفيذ',
            self::IN_PROGRESS => 'قيد التنفيذ',
            self::IN_REVIEW => 'قيد المراجعة',
            self::COMPLETED => 'مكتملة',
            self::CANCELLED => 'ملغاة',
            self::ON_HOLD => 'معلقة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::TODO => 'gray',
            self::IN_PROGRESS => 'blue',
            self::IN_REVIEW => 'yellow',
            self::COMPLETED => 'green',
            self::CANCELLED => 'red',
            self::ON_HOLD => 'orange',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TODO => 'circle',
            self::IN_PROGRESS => 'play-circle',
            self::IN_REVIEW => 'eye',
            self::COMPLETED => 'check-circle',
            self::CANCELLED => 'x-circle',
            self::ON_HOLD => 'pause-circle',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::TODO, self::IN_PROGRESS, self::IN_REVIEW]);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function activeStatuses(): array
    {
        return [self::TODO->value, self::IN_PROGRESS->value, self::IN_REVIEW->value];
    }
}
