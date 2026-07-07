<?php

namespace App\Modules\OVR\Notifications;

use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SLADueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected IncidentReport $report
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('ovr.notifications.sla_due_subject', ['report_number' => $this->report->report_number]))
            ->greeting(__('ovr.notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('ovr.notifications.sla_due_line', ['report_number' => $this->report->report_number]))
            ->line(__('ovr.notifications.due_date_line', ['due_date' => $this->report->due_date->format('Y-m-d H:i')]))
            ->action(__('ovr.notifications.view_report_action'), url("/ovr/incidents/{$this->report->id}"))
            ->line(__('ovr.notifications.sla_due_action_line'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ovr_sla_due',
            'report_id' => $this->report->id,
            'report_number' => $this->report->report_number,
            'due_date' => $this->report->due_date?->toIso8601String(),
            'message' => __('ovr.notifications.sla_due_subject', ['report_number' => $this->report->report_number]),
        ];
    }
}
