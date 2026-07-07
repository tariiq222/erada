<?php

namespace App\Modules\RiskManagement\Notifications;

use App\Modules\RiskManagement\Models\RiskAssessment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiskReviewDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected RiskAssessment $assessment
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $risk = $this->assessment->risk;
        $code = $risk?->code ?? '#'.$this->assessment->risk_id;
        $title = $risk?->title ?? '—';

        return (new MailMessage)
            ->subject("موعد إعادة تقييم الخطر {$code}")
            ->greeting("مرحباً {$notifiable->name}")
            ->line("حان موعد إعادة تقييم الخطر {$code}: {$title}.")
            ->line("تاريخ الاستحقاق: {$this->assessment->next_review_at->format('Y-m-d')}")
            ->action('عرض الخطر', url("/risk-management/risks/{$this->assessment->risk_id}"))
            ->line('يرجى تسجيل تقييم جديد لتحديث حالة الخطر.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'risk_review_due',
            'risk_id' => $this->assessment->risk_id,
            'risk_assessment_id' => $this->assessment->id,
            'next_review_at' => $this->assessment->next_review_at?->toIso8601String(),
            'message' => "موعد إعادة تقييم الخطر #{$this->assessment->risk_id}",
        ];
    }
}
