<?php

namespace Tests\Feature\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * M-06: the OTP/forgot limiter must carry a per-IP ceiling in addition to the
 * per-email+IP limit, so one IP cannot bomb many distinct emails.
 */
class OtpRateLimitCeilingTest extends TestCase
{
    public function test_otp_limiter_has_a_pure_per_ip_ceiling(): void
    {
        $request = Request::create('/api/forgot-password', 'POST', ['email' => 'victim@example.test'], server: ['REMOTE_ADDR' => '203.0.113.42']);

        $limits = RateLimiter::limiter('otp')($request);
        $this->assertIsArray($limits);
        $keys = array_map(fn (Limit $l) => $l->key, $limits);

        // A ceiling keyed on IP only (no email) must exist.
        $ipOnly = array_filter($keys, fn ($k) => str_contains($k, '203.0.113.42') && ! str_contains($k, 'victim@example.test'));
        $this->assertNotEmpty($ipOnly, 'no pure per-IP OTP ceiling present');
    }
}
