<?php

namespace App\Modules\Surveys\Notifications;

use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewSurveyResponseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Survey $survey,
        protected SurveyResponse $response
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('رد جديد على استبيان: '.$this->survey->title)
            ->greeting("مرحباً {$notifiable->name}")
            ->line("تم استلام رد جديد على الاستبيان: {$this->survey->title}")
            ->line("معرّف الرد: {$this->response->id}")
            ->action('عرض الردود', url("/surveys/{$this->survey->id}/responses"))
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_survey_response',
            'survey_id' => $this->survey->id,
            'survey_title' => $this->survey->title,
            'response_id' => $this->response->id,
            'message' => "رد جديد على الاستبيان: {$this->survey->title}",
        ];
    }
}
