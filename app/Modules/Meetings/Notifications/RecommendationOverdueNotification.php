<?php

namespace App\Modules\Meetings\Notifications;

use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecommendationOverdueNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        protected Recommendation $recommendation
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->recommendation;
        $daysOverdue = (int) ($r->due_date?->diffInDays(now()) ?? 0);

        return (new MailMessage)
            ->subject("توصية متأخرة: {$r->title}")
            ->greeting("مرحباً {$notifiable->name}")
            ->line("التوصية \"{$r->title}\" تجاوزت موعد الاستحقاق.")
            ->line("الرقم المرجعي: {$r->reference_number}")
            ->line("الأولوية: {$r->priority_label}")
            ->line("كان من المفترض إنجازها في: {$r->due_date->format('Y-m-d')}")
            ->line("عدد الأيام المتأخرة: {$daysOverdue}")
            ->action('عرض التوصية', url("/strategy/meetings/recommendations/{$r->id}"))
            ->line('يرجى تحديث الحالة أو تمديد الموعد.');
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->recommendation;

        return [
            'type' => 'recommendation_overdue',
            'recommendation_id' => $r->id,
            'reference_number' => $r->reference_number,
            'title' => $r->title,
            'due_date' => $r->due_date?->toDateString(),
            'days_overdue' => (int) ($r->due_date?->diffInDays(now()) ?? 0),
            'message' => "توصية متأخرة: {$r->title}",
        ];
    }
}
