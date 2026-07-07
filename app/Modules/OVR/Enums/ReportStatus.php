<?php

namespace App\Modules\OVR\Enums;

enum ReportStatus: string
{
    case Draft = 'draft';
    case New = 'new';
    case UnderReview = 'under_review';
    case PendingInfo = 'pending_info';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('ovr.status.draft'),
            self::New => __('ovr.status.new'),
            self::UnderReview => __('ovr.status.under_review'),
            self::PendingInfo => __('ovr.status.pending_info'),
            self::InProgress => __('ovr.status.in_progress'),
            self::Resolved => __('ovr.status.resolved'),
            self::Closed => __('ovr.status.closed'),
            self::Rejected => __('ovr.status.rejected'),
            self::Archived => __('ovr.status.archived'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'default',
            self::New => 'danger',
            self::UnderReview => 'warning',
            self::PendingInfo => 'accent',
            self::InProgress => 'primary',
            self::Resolved => 'success',
            self::Closed => 'success',
            self::Rejected => 'default',
            self::Archived => 'default',
        };
    }

    /**
     * Whether the report can be edited in this status.
     */
    public function canEdit(): bool
    {
        return match ($this) {
            self::Draft, self::New, self::PendingInfo => true,
            default => false,
        };
    }

    /**
     * Status transitions allowed from this status.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::New, self::Rejected],
            self::New => [self::UnderReview, self::InProgress, self::Rejected],
            self::UnderReview => [self::PendingInfo, self::InProgress, self::Resolved, self::Rejected],
            self::PendingInfo => [self::UnderReview, self::New],
            self::InProgress => [self::PendingInfo, self::Resolved],
            self::Resolved => [self::Closed, self::UnderReview],
            self::Closed => [self::Archived],
            self::Archived => [self::Closed],
            self::Rejected => [self::New],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
