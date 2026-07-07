<?php

namespace Tests\Feature\Security;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * M-05: the public survey-submit throttle must key on IP (or a server-issued
 * token), never the client-controlled X-Fingerprint-Hash header. Rotating the
 * header must not move the rate-limit bucket.
 */
class SurveyThrottleKeyTest extends TestCase
{
    private function limitsFor(string $fingerprint): array
    {
        $request = Request::create('/api/surveys/x/submit', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
        $request->headers->set('X-Fingerprint-Hash', $fingerprint);

        $callback = RateLimiter::limiter('survey-submit');
        $result = $callback($request);

        return array_map(fn (Limit $l) => $l->key, is_array($result) ? $result : [$result]);
    }

    public function test_rotating_fingerprint_does_not_change_the_throttle_key(): void
    {
        $a = $this->limitsFor('fingerprint-aaaa');
        $b = $this->limitsFor('fingerprint-bbbb');

        $this->assertSame($a, $b, 'rotating X-Fingerprint-Hash changed the throttle bucket');
        foreach ($a as $key) {
            $this->assertStringNotContainsString('fingerprint', $key);
            $this->assertStringContainsString('198.51.100.7', $key);
        }
    }
}
