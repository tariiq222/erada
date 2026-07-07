<?php

namespace App\Modules\Surveys\Enums;

enum FieldType: string
{
    // حقول نصية
    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Phone = 'phone';
    case Url = 'url';

    // حقول رقمية
    case Number = 'number';
    case Rating = 'rating';
    case Scale = 'scale';

    // حقول اختيار
    case Select = 'select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Multiselect = 'multiselect';

    // حقول التاريخ والوقت
    case Date = 'date';
    case Time = 'time';
    case Datetime = 'datetime';

    // حقول الملفات
    case File = 'file';
    case Image = 'image';

    // حقول متقدمة
    case Matrix = 'matrix';

    // حقول عرض
    case Heading = 'heading';
    case Separator = 'separator';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'نص قصير',
            self::Textarea => 'نص طويل',
            self::Email => 'بريد إلكتروني',
            self::Phone => 'هاتف',
            self::Url => 'رابط',
            self::Number => 'رقم',
            self::Rating => 'تقييم نجوم',
            self::Scale => 'مقياس',
            self::Select => 'قائمة منسدلة',
            self::Radio => 'اختيار فردي',
            self::Checkbox => 'مربع اختيار',
            self::Multiselect => 'اختيار متعدد',
            self::Date => 'تاريخ',
            self::Time => 'وقت',
            self::Datetime => 'تاريخ ووقت',
            self::File => 'ملف',
            self::Image => 'صورة',
            self::Matrix => 'جدول أسئلة',
            self::Heading => 'عنوان',
            self::Separator => 'فاصل',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'heroicon-o-pencil',
            self::Textarea => 'heroicon-o-document-text',
            self::Email => 'heroicon-o-envelope',
            self::Phone => 'heroicon-o-phone',
            self::Url => 'heroicon-o-link',
            self::Number => 'heroicon-o-hashtag',
            self::Rating => 'heroicon-o-star',
            self::Scale => 'heroicon-o-adjustments-horizontal',
            self::Select => 'heroicon-o-chevron-down',
            self::Radio => 'heroicon-o-check-circle',
            self::Checkbox => 'heroicon-o-check',
            self::Multiselect => 'heroicon-o-list-bullet',
            self::Date => 'heroicon-o-calendar',
            self::Time => 'heroicon-o-clock',
            self::Datetime => 'heroicon-o-calendar-days',
            self::File => 'heroicon-o-document',
            self::Image => 'heroicon-o-photo',
            self::Matrix => 'heroicon-o-table-cells',
            self::Heading => 'heroicon-o-bars-3',
            self::Separator => 'heroicon-o-minus',
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this, [
            self::Select,
            self::Radio,
            self::Checkbox,
            self::Multiselect,
        ]);
    }

    public function hasMatrixConfig(): bool
    {
        return $this === self::Matrix;
    }

    public function isDisplayOnly(): bool
    {
        return in_array($this, [self::Heading, self::Separator]);
    }

    public function storesValue(): bool
    {
        return ! $this->isDisplayOnly();
    }

    public function isMultiValue(): bool
    {
        return in_array($this, [
            self::Checkbox,
            self::Multiselect,
            self::Matrix,
        ]);
    }
}
