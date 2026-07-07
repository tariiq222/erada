<?php

namespace App\Modules\OVR\Notifications;

use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportSubmittedNotification extends Notification implements ShouldQueue
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
            ->subject(__('ovr.notifications.report_submitted_subject', ['report_number' => $this->report->report_number]))
            ->greeting(__('ovr.notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('ovr.notifications.report_submitted_line', ['report_number' => $this->report->report_number]))
            ->line(__('ovr.notifications.severity_line', ['severity' => $this->report->severity_level->label()]))
            ->action(__('ovr.notifications.view_report_action'), url("/ovr/incidents/{$this->report->id}"))
            ->line(__('ovr.notifications.thanks_footer'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ovr_report_submitted',
            'report_id' => $this->report->id,
            'report_number' => $this->report->report_number,
            'severity' => $this->report->severity_level->value,
            'message' => __('ovr.notifications.report_submitted_line', ['report_number' => $this->report->report_number]),
        ];
    }
}
