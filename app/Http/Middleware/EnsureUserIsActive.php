<?php

namespace App\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware للتحقق من حالة حساب المستخدم على كل طلب مُصادَق
 *
 * يعالج الفجوة الزمنية بين إصدار Sanctum Token (24 ساعة) وإلغاء/قفل الحساب:
 *  - إذا تم تعطيل الحساب (`is_active = false`) بعد إصدار التوكن، يُرجع 401.
 *  - إذا تم قفل الحساب (`locked_until` في المستقبل) بعد إصدار التوكن، يُرجع 401.
 *  - إذا تم تعطيل مؤسسة المستخدم (`organization.is_active = false`) بعد إصدار
 *    التوكن، يُرجع 401 — هذا يعكس بوابة تسجيل الدخول نفسها (انظر AuthController).
 *
 * - يعمل بعد `auth:sanctum` ليقرأ المستخدم من الطلب.
 * - يمرّر الطلبات غير المُصادَقة (null user) دون اعتراض (دور Authenticate وحده).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->is_active === false) {
            return response()->json([
                'message' => 'تم تعطيل هذا الحساب. يرجى التواصل مع مدير النظام.',
                'reason' => 'account_deactivated',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->isLocked()) {
            return response()->json([
                'message' => 'الحساب مقفل مؤقتاً. يرجى المحاولة لاحقاً.',
                'reason' => 'account_locked',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // force-refresh the cached relation so a same-instance user
        // (e.g. across test requests or session-reused authenticators)
        // can never report a stale `is_active=true` after an admin flips
        // the organization inactive.
        $user->load('organization');
        $org = $user->organization;
        if ($org !== null && $org->is_active === false) {
            return response()->json([
                'message' => 'المؤسسة غير نشطة. يرجى التواصل مع مدير النظام.',
                'reason' => 'organization_inactive',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
