<?php

namespace App\Modules\Meetings\Notifications;

use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecommendationAssignedNotification extends Notification implements ShouldQueueAfterCommit
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

        return (new MailMessage)
            ->subject("تم تعيينك لتوصية: {$r->title}")
            ->greeting("مرحباً {$notifiable->name}")
            ->line("تم تعيينك للتوصية: {$r->title}")
            ->line("الرقم المرجعي: {$r->reference_number}")
            ->line("الأولوية: {$r->priority_label}")
            ->when($r->due_date, fn ($mail) => $mail->line("موعد الاستحقاق: {$r->due_date->format('Y-m-d')}"))
            ->when($r->decision, fn ($mail) => $mail->line("القرار الأصلي: {$r->decision->title}"))
            ->action('عرض التوصية', url("/strategy/meetings/recommendations/{$r->id}"))
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->recommendation;

        return [
            'type' => 'recommendation_assigned',
            'recommendation_id' => $r->id,
            'reference_number' => $r->reference_number,
            'title' => $r->title,
            'priority' => $r->priority,
            'due_date' => $r->due_date?->toDateString(),
            'message' => "تم تعيينك لتوصية: {$r->title}",
        ];
    }
}
