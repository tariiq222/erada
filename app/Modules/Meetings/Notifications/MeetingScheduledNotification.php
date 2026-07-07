<?php

namespace App\Modules\Meetings\Notifications;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingScheduledNotification extends Notification implements ShouldQueueAfterCommit
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
            ->subject("دعوة لاجتماع: {$m->title}")
            ->greeting("مرحباً {$notifiable->name}")
            ->line("تمت دعوتك لحضور الاجتماع: {$m->title}")
            ->line("الرقم المرجعي: {$m->reference_number}")
            ->line("الموعد: {$m->scheduled_at->format('Y-m-d H:i')}")
            ->line("المدة: {$m->duration_minutes} دقيقة")
            ->when($m->location, fn ($mail) => $mail->line("المكان: {$m->location}"))
            ->when($m->virtual_link, fn ($mail) => $mail->line("الرابط الافتراضي: {$m->virtual_link}"))
            ->when($m->agenda, fn ($mail) => $mail->line("جدول الأعمال: {$m->agenda}"))
            ->action('عرض الاجتماع', url("/strategy/meetings/{$m->id}"))
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    public function toArray(object $notifiable): array
    {
        $m = $this->meeting;

        return [
            'type' => 'meeting_scheduled',
            'meeting_id' => $m->id,
            'reference_number' => $m->reference_number,
            'title' => $m->title,
            'scheduled_at' => $m->scheduled_at?->toIso8601String(),
            'message' => "تمت دعوتك لاجتماع: {$m->title}",
        ];
    }
}
