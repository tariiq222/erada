<?php

namespace App\Modules\OVR\Notifications;

use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Models\IncidentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected IncidentReport $report,
        protected ReportStatus $fromStatus,
        protected ReportStatus $toStatus
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('ovr.notifications.status_changed_subject', ['report_number' => $this->report->report_number]))
            ->greeting(__('ovr.notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('ovr.notifications.status_changed_line', [
                'report_number' => $this->report->report_number,
                'from' => $this->fromStatus->label(),
                'to' => $this->toStatus->label(),
            ]))
            ->action(__('ovr.notifications.view_report_action'), url("/ovr/incidents/{$this->report->id}"))
            ->line(__('ovr.notifications.thanks_footer'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ovr_status_changed',
            'report_id' => $this->report->id,
            'report_number' => $this->report->report_number,
            'from_status' => $this->fromStatus->value,
            'to_status' => $this->toStatus->value,
            'message' => __('ovr.notifications.status_changed_message', [
                'report_number' => $this->report->report_number,
                'status' => $this->toStatus->label(),
            ]),
        ];
    }
}
