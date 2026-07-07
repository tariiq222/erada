<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private function invokeProductionCsp(string $nonce): string
    {
        $middleware = new SecurityHeaders;
        $method = new ReflectionMethod($middleware, 'getProductionCSP');
        $method->setAccessible(true);

        return $method->invoke($middleware, $nonce);
    }

    private function invokeDevelopmentCsp(): string
    {
        $middleware = new SecurityHeaders;
        $method = new ReflectionMethod($middleware, 'getDevelopmentCSP');
        $method->setAccessible(true);

        return $method->invoke($middleware);
    }

    public function test_production_csp_does_not_contain_unsafe_inline(): void
    {
        config()->set('app.env', 'production');

        $csp = $this->invokeProductionCsp(base64_encode(random_bytes(16)));

        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
    }

    public function test_production_csp_does_not_contain_unsafe_eval(): void
    {
        config()->set('app.env', 'production');

        $csp = $this->invokeProductionCsp(base64_encode(random_bytes(16)));

        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    public function test_production_csp_includes_nonce_in_script_src(): void
    {
        config()->set('app.env', 'production');

        $nonce = base64_encode(random_bytes(16));
        $csp = $this->invokeProductionCsp($nonce);

        $this->assertStringContainsString("'nonce-{$nonce}'", $csp);
        // Must appear in script-src specifically
        $this->assertMatchesRegularExpression(
            '/script-src [^;]*\'nonce-'.preg_quote($nonce, '/').'\'/',
            $csp
        );
    }

    public function test_production_csp_includes_nonce_in_style_src(): void
    {
        config()->set('app.env', 'production');

        $nonce = base64_encode(random_bytes(16));
        $csp = $this->invokeProductionCsp($nonce);

        $this->assertMatchesRegularExpression(
            '/style-src [^;]*\'nonce-'.preg_quote($nonce, '/').'\'/',
            $csp
        );
    }

    public function test_development_csp_still_allows_unsafe_inline_and_eval(): void
    {
        config()->set('app.env', 'local');

        $csp = $this->invokeDevelopmentCsp();

        // Dev policy is intentionally permissive for Vite HMR — do not break DX.
        $this->assertStringContainsString("'unsafe-inline'", $csp);
        $this->assertStringContainsString("'unsafe-eval'", $csp);
    }

    public function test_handle_sets_unique_nonce_per_request(): void
    {
        $middleware = new SecurityHeaders;

        config()->set('app.env', 'production');
        $request1 = Request::create('/');
        $response1 = $middleware->handle($request1, fn () => response('ok'));
        $nonce1 = $request1->attributes->get('csp_nonce');

        $request2 = Request::create('/');
        $response2 = $middleware->handle($request2, fn () => response('ok'));
        $nonce2 = $request2->attributes->get('csp_nonce');

        $this->assertIsString($nonce1);
        $this->assertIsString($nonce2);
        $this->assertNotSame($nonce1, $nonce2);
        $this->assertNotEmpty($response1->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("'nonce-{$nonce1}'", $response1->headers->get('Content-Security-Policy'));
    }

    public function test_generate_nonce_returns_base64_string_of_expected_length(): void
    {
        $middleware = new SecurityHeaders;

        $nonce = $middleware->generateNonce();

        $this->assertIsString($nonce);
        $this->assertSame(24, strlen($nonce)); // base64 of 16 bytes
        $this->assertSame(base64_encode(base64_decode($nonce, true)), $nonce); // valid base64
    }
}
