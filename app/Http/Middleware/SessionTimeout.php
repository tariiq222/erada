<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware للتحقق من انتهاء صلاحية الجلسة بسبب عدم النشاط
 *
 * - يتتبع آخر نشاط للمستخدم
 * - ينهي الجلسة تلقائياً بعد فترة من عدم النشاط
 * - يُرجع response خاص لإعلام الواجهة بانتهاء الجلسة
 */
class SessionTimeout
{
    /**
     * مدة الخمول القصوى بالدقائق
     */
    private const IDLE_TIMEOUT_MINUTES = 30;

    /**
     * مفتاح Cache لتخزين آخر نشاط
     */
    private const CACHE_KEY_PREFIX = 'user_last_activity_';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $cacheKey = self::CACHE_KEY_PREFIX.$user->id;
        $lastActivity = Cache::get($cacheKey);

        // التحقق من انتهاء فترة الخمول
        if ($lastActivity) {
            $idleMinutes = (int) now()->diffInMinutes($lastActivity, true);

            if ($idleMinutes >= self::IDLE_TIMEOUT_MINUTES) {
                // إنهاء الجلسة
                $this->terminateSession($user, $cacheKey);

                return response()->json([
                    'message' => 'انتهت صلاحية الجلسة بسبب عدم النشاط',
                    'reason' => 'session_timeout',
                    'idle_minutes' => $idleMinutes,
                ], 401);
            }
        }

        // تحديث آخر نشاط
        Cache::put($cacheKey, now(), now()->addMinutes(self::IDLE_TIMEOUT_MINUTES + 5));

        $response = $next($request);

        // إضافة header يُعلم الواجهة بوقت انتهاء الجلسة المتوقع
        $response->headers->set('X-Session-Timeout', self::IDLE_TIMEOUT_MINUTES * 60);
        $response->headers->set('X-Session-Expires-At', now()->addMinutes(self::IDLE_TIMEOUT_MINUTES)->toIso8601String());

        return $response;
    }

    /**
     * إنهاء جلسة المستخدم
     */
    private function terminateSession($user, string $cacheKey): void
    {
        // حذف التوكن الحالي
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        // مسح cache النشاط
        Cache::forget($cacheKey);

        // تسجيل الحدث
        Log::info('Session terminated due to inactivity', [
            'user_id' => $user->id,
            'idle_timeout_minutes' => self::IDLE_TIMEOUT_MINUTES,
        ]);
    }

    /**
     * الحصول على مدة الخمول المسموحة (لاستخدامها في أماكن أخرى)
     */
    public static function getIdleTimeoutMinutes(): int
    {
        return self::IDLE_TIMEOUT_MINUTES;
    }

    /**
     * تحديث النشاط يدوياً (مثلاً عند heartbeat من الواجهة)
     */
    public static function refreshActivity(int $userId): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX.$userId;
        Cache::put($cacheKey, now(), now()->addMinutes(self::IDLE_TIMEOUT_MINUTES + 5));
    }

    /**
     * الحصول على الوقت المتبقي للجلسة بالثواني
     */
    public static function getRemainingTime(int $userId): ?int
    {
        $cacheKey = self::CACHE_KEY_PREFIX.$userId;
        $lastActivity = Cache::get($cacheKey);

        if (! $lastActivity) {
            return null;
        }

        $remainingMinutes = self::IDLE_TIMEOUT_MINUTES - now()->diffInMinutes($lastActivity);

        return max(0, $remainingMinutes * 60);
    }
}
