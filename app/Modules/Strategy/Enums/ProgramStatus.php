<?php

namespace App\Modules\Strategy\Enums;

enum ProgramStatus: string
{
    case DRAFT = 'draft';
    case PLANNING = 'planning';
    case IN_PROGRESS = 'in_progress';
    case ON_HOLD = 'on_hold';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Get the label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'مسودة',
            self::PLANNING => 'تخطيط',
            self::IN_PROGRESS => 'قيد التنفيذ',
            self::ON_HOLD => 'معلق',
            self::COMPLETED => 'مكتمل',
            self::CANCELLED => 'ملغي',
        };
    }

    /**
     * Get the color for the status (for UI).
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PLANNING => 'blue',
            self::IN_PROGRESS => 'yellow',
            self::ON_HOLD => 'orange',
            self::COMPLETED => 'green',
            self::CANCELLED => 'red',
        };
    }

    /**
     * Check if the status is active.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::PLANNING, self::IN_PROGRESS]);
    }

    /**
     * Check if the status is closed.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED]);
    }

    /**
     * Get all statuses as array for forms.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
