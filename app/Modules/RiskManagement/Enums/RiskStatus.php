<?php

namespace App\Modules\RiskManagement\Enums;

enum RiskStatus: string
{
    case Open = 'open';
    case Treating = 'treating';
    case Closed = 'closed';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوح',
            self::Treating => 'قيد المعالجة',
            self::Closed => 'مغلق',
            self::Accepted => 'مقبول',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Treating => 'warning',
            self::Closed => 'success',
            self::Accepted => 'primary',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Accepted], true);
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Treating, self::Accepted, self::Closed],
            self::Treating => [self::Open, self::Accepted, self::Closed],
            self::Closed => [self::Open],
            self::Accepted => [self::Open, self::Treating],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
