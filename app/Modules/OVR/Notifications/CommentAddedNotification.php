<?php

namespace App\Modules\OVR\Notifications;

use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\ReportComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected IncidentReport $report,
        protected ReportComment $comment
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('ovr.notifications.comment_added_subject', ['report_number' => $this->report->report_number]))
            ->greeting(__('ovr.notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('ovr.notifications.comment_added_line', [
                'author' => $this->comment->author_name,
                'report_number' => $this->report->report_number,
            ]))
            ->action(__('ovr.notifications.view_report_action'), url("/ovr/incidents/{$this->report->id}"))
            ->line(__('ovr.notifications.thanks_footer'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ovr_comment_added',
            'report_id' => $this->report->id,
            'report_number' => $this->report->report_number,
            'comment_id' => $this->comment->id,
            'author_name' => $this->comment->author_name,
            'message' => __('ovr.notifications.comment_added_subject', ['report_number' => $this->report->report_number]),
        ];
    }
}
