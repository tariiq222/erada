<?php

namespace App\Modules\Surveys\Enums;

enum ResponseStatus: string
{
    case Submitted = 'submitted';
    case Invalid = 'invalid';
    case Flagged = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'مُرسل',
            self::Invalid => 'غير صالح',
            self::Flagged => 'مُعلّم للمراجعة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'success',
            self::Invalid => 'danger',
            self::Flagged => 'warning',
        };
    }
}
