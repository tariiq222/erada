<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Shared\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckRole Middleware
 *
 * للتحقق من الأدوار على مستوى الـ Routes
 *
 * الاستخدام:
 * Route::middleware(['role:admin'])->...
 * Route::middleware(['role:admin,project_manager'])->... // أي دور
 * Route::middleware(['role:admin&project_manager'])->... // جميع الأدوار
 */
class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'غير مصرح - يجب تسجيل الدخول',
            ], 401);
        }

        // Super Admin يتجاوز كل الأدوار
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // تحليل الأدوار المطلوبة
        $requireAll = false;
        $roleList = [];

        foreach ($roles as $role) {
            if (str_contains($role, '&')) {
                $requireAll = true;
                $roleList = array_merge($roleList, explode('&', $role));
            } else {
                $roleList[] = $role;
            }
        }

        $roleList = array_map('trim', $roleList);

        // التحقق من الأدوار
        $hasAccess = $requireAll
            ? $user->hasAllRoles($roleList)
            : $user->hasAnyRole($roleList);

        if (! $hasAccess) {
            // تسجيل محاولة الوصول المرفوضة
            ActivityLog::logAccessDenied(
                $user->id,
                $request->method().' '.$request->path(),
                null,
                null,
                'أدوار مطلوبة: '.implode(', ', $roleList)
            );

            // Mirror to the file/stream channel so failed role authz is visible
            // in production logs without depending on the ActivityLog sink.
            Log::warning('authz.role_denied', [
                'user_id' => $user->id,
                'route' => $request->method().' '.$request->path(),
                'required_roles' => $roleList,
            ]);

            return response()->json([
                'message' => 'ليس لديك الدور المطلوب للوصول لهذا المورد',
                'required_roles' => $roleList,
            ], 403);
        }

        return $next($request);
    }
}
