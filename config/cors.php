<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | إعدادات CORS للتحكم في الوصول من النطاقات الخارجية
    | تم تضييق الإعدادات للأمان
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        env('APP_URL', 'http://localhost'),
        env('FRONTEND_URL'),
        // بيئة التطوير فقط - تُضاف إذا كان APP_ENV ليس production
        ...(env('APP_ENV') !== 'production' ? [
            'http://localhost:3000',
            'http://localhost:5173',
        ] : []),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 7200, // ساعتين - لتقليل طلبات preflight

    'supports_credentials' => true,

];
