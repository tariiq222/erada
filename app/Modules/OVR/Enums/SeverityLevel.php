<?php

namespace App\Modules\OVR\Enums;

enum SeverityLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => __('ovr.severity.low'),
            self::Medium => __('ovr.severity.medium'),
            self::High => __('ovr.severity.high'),
            self::Critical => __('ovr.severity.critical'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'success',
            self::Medium => 'warning',
            self::High => 'danger',
            self::Critical => 'danger',
        };
    }

    public function slaHours(): int
    {
        return match ($this) {
            self::Low => 48,
            self::Medium => 48,
            self::High => 24,
            self::Critical => 4,
        };
    }
}
