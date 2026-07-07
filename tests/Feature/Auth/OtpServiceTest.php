<?php

namespace Tests\Feature\Auth;

use App\Modules\Core\Enums\OtpPurpose;
use App\Modules\Core\Models\EmailOtp;
use App\Modules\Core\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OtpService::class);
    }

    public function test_issue_then_verify_succeeds_and_consumes(): void
    {
        $code = $this->service->issue('a@org.test', OtpPurpose::PasswordReset);

        $this->assertTrue($this->service->verify('a@org.test', OtpPurpose::PasswordReset, $code));
        $this->assertFalse($this->service->verify('a@org.test', OtpPurpose::PasswordReset, $code));
    }

    public function test_wrong_code_fails_and_counts_attempts(): void
    {
        $this->service->issue('b@org.test', OtpPurpose::PasswordReset);

        $this->assertFalse($this->service->verify('b@org.test', OtpPurpose::PasswordReset, '000000'));
        $this->assertSame(1, EmailOtp::where('email', 'b@org.test')->latest('id')->first()->attempts);
    }

    public function test_lockout_after_max_attempts(): void
    {
        $code = $this->service->issue('c@org.test', OtpPurpose::PasswordReset);

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->service->verify('c@org.test', OtpPurpose::PasswordReset, '999999');
        }
        $this->assertFalse($this->service->verify('c@org.test', OtpPurpose::PasswordReset, $code));
    }

    public function test_expired_code_fails(): void
    {
        $code = $this->service->issue('d@org.test', OtpPurpose::PasswordReset);
        Carbon::setTestNow(now()->addMinutes(OtpService::TTL_MINUTES + 1));

        $this->assertFalse($this->service->verify('d@org.test', OtpPurpose::PasswordReset, $code));
        Carbon::setTestNow();
    }

    public function test_issuing_invalidates_prior_unconsumed_codes(): void
    {
        $old = $this->service->issue('e@org.test', OtpPurpose::PasswordReset);
        $new = $this->service->issue('e@org.test', OtpPurpose::PasswordReset);

        $this->assertFalse($this->service->verify('e@org.test', OtpPurpose::PasswordReset, $old));
        $this->assertTrue($this->service->verify('e@org.test', OtpPurpose::PasswordReset, $new));
    }

    public function test_code_is_single_use(): void
    {
        // After the simplified-registration cutover, only OtpPurpose::PasswordReset
        // remains, so the previous "purpose isolation" test is no longer applicable.
        // We still pin the single-use guarantee: the second verify with the same
        // code must fail because the first call consumed it.
        $code = $this->service->issue('p@org.test', OtpPurpose::PasswordReset);

        $this->assertTrue($this->service->verify('p@org.test', OtpPurpose::PasswordReset, $code));
        $this->assertFalse($this->service->verify('p@org.test', OtpPurpose::PasswordReset, $code));
    }

    public function test_code_is_isolated_by_email(): void
    {
        $code = $this->service->issue('a@org.test', OtpPurpose::PasswordReset);

        $this->assertFalse($this->service->verify('b@org.test', OtpPurpose::PasswordReset, $code));
    }

    public function test_reissue_does_not_reset_bruteforce_budget(): void
    {
        $this->service->issue('budget@org.test', OtpPurpose::PasswordReset);

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS - 1; $i++) {
            $this->service->verify('budget@org.test', OtpPurpose::PasswordReset, '999999');
        }

        $latest = $this->service->issue('budget@org.test', OtpPurpose::PasswordReset);
        $this->service->verify('budget@org.test', OtpPurpose::PasswordReset, '000000');

        $this->assertFalse($this->service->verify('budget@org.test', OtpPurpose::PasswordReset, $latest));
    }

    public function test_consumed_code_cannot_be_reused_after_out_of_band_consume(): void
    {
        $code = $this->service->issue('race@org.test', OtpPurpose::PasswordReset);

        EmailOtp::query()->update(['consumed_at' => now()]);

        $this->assertFalse($this->service->verify('race@org.test', OtpPurpose::PasswordReset, $code));
    }

    public function test_correct_code_fails_once_attempts_exhausted(): void
    {
        $code = $this->service->issue('exhaust@org.test', OtpPurpose::PasswordReset);

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->service->verify('exhaust@org.test', OtpPurpose::PasswordReset, '999999');
        }

        $this->assertFalse($this->service->verify('exhaust@org.test', OtpPurpose::PasswordReset, $code));
    }
}
