<?php

namespace App\Modules\Surveys\Enums;

enum InsertPolicy: string
{
    case CreateOnly = 'create_only';
    case UpdateOnly = 'update_only';
    case Upsert = 'upsert';

    public function label(): string
    {
        return match ($this) {
            self::CreateOnly => 'إنشاء فقط',
            self::UpdateOnly => 'تحديث فقط',
            self::Upsert => 'إنشاء أو تحديث',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateOnly => 'إنشاء سجلات جديدة فقط، تجاهل الموجودة',
            self::UpdateOnly => 'تحديث السجلات الموجودة فقط، تجاهل الجديدة',
            self::Upsert => 'إنشاء سجل جديد أو تحديث الموجود',
        };
    }

    public function allowsCreate(): bool
    {
        return in_array($this, [self::CreateOnly, self::Upsert]);
    }

    public function allowsUpdate(): bool
    {
        return in_array($this, [self::UpdateOnly, self::Upsert]);
    }
}
