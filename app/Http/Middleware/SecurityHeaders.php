<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * إضافة Headers الأمان للاستجابات
     */
    public function handle(Request $request, Closure $next): Response
    {
        // توليد nonce للسكربتات
        $nonce = $this->generateNonce();

        // حفظ الـ nonce في الـ request لاستخدامه في القوالب
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        // Content Security Policy — strict whenever the transport is secure
        // (or in production), lenient only over plaintext dev for Vite HMR (M-13).
        $response->headers->set('Content-Security-Policy', $this->getCSP($nonce, $request->isSecure()));

        // منع الـ XSS
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // منع الـ Content Type Sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // منع الـ Clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions Policy
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Strict Transport Security — only meaningful over HTTPS (M-12). Emitting
        // it on a plaintext response is useless/harmful, so gate on the transport.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // X-Permitted-Cross-Domain-Policies
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Cross-Origin Headers للأمان الإضافي
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }

    /**
     * توليد nonce فريد لكل طلب
     */
    public function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * Content Security Policy
     *
     * في الإنتاج: استخدام nonce بدلاً من unsafe-inline/unsafe-eval
     * في التطوير: السماح بـ unsafe-inline/eval لدعم Vite HMR
     */
    private function getCSP(string $nonce, bool $isSecure = false): string
    {
        // Strict CSP whenever the transport is secure or in production; the
        // lenient dev policy (unsafe-inline/eval for Vite HMR) is only used over
        // plaintext local dev.
        if ($isSecure || config('app.env') === 'production') {
            return $this->getProductionCSP($nonce);
        }

        return $this->getDevelopmentCSP();
    }

    /**
     * CSP للإنتاج
     *
     * - لا unsafe-inline / unsafe-eval
     * - inline scripts/styles يجب أن تحمل السمة nonce="{nonce}"
     * - Vite في الإنتاج يولّد assets خارجية (لا inline scripts) فلا حاجة لـ unsafe-eval
     */
    private function getProductionCSP(string $nonce): string
    {
        $appUrl = config('app.url');

        $directives = [
            // السماح فقط لمصادر موثوقة
            "default-src 'self'",

            // السكربتات: self + nonce (يحظر unsafe-inline و unsafe-eval)
            "script-src 'self' 'nonce-{$nonce}'",

            // الأنماط: self + nonce (لـ inline <style>) + خطوط Google
            "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://fonts.bunny.net",

            // الخطوط
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data:",

            // الصور
            "img-src 'self' data: blob: https:",

            // الاتصالات (API)
            "connect-src 'self' {$appUrl}",

            // Workers
            "worker-src 'self' blob:",

            // منع التضمين في إطارات خارجية
            "frame-ancestors 'self'",

            // تقييد النماذج
            "form-action 'self'",

            // تقييد base URI
            "base-uri 'self'",

            // منع object/embed
            "object-src 'none'",

            // Upgrade HTTP to HTTPS
            'upgrade-insecure-requests',
        ];

        return implode('; ', $directives);
    }

    /**
     * CSP للتطوير (أقل صرامة لدعم Vite HMR)
     */
    private function getDevelopmentCSP(): string
    {
        $viteDevServer = ' http://localhost:* http://127.0.0.1:* ws://localhost:* ws://127.0.0.1:*';

        $directives = [
            "default-src 'self'".$viteDevServer,
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'".$viteDevServer,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net".$viteDevServer,
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net data:".$viteDevServer,
            "img-src 'self' data: blob: https://chart.googleapis.com http: https:",
            "connect-src 'self' ".config('app.url').$viteDevServer,
            "worker-src 'self' blob:".$viteDevServer,
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
        ];

        return implode('; ', $directives);
    }

    /**
     * الحصول على الـ nonce الحالي (لاستخدامه في Blade)
     */
    public static function getNonce(Request $request): ?string
    {
        return $request->attributes->get('csp_nonce');
    }
}
