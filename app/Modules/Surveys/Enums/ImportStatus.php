<?php

namespace App\Modules\Surveys\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Applied = 'applied';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد المراجعة',
            self::Approved => 'معتمد',
            self::Rejected => 'مرفوض',
            self::Applied => 'تم التطبيق',
            self::Failed => 'فشل',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Applied => 'success',
            self::Failed => 'danger',
        };
    }

    public function canApprove(): bool
    {
        return $this === self::Pending;
    }

    public function canReject(): bool
    {
        return $this === self::Pending;
    }

    public function canApply(): bool
    {
        return $this === self::Approved;
    }

    public function canRetry(): bool
    {
        return $this === self::Failed;
    }
}
