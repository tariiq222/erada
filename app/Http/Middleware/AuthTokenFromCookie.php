<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware لقراءة Token المصادقة من HttpOnly Cookie
 *
 * يقرأ الـ Token من الـ Cookie ويضيفه للـ Authorization header
 * إذا لم يكن موجوداً مسبقاً. هذا يسمح بدعم كلا الطريقتين:
 * 1. Authorization: Bearer token (للتوافق مع الإصدارات السابقة)
 * 2. HttpOnly Cookie (الطريقة الآمنة الجديدة)
 */
class AuthTokenFromCookie
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // إذا كان هناك Authorization header، استخدمه
        if ($request->bearerToken()) {
            return $next($request);
        }

        // محاولة قراءة الـ Token من الـ Cookie
        $token = $request->cookie('auth_token');

        if ($token) {
            // إضافة الـ Token للـ headers
            $request->headers->set('Authorization', 'Bearer '.$token);
            // M-14: mark that this bearer was synthesized from the cookie so the
            // CSRF middleware still requires an X-XSRF-TOKEN for this request
            // (cookie-auth IS in the CSRF threat model; raw bearer clients are not).
            $request->attributes->set('auth_from_cookie', true);
        }

        return $next($request);
    }
}
