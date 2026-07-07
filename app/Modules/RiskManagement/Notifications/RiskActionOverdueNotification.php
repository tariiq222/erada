<?php

namespace App\Modules\RiskManagement\Notifications;

use App\Modules\RiskManagement\Models\RiskAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiskActionOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected RiskAction $action
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $risk = $this->action->risk;
        $code = $risk?->code ?? '#'.$this->action->risk_id;

        return (new MailMessage)
            ->subject("إجراء متأخر على الخطر {$code}")
            ->greeting("مرحباً {$notifiable->name}")
            ->line("الإجراء \"{$this->action->title}\" على الخطر {$code} متأخر عن موعده.")
            ->line("كان من المفترض إنجازه في: {$this->action->due_date->format('Y-m-d')}")
            ->action('عرض الإجراء', url("/risk-management/risks/{$this->action->risk_id}#actions"))
            ->line('يرجى تحديث حالة الإجراء أو تمديد الموعد.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'risk_action_overdue',
            'risk_id' => $this->action->risk_id,
            'risk_action_id' => $this->action->id,
            'due_date' => $this->action->due_date?->toIso8601String(),
            'message' => "الإجراء \"{$this->action->title}\" متأخر",
        ];
    }
}
