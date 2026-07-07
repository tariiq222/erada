<?php

namespace App\Modules\RiskManagement\Enums;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'منخفض',
            self::Medium => 'متوسط',
            self::High => 'عالٍ',
            self::Critical => 'حرج',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'success',
            self::Medium => 'primary',
            self::High => 'warning',
            self::Critical => 'danger',
        };
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score <= 3 => self::Low,
            $score <= 6 => self::Medium,
            $score <= 12 => self::High,
            default => self::Critical,
        };
    }
}
