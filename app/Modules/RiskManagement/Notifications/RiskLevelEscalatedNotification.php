<?php

namespace App\Modules\RiskManagement\Notifications;

use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dispatched when RiskLifecycleService detects a level escalation on a
 * reassessment. The lifecycle service writes the RiskAlert audit row
 * before calling notify() so the dashboard reflects the event even
 * if the mail transport is offline.
 */
class RiskLevelEscalatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Risk $risk,
        protected RiskAlert $alert
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $previous = $this->alert->payload['previous_level'] ?? '—';
        $newLevel = $this->alert->payload['new_level'] ?? '—';

        return (new MailMessage)
            ->subject("تصاعد مستوى الخطر {$this->risk->code}")
            ->greeting("مرحباً {$notifiable->name}")
            ->line("تم تسجيل تصاعد في مستوى الخطر {$this->risk->code}: {$this->risk->title}.")
            ->line("المستوى السابق: {$previous} — المستوى الجديد: {$newLevel}")
            ->action('عرض الخطر', url("/risk-management/risks/{$this->risk->id}"))
            ->line('يرجى مراجعة الخطة واتخاذ الإجراءات اللازمة.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'risk_level_escalated',
            'risk_id' => $this->risk->id,
            'risk_alert_id' => $this->alert->id,
            'previous_level' => $this->alert->payload['previous_level'] ?? null,
            'new_level' => $this->alert->payload['new_level'] ?? null,
            'message' => "تصاعد مستوى الخطر {$this->risk->code} إلى {$this->alert->payload['new_level']}",
        ];
    }
}
