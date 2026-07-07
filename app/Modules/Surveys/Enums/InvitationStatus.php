<?php

namespace App\Modules\Surveys\Enums;

enum InvitationStatus: string
{
    case Active = 'active';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Used => 'مستخدم',
            self::Expired => 'منتهي',
            self::Revoked => 'ملغي',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Used => 'info',
            self::Expired => 'warning',
            self::Revoked => 'danger',
        };
    }

    public function canUse(): bool
    {
        return $this === self::Active;
    }
}
