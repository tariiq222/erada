<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\LoginAttempt;
use App\Modules\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * خدمة أمان المصادقة
 *
 * تدير:
 * - قفل الحساب (Account Lockout)
 * - تتبع محاولات الدخول الفاشلة
 * - Session Timeout
 */
class AuthSecurityService
{
    /**
     * الحد الأقصى لمحاولات الدخول الفاشلة قبل القفل
     */
    public const MAX_FAILED_ATTEMPTS = 5;

    /**
     * مدة القفل بالدقائق (تتصاعد مع تكرار القفل)
     */
    public const BASE_LOCKOUT_MINUTES = 15;

    /**
     * الحد الأقصى لمحاولات IP الفاشلة في الدقيقة
     */
    public const MAX_IP_ATTEMPTS = 20;

    /**
     * فترة تتبع المحاولات الفاشلة (بالدقائق)
     */
    public const TRACKING_WINDOW_MINUTES = 15;

    /**
     * مدة صلاحية الجلسة غير النشطة (بالدقائق)
     */
    public const SESSION_IDLE_TIMEOUT = 30;

    /**
     * التحقق من إمكانية تسجيل الدخول
     *
     * يعيد نتيجة موحّدة مع `reason_type` ليميز المتصل (المتحكم) بين
     * حظر IP (دفاع DoS على مستوى الشبكة) وقفل الحساب (مؤشّر تعداد مستخدمين).
     * لا يجب أن تتسرّب `reason` أو `retry_after` أو `locked_until` إلى
     * الاستجابة العامة في حالة قفل الحساب.
     *
     * @return array{allowed: bool, reason_type?: string, reason?: string, retry_after?: int, locked_until?: mixed}
     */
    public function canAttemptLogin(string $email, string $ip): array
    {
        // 1. التحقق من قفل IP (حماية من الهجمات الموزعة)
        $ipAttempts = LoginAttempt::recentFailedAttemptsFromIp($ip, self::TRACKING_WINDOW_MINUTES);
        if ($ipAttempts >= self::MAX_IP_ATTEMPTS) {
            Log::warning('IP blocked due to too many failed attempts', [
                'ip' => $ip,
                'attempts' => $ipAttempts,
                'retry_after' => self::TRACKING_WINDOW_MINUTES * 60,
            ]);

            return [
                'allowed' => false,
                'reason_type' => 'ip_blocked',
                'reason' => 'تم حظر عنوان IP مؤقتاً بسبب كثرة المحاولات الفاشلة',
                'retry_after' => self::TRACKING_WINDOW_MINUTES * 60,
            ];
        }

        // 2. التحقق من قفل الحساب
        $user = User::where('email', strtolower($email))->first();
        if ($user && $user->locked_until) {
            if (Carbon::parse($user->locked_until)->isFuture()) {
                $retryAfter = Carbon::parse($user->locked_until)->diffInSeconds(now());

                // تفاصيل القفل تذهب إلى الـ audit log فقط — لا إلى الاستجابة العامة
                // (المتحكم سيُرجع رسالة خطأ موحّدة لمنع تعداد المستخدمين)
                Log::warning('Login attempt on locked account', [
                    'email' => $email,
                    'ip' => $ip,
                    'user_id' => $user->id,
                    'locked_until' => $user->locked_until,
                    'retry_after' => $retryAfter,
                ]);

                return [
                    'allowed' => false,
                    'reason_type' => 'account_locked',
                ];
            }

            // انتهت فترة القفل - إعادة تعيين
            // forceFill: حقول أمان خارج $fillable عمداً، والكتابة هنا موثوقة (قيم من الخادم)
            $user->forceFill([
                'locked_until' => null,
                'failed_login_attempts' => 0,
            ])->save();
        }

        return ['allowed' => true];
    }

