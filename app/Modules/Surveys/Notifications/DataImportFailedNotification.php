<?php

namespace App\Modules\Surveys\Notifications;

use App\Modules\Surveys\Models\DataImportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataImportFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected DataImportRequest $importRequest
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $surveyTitle = $this->importRequest->response?->survey?->title ?? 'استبيان';
        $errorMessage = $this->importRequest->error_message ?? 'خطأ غير معروف';

        return (new MailMessage)
            ->subject('فشل تطبيق طلب استيراد بيانات')
            ->greeting("مرحباً {$notifiable->name}")
            ->line('فشل تطبيق طلب استيراد بيانات.')
            ->line("الاستبيان: {$surveyTitle}")
            ->line("الخطأ: {$errorMessage}")
            ->action('مراجعة الطلب', url('/surveys/import-requests'))
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'data_import_failed',
            'import_request_id' => $this->importRequest->id,
            'survey_id' => $this->importRequest->response?->survey_id,
            'error_message' => $this->importRequest->error_message,
            'message' => 'فشل تطبيق طلب استيراد بيانات',
        ];
    }
}
