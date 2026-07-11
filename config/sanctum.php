<?php

use App\Http\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | النطاقات التي تدعم المصادقة عبر الـ Cookies (SPA)
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,localhost:5173,localhost:8080,127.0.0.1,127.0.0.1:8000,127.0.0.1:8080,::1',
        env('APP_URL') ? (function () {
            $appUrl = parse_url(env('APP_URL'));
            $host = $appUrl['host'] ?? null;
            $port = $appUrl['port'] ?? null;

            return $host ? ','.$host.($port ? ':'.$port : '') : '';
        })() : ''
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | حراس المصادقة المستخدمين للتحقق من الطلبات
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | مدة صلاحية الـ Token بالدقائق (null = لا تنتهي)
    | في الإنتاج: ينصح بـ 60 دقيقة (ساعة)
    |
    */

    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 60 * 24), // 24 ساعة

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | بادئة الـ Token للتمييز في السجلات
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware للمصادقة عبر الـ Cookies
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
