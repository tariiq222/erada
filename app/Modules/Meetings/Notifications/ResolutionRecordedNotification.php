<?php

namespace App\Modules\Meetings\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\MeetingResolution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 1 / Direction R — notify the owner that a meeting resolution has
 * been recorded against them. Mirrors RecommendationAssignedNotification
 * shape (mail + database channels, ShouldQueueAfterCommit) so the existing
 * NotificationBell + NotificationDropdown render the new entry without
 * having to special-case it.
 */
class ResolutionRecordedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MeetingResolution $resolution) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $kindLabel = MeetingResolution::KINDS[$this->resolution->kind] ?? 'مخرج';
        $title = "تم تسجيل {$kindLabel} جديد لك";

        return (new MailMessage)
            ->subject($title)
            ->line($title.': '.$this->resolution->title)
            ->line('الاجتماع: '.($this->resolution->meeting?->title ?? 'غير محدد'))
            ->line('الحالة: '.($this->resolution->status_label ?? $this->resolution->status));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => 'meeting_resolution_recorded',
            'resolution_id' => $this->resolution->id,
            'reference_number' => $this->resolution->reference_number,
            'kind' => $this->resolution->kind,
            'title' => $this->resolution->title,
            'meeting_id' => $this->resolution->meeting_id,
            'due_date' => $this->resolution->due_date?->toDateString(),
            'message' => 'تم تسجيل '.(MeetingResolution::KINDS[$this->resolution->kind] ?? 'مخرج').' جديد: '.$this->resolution->title,
        ];
    }
}