    /**
     * تسجيل محاولة دخول فاشلة
     *
     * يعيد نتيجة موحّدة بدون أي نص موجّه للمستخدم.
     * المتحكم (AuthController) مسؤول عن بناء الاستجابة العامة الموحّدة
     * (نفس الرسالة لـ: مستخدم غير موجود / كلمة مرور خاطئة / حساب مقفل)
     * لمنع تعداد المستخدمين.
     *
     * @return array{locked: bool, reason: string, remaining_attempts?: int|null, locked_until?: mixed}
     */
    public function recordFailedAttempt(string $email, string $ip, ?string $userAgent): array
    {
        // تسجيل المحاولة
        LoginAttempt::record($email, $ip, $userAgent, false);

        // تحديث عداد المحاولات الفاشلة للمستخدم
        $user = User::where('email', strtolower($email))->first();
        if ($user) {
            $failedAttempts = $user->failed_login_attempts + 1;

            $updateData = [
                'failed_login_attempts' => $failedAttempts,
                'last_failed_login_at' => now(),
            ];

            // قفل الحساب إذا تجاوز الحد
            if ($failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
                $lockoutMinutes = $this->calculateLockoutDuration($failedAttempts);
                $updateData['locked_until'] = now()->addMinutes($lockoutMinutes);

                Log::warning('Account locked due to failed login attempts', [
                    'user_id' => $user->id,
                    'email' => $email,
                    'failed_attempts' => $failedAttempts,
                    'locked_until' => $updateData['locked_until'],
                    'lockout_minutes' => $lockoutMinutes,
                    'ip' => $ip,
                ]);

                $user->forceFill($updateData)->save();

                return [
                    'locked' => true,
                    'reason' => 'account_locked',
                    'remaining_attempts' => 0,
                    'locked_until' => $updateData['locked_until'],
                ];
            }

            $user->forceFill($updateData)->save();

            return [
                'locked' => false,
                'reason' => 'wrong_password',
                'remaining_attempts' => self::MAX_FAILED_ATTEMPTS - $failedAttempts,
            ];
        }

        // مستخدم غير موجود — لا نكشف ذلك في الاستجابة العامة
        return [
            'locked' => false,
            'reason' => 'user_not_found',
            'remaining_attempts' => null,
        ];
    }

    /**
     * تسجيل محاولة دخول ناجحة
     */
    public function recordSuccessfulLogin(User $user, string $ip, ?string $userAgent): void
    {
        LoginAttempt::record($user->email, $ip, $userAgent, true);

        // إعادة تعيين عداد المحاولات الفاشلة
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login_at' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        // مسح سجل المحاولات الفاشلة القديمة
        LoginAttempt::clearFailedAttempts($user->email);
    }

    /**
     * حساب مدة القفل (تتصاعد مع تكرار المحاولات)
     */
    private function calculateLockoutDuration(int $failedAttempts): int
    {
        // القفل الأول: 15 دقيقة
        // القفل الثاني: 30 دقيقة
        // القفل الثالث: 60 دقيقة
        // الحد الأقصى: 24 ساعة
        $multiplier = floor(($failedAttempts - self::MAX_FAILED_ATTEMPTS) / self::MAX_FAILED_ATTEMPTS) + 1;
        $duration = self::BASE_LOCKOUT_MINUTES * pow(2, $multiplier - 1);

        return min($duration, 1440); // الحد الأقصى 24 ساعة
    }

    /**
     * التحقق من صلاحية الجلسة (Session Idle Timeout)
     */
    public function isSessionValid(?Carbon $lastActivity): bool
    {
        if (! $lastActivity) {
            return false;
        }

        return $lastActivity->diffInMinutes(now()) < self::SESSION_IDLE_TIMEOUT;
    }

    /**
     * إلغاء قفل حساب يدوياً (بواسطة مدير)
     */
    public function unlockAccount(User $user): void
    {
        $user->forceFill([
            'locked_until' => null,
            'failed_login_attempts' => 0,
            'last_failed_login_at' => null,
        ])->save();

        Log::info('Account unlocked manually', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    /**
     * الحصول على معلومات حالة الأمان للحساب
     */
    public function getAccountSecurityStatus(User $user): array
    {
        return [
            'is_locked' => $user->locked_until && Carbon::parse($user->locked_until)->isFuture(),
            'locked_until' => $user->locked_until,
            'failed_attempts' => $user->failed_login_attempts,
            'last_failed_login' => $user->last_failed_login_at,
            'last_login' => $user->last_login_at,
            'last_login_ip' => $user->last_login_ip,
        ];
    }

    /**
     * تنظيف السجلات القديمة (يُستدعى من Job أو Command)
     */
    public function cleanup(): array
    {
        $deletedAttempts = LoginAttempt::cleanup(24);

        return [
            'deleted_attempts' => $deletedAttempts,
        ];
    }
}
