<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * First-time welcome message shown in the user's notification center
 * immediately after a successful self-registration.
 *
 * In the simplified-registration flow (no OTP, no admin approval) the
 * user is `active` and `email_verified_at` is set the moment they submit
 * the form. A welcome notification is the only signal they get that
 * their account exists; without it the SPA would silently drop them on
 * the dashboard after `navigate('/dashboard')`.
 *
 * Persisted via the `database` channel only — no email. The system
 * already has OVR / Risk / Shared notifications that follow this same
 * pattern, and the in-app bell renders them.
 */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly User $user) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'auth.welcome',
            'title' => 'auth.welcome_title',
            'body' => 'auth.welcome_body',
            'user_id' => $this->user->id,
        ];
    }

    /**
     * Kept for completeness — the channel list above does not include
     * 'mail', so this method is never invoked. Defined so a future
     * 'mail' addition has a ready template.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('مرحباً بك في منصة إرادة')
            ->greeting('مرحباً '.$this->user->name)
            ->line('تم إنشاء حسابك بنجاح.')
            ->line('يمكنك الآن تسجيل الدخول والوصول إلى لوحة التحكم.')
            ->action('تسجيل الدخول', url('/login'));
    }
}
