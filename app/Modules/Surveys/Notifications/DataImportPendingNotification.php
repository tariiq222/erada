<?php

namespace App\Modules\Surveys\Notifications;

use App\Modules\Surveys\Models\DataImportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DataImportPendingNotification extends Notification implements ShouldQueue
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
        $targetTable = $this->importRequest->getTargetTableLabel();

        return (new MailMessage)
            ->subject('طلب استيراد بيانات بانتظار الاعتماد')
            ->greeting("مرحباً {$notifiable->name}")
            ->line('هناك طلب استيراد جديد بانتظار الاعتماد.')
            ->line("الاستبيان: {$surveyTitle}")
            ->line("الجدول المستهدف: {$targetTable}")
            ->action('مراجعة الطلبات', url('/surveys/import-requests'))
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'data_import_pending',
            'import_request_id' => $this->importRequest->id,
            'survey_id' => $this->importRequest->response?->survey_id,
            'target_table' => $this->importRequest->target_table,
            'message' => 'طلب استيراد بيانات بانتظار الاعتماد',
        ];
    }
}
