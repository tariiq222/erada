<?php

namespace App\Modules\Meetings\Notifications;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminderNotification extends Notification implements ShouldQueueAfterCommit
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
        $hoursLeft = (int) max(0, round(now()->diffInHours($m->scheduled_at, false)));

        return (new MailMessage)
            ->subject("تذكير: اجتماع {$m->title} غداً")
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line("تذكير: الاجتماع \"{$m->title}\" بعد {$hoursLeft} ساعة.")
            ->line("الرقم المرجعي: {$m->reference_number}")
            ->line("الموعد: {$m->scheduled_at->format('Y-m-d H:i')}")
            ->when($m->location, fn ($mail) => $mail->line("المكان: {$m->location}"))
            ->when($m->virtual_link, fn ($mail) => $mail->line("الرابط: {$m->virtual_link}"))
            ->action('عرض الاجتماع', url("/strategy/meetings/{$m->id}"))
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    public function toArray(object $notifiable): array
    {
        $m = $this->meeting;

        return [
            'type' => 'meeting_reminder',
            'meeting_id' => $m->id,
            'reference_number' => $m->reference_number,
            'title' => $m->title,
            'scheduled_at' => $m->scheduled_at?->toIso8601String(),
            'message' => "تذكير: اجتماعك غداً ({$m->title})",
        ];
    }
}
