<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Shared\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckPermission Middleware
 *
 * للتحقق من الصلاحيات على مستوى الـ Routes
 *
 * الاستخدام:
 * Route::middleware(['permission:view_users'])->...
 * Route::middleware(['permission:edit_projects,delete_projects'])->... // أي صلاحية
 * Route::middleware(['permission:edit_projects&delete_projects'])->... // جميع الصلاحيات
 */
class CheckPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'غير مصرح - يجب تسجيل الدخول',
            ], 401);
        }

        // Super Admin يتجاوز كل الصلاحيات
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // تحليل الصلاحيات المطلوبة
        $requireAll = str_contains($permissions, '&');
        $permissionList = $requireAll
            ? explode('&', $permissions)
            : explode(',', $permissions);

        $permissionList = array_map('trim', $permissionList);

        // التحقق من الصلاحيات
        $hasAccess = $requireAll
            ? $user->hasAllPermissions($permissionList)
            : $user->hasAnyPermission($permissionList);

        if (! $hasAccess) {
            // تسجيل محاولة الوصول المرفوضة
            ActivityLog::logAccessDenied(
                $user->id,
                $request->method().' '.$request->path(),
                null,
                null,
                'صلاحيات مطلوبة: '.implode(', ', $permissionList)
            );

            // Mirror to the file/stream channel so failed authz is visible in
            // production logs without depending on the ActivityLog sink.
            Log::warning('authz.permission_denied', [
                'user_id' => $user->id,
                'route' => $request->method().' '.$request->path(),
                'required_permissions' => $permissionList,
            ]);

            return response()->json([
                'message' => 'ليس لديك صلاحية للوصول لهذا المورد',
                'required_permissions' => $permissionList,
            ], 403);
        }

        return $next($request);
    }
}
