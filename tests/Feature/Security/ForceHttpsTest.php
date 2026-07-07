<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ForceHttpsInProduction;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * M-18: plaintext requests are 301-redirected to HTTPS in non-local envs; the
 * local/testing envs are never redirected.
 */
class ForceHttpsTest extends TestCase
{
    public function test_plaintext_redirects_to_https_in_production(): void
    {
        $this->app['env'] = 'production';

        $mw = new ForceHttpsInProduction;
        $request = Request::create('http://example.test/dashboard', 'GET');
        $response = $mw->handle($request, fn () => response('ok'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringStartsWith('https://', (string) $response->headers->get('Location'));
    }

    public function test_forwarded_https_request_does_not_redirect_in_production(): void
    {
        $this->app['env'] = 'production';

        $mw = new ForceHttpsInProduction;
        $request = Request::create('http://example.test/dashboard', 'GET', server: [
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $response = $mw->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_no_redirect_in_testing_env(): void
    {
        $mw = new ForceHttpsInProduction;
        $request = Request::create('http://example.test/dashboard', 'GET');
        $response = $mw->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }
}
