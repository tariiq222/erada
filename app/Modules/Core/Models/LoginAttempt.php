<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * نموذج محاولات تسجيل الدخول
 *
 * يُستخدم لتتبع محاولات الدخول الفاشلة والناجحة
 * ودعم نظام قفل الحساب (Account Lockout)
 */
class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email',
        'ip_address',
        'user_agent',
        'successful',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * تسجيل محاولة دخول
     */
    public static function record(string $email, string $ip, ?string $userAgent, bool $successful): self
    {
        return self::create([
            'email' => strtolower($email),
            'ip_address' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            'successful' => $successful,
            'attempted_at' => now(),
        ]);
    }

    /**
     * عدد المحاولات الفاشلة الأخيرة لبريد معين
     */
    public static function recentFailedAttempts(string $email, int $minutes = 15): int
    {
        return self::where('email', strtolower($email))
            ->where('successful', false)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * عدد المحاولات الفاشلة الأخيرة من IP معين
     */
    public static function recentFailedAttemptsFromIp(string $ip, int $minutes = 15): int
    {
        return self::where('ip_address', $ip)
            ->where('successful', false)
            ->where('attempted_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * مسح سجل المحاولات الفاشلة عند النجاح
     */
    public static function clearFailedAttempts(string $email): void
    {
        self::where('email', strtolower($email))
            ->where('successful', false)
            ->delete();
    }

    /**
     * تنظيف السجلات القديمة (أقدم من 24 ساعة)
     */
    public static function cleanup(int $hours = 24): int
    {
        return self::where('attempted_at', '<', now()->subHours($hours))->delete();
    }
}
