<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware للتحقق من حالة حساب المستخدم على كل طلب مُصادَق
 *
 * يعالج الفجوة الزمنية بين إصدار Sanctum Token (24 ساعة) وإلغاء/قفل الحساب:
 *  - إذا تم تعطيل الحساب (`is_active = false`) بعد إصدار التوكن، يُرجع 401.
 *  - إذا تم قفل الحساب (`locked_until` في المستقبل) بعد إصدار التوكن، يُرجع 401.
 *
 * - يعمل بعد `auth:sanctum` ليقرأ المستخدم من الطلب.
 * - يمرّر الطلبات غير المُصادَقة (null user) دون اعتراض (دور Authenticate وحده).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->is_active === false) {
            return response()->json([
                'message' => 'تم تعطيل هذا الحساب. يرجى التواصل مع مدير النظام.',
                'reason' => 'account_deactivated',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (method_exists($user, 'isLocked') && $user->isLocked()) {
            return response()->json([
                'message' => 'الحساب مقفل مؤقتاً. يرجى المحاولة لاحقاً.',
                'reason' => 'account_locked',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
