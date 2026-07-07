<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Enums\OtpPurpose;
use App\Modules\Core\Notifications\EmailOtpNotification;
use App\Modules\Core\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OtpNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_sends_otp_notification_to_email(): void
    {
        Notification::fake();

        app(OtpService::class)->issue('user@org.test', OtpPurpose::PasswordReset);

        Notification::assertSentOnDemand(EmailOtpNotification::class);
    }
}
