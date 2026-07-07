<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-12/M-13: HSTS (and strict CSP) are derived from the live transport, not the
 * APP_ENV string. A secure request gets HSTS; a plaintext one does not.
 */
class TransportSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_hsts_present_over_https(): void
    {
        $this->get('https://localhost/api/settings/system')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    public function test_hsts_absent_over_http(): void
    {
        $res = $this->get('http://localhost/api/settings/system');
        $this->assertNull($res->headers->get('Strict-Transport-Security'), 'HSTS must not be emitted over plaintext');
    }
}
