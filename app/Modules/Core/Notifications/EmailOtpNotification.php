<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $code,
        protected OtpPurpose $purpose
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('رمز التحقق - منصة إرادة')
            ->greeting('مرحباً')
            ->line('رمز التحقق لإعادة تعيين كلمة المرور:')
            ->line($this->code)
            ->line('الرمز صالح لمدة 10 دقائق ولمرة واحدة فقط.')
            ->line('إذا لم تطلب هذا الرمز، يمكنك تجاهل هذه الرسالة.');
    }
}
