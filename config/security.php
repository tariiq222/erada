<?php

return [

    /*
    |--------------------------------------------------------------------------
    | إعدادات الأمان
    |--------------------------------------------------------------------------
    |
    | إعدادات الأمان المختلفة للنظام
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Account Lockout
    |--------------------------------------------------------------------------
    */

    'lockout' => [
        // الحد الأقصى لمحاولات الدخول الفاشلة قبل القفل
        'max_attempts' => (int) env('SECURITY_MAX_LOGIN_ATTEMPTS', 5),

        // مدة القفل الأساسية بالدقائق
        'lockout_minutes' => (int) env('SECURITY_LOCKOUT_MINUTES', 15),

        // الحد الأقصى لمحاولات IP
        'max_ip_attempts' => (int) env('SECURITY_MAX_IP_ATTEMPTS', 20),

        // فترة تتبع المحاولات بالدقائق
        'tracking_window_minutes' => (int) env('SECURITY_TRACKING_WINDOW', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Timeout
    |--------------------------------------------------------------------------
    */

    'session' => [
        // مدة الخمول القصوى بالدقائق قبل إنهاء الجلسة
        'idle_timeout_minutes' => (int) env('SESSION_IDLE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication (2FA)
    |--------------------------------------------------------------------------
    */

    'two_factor' => [
        // تفعيل 2FA
        'enabled' => (bool) env('TWO_FACTOR_ENABLED', true),

        // إجبار 2FA للأدوار الإدارية
        'required_for_admins' => (bool) env('TWO_FACTOR_REQUIRED_ADMINS', true),

        // طول كود TOTP
        'code_length' => 6,

        // فترة صلاحية الكود بالثواني
        'time_step' => 30,

        // عدد أكواد الاسترداد
        'recovery_codes_count' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Whitelisting
    |--------------------------------------------------------------------------
    */

    'ip_whitelist' => [
        // تفعيل فحص IP للوحة الإدارة
        'enabled' => (bool) env('ADMIN_IP_WHITELIST_ENABLED', false),

        // تطبيق الفحص في بيئة التطوير
        'enforce_in_dev' => (bool) env('ADMIN_IP_WHITELIST_DEV', false),

        // قائمة العناوين المسموح بها (إضافة إلى localhost)
        'addresses' => array_filter(
            explode(',', env('ADMIN_IP_WHITELIST', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    */

    'password' => [
        // الحد الأدنى لطول كلمة المرور
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 8),

        // اشتراط حروف كبيرة وصغيرة
        'require_mixed_case' => (bool) env('PASSWORD_REQUIRE_MIXED_CASE', true),

        // اشتراط أرقام
        'require_numbers' => (bool) env('PASSWORD_REQUIRE_NUMBERS', true),

        // اشتراط رموز خاصة
        'require_symbols' => (bool) env('PASSWORD_REQUIRE_SYMBOLS', true),

        // منع كلمات المرور الشائعة
        'block_common' => (bool) env('PASSWORD_BLOCK_COMMON', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Readiness
    |--------------------------------------------------------------------------
    |
    | إعدادات فحص الجاهزية للإنتاج
    | تُستهلك عبر App\Support\ProductionReadiness\ProductionReadinessChecklist
    |
    */

    'production_readiness' => [
        // قائمة البروكسيات الموثوقة (مفصولة بفواصل)
        'trusted_proxies' => (string) env('TRUSTED_PROXIES', ''),

        // رؤوس الطلب الموثوقة
        'trusted_headers' => (string) env('TRUSTED_HEADERS', ''),

        // هل تم نشر مجدول المهام (scheduler) في الإنتاج
        'scheduler_deployed' => (bool) env('SCHEDULER_DEPLOYED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging
    |--------------------------------------------------------------------------
    */

    'audit' => [
        // تسجيل محاولات الدخول
        'log_login_attempts' => (bool) env('AUDIT_LOG_LOGINS', true),

        // تسجيل تغييرات كلمة المرور
        'log_password_changes' => (bool) env('AUDIT_LOG_PASSWORD', true),

        // تسجيل محاولات الوصول المرفوضة
        'log_access_denied' => (bool) env('AUDIT_LOG_ACCESS_DENIED', true),

        // مدة الاحتفاظ بالسجلات بالأيام
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 90),
    ],

];
