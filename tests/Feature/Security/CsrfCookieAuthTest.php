<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureCsrfForStateChangingApi;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * M-14: a state-changing request whose bearer was synthesized from the auth
 * cookie (browser/cookie client) must still pass the CSRF check. A genuine
 * Authorization-header (token) client stays exempt.
 */
class CsrfCookieAuthTest extends TestCase
{
    public function test_cookie_auth_post_without_csrf_token_is_rejected(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer cookie-synth-token');
        // Simulates AuthTokenFromCookie having injected the bearer from the cookie.
        $request->attributes->set('auth_from_cookie', true);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(419, $response->getStatusCode(), 'cookie-auth POST without CSRF token must be 419');
    }

    public function test_genuine_bearer_client_still_bypasses_csrf(): void
    {
        $middleware = new EnsureCsrfForStateChangingApi;
        $request = Request::create('/api/users', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer realapitoken123');
        // No auth_from_cookie attribute → genuine token client.

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode(), 'raw bearer client should bypass CSRF');
    }
}
