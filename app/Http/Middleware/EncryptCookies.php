<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

/**
 * استثناء auth_token من التشفير لأنه Sanctum Bearer token
 * يُقرأ بواسطة AuthTokenFromCookie قبل أن يشتغل EncryptCookies
 */
class EncryptCookies extends Middleware
{
    protected $except = [
        'auth_token',
    ];
}
