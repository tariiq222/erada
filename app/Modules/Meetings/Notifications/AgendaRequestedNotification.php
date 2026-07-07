<?php

namespace App\Modules\Meetings\Notifications;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgendaRequestedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        protected Meeting $meeting
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = $this->meeting;

        return (new MailMessage)
            ->subject("طلب إضافة نقاط لجدول أعمال: {$m->title}")
            ->greeting("مرحباً {$notifiable->name}")
            ->line("يطلب منك منظّم الاجتماع \"{$m->title}\" إضافة النقاط التي ترغب بمناقشتها.")
            ->line("الموعد: {$m->scheduled_at->format('Y-m-d H:i')}")
            ->action('إضافة نقاطي', url("/strategy/meetings/{$m->id}"))
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    public function toArray(object $notifiable): array
    {
        $m = $this->meeting;

        return [
            'type' => 'agenda_requested',
            'meeting_id' => $m->id,
            'reference_number' => $m->reference_number,
            'title' => $m->title,
            'scheduled_at' => $m->scheduled_at?->toIso8601String(),
            'message' => "طلب إضافة نقاط لجدول أعمال: {$m->title}",
        ];
    }
}
