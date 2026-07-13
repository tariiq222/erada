<?php

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Controllers\AuthController;
use App\Modules\Core\Http\Controllers\AuthorizationRoleAssignmentController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\GovernanceRulesController;
use App\Modules\Core\Http\Controllers\OrganizationController;
use App\Modules\Core\Http\Controllers\PasswordResetController;
use App\Modules\Core\Http\Controllers\RegistrationController;
use App\Modules\Core\Http\Controllers\RoleController;
use App\Modules\Core\Http\Controllers\ScopeTypeController;
use App\Modules\Core\Http\Controllers\SuperAdminDashboardController;
use App\Modules\Core\Http\Controllers\SystemSettingsController;
use App\Modules\Core\Http\Controllers\TwoFactorController;
use App\Modules\Core\Http\Controllers\UserController;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Core Module API Routes
|--------------------------------------------------------------------------
|
| مسارات API الأساسية للنظام:
| - المصادقة (Auth)
| - المستخدمين (Users)
| - لوحة التحكم (Dashboard)
| - إعدادات النظام (System Settings)
|
*/

// ========================================
// مسارات عامة (بدون مصادقة)
// ========================================

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->withoutMiddleware(ThrottleRequests::class.':api');

// Health Check للـ Deployment
Route::get('/health', function () {
    $checks = [
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'services' => [],
    ];

    // فحص قاعدة البيانات
    try {
        DB::connection()->getPdo();
        $checks['services']['database'] = 'ok';
    } catch (Exception $e) {
        $checks['services']['database'] = 'error';
        $checks['status'] = 'degraded';
    }

    // فحص Redis/Cache
    try {
        Cache::store()->get('health_check');
        $checks['services']['cache'] = 'ok';
    } catch (Exception $e) {
        $checks['services']['cache'] = 'error';
        $checks['status'] = 'degraded';
    }

    $httpStatus = $checks['status'] === 'ok' ? 200 : 503;

    return response()->json($checks, $httpStatus);
})->withoutMiddleware(ThrottleRequests::class.':api');

// إعدادات النظام العامة - بدون Rate Limiting لأنها تُستدعى عند كل تحميل للصفحة
Route::get('/settings/system', [SystemSettingsController::class, 'show'])
    ->withoutMiddleware(ThrottleRequests::class.':api');

// التحقق من 2FA (بدون مصادقة كاملة - يُستخدم أثناء تسجيل الدخول)
Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])
    ->middleware('throttle:login')
    ->withoutMiddleware(ThrottleRequests::class.':api');

// التسجيل المباشر (Direct Registration) — بدون مصادقة
Route::post('/register', [RegistrationController::class, 'register'])
    ->middleware('throttle:login')->withoutMiddleware(ThrottleRequests::class.':api');
Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:otp')->withoutMiddleware(ThrottleRequests::class.':api');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password')->withoutMiddleware(ThrottleRequests::class.':api');

// ========================================
// مسارات تتطلب مصادقة
// ========================================

Route::middleware('auth:sanctum')->group(function () {

    // المصادقة
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // الملف الشخصي
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:password');

    // تفضيل اللغة
    Route::put('/user/locale', [AuthController::class, 'updateLocale']);

    // المصادقة الثنائية (2FA)
    Route::prefix('2fa')->group(function () {
        Route::get('/status', [TwoFactorController::class, 'status']);
        Route::post('/enable', [TwoFactorController::class, 'enable']);
        Route::post('/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/disable', [TwoFactorController::class, 'disable']);
        Route::middleware('throttle:3,60')->group(function () {
            Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        });
    });

    // لوحة التحكم محمية بقدرة DASHBOARD_VIEW عبر AccessDecision::can.
    Route::prefix('dashboard')->middleware('engine_capability:'.Capability::DASHBOARD_VIEW)->group(function () {
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/advanced-stats', [DashboardController::class, 'advancedStats']);
        Route::get('/recent-projects', [DashboardController::class, 'recentProjects']);
        Route::get('/overdue-tasks', [DashboardController::class, 'overdueTasks']);
        Route::get('/my-upcoming-tasks', [DashboardController::class, 'myUpcomingTasks']);
        Route::get('/projects-by-status', [DashboardController::class, 'projectsByStatus']);
    });

    // المستخدمين
    Route::get('/users/list', [UserController::class, 'list']);
    Route::get('/users/stats', [UserController::class, 'stats']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::get('/users/{user}/security', [AuthController::class, 'getSecurityStatus']);
    Route::middleware(['throttle:admin', 'idempotency'])->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/unlock', [AuthController::class, 'unlockAccount']);
    });
    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware('throttle:delete');

    // إعدادات النظام (التحديث فقط - القراءة عامة) — global settings, super_admin only (M-01)
    Route::put('/settings/system', [SystemSettingsController::class, 'update'])
        ->middleware(['engine_capability:'.Capability::SETTINGS_EDIT, 'throttle:admin', 'idempotency']);

    // ========================================
    // إدارة الأدوار والصلاحيات
    // ========================================

    // الأدوار (System Roles) - فقط super_admin
    Route::post('/roles/assign', [RoleController::class, 'assignToUser'])
        ->middleware(['engine_capability:'.Capability::CORE_ASSIGN_ROLES, 'idempotency']);

    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->middleware('engine_capability:'.Capability::ROLES_VIEW);
        Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('engine_capability:'.Capability::ROLES_VIEW);
        Route::get('/abilities', [RoleController::class, 'abilities'])->middleware('engine_capability:'.Capability::ROLES_VIEW);
        Route::get('/scope-options', [RoleController::class, 'scopeOptions'])->middleware('engine_capability:'.Capability::ROLES_VIEW);
        Route::post('/', [RoleController::class, 'store'])->middleware(['engine_capability:'.Capability::ROLES_CREATE, 'idempotency']);
        Route::get('/{roleDefinition}', [RoleController::class, 'show'])->middleware('engine_capability:'.Capability::ROLES_VIEW);
        Route::put('/{roleDefinition}', [RoleController::class, 'update'])->middleware(['engine_capability:'.Capability::ROLES_EDIT, 'idempotency']);
        Route::delete('/{roleDefinition}', [RoleController::class, 'destroy'])->middleware('engine_capability:'.Capability::ROLES_DELETE);
    });

    // Governing departments (unified governance_rules)
    Route::prefix('governance-rules')->middleware('engine_capability:'.Capability::SETTINGS_MANAGE)->group(function () {
        Route::get('/', [GovernanceRulesController::class, 'index']);
        Route::match(['put', 'patch'], '/', [GovernanceRulesController::class, 'update']);
    });

    // Canonical authorization-role assignment reads.
    Route::prefix('authorization-role-assignments')->group(function () {
        Route::get('/user/{user}', [AuthorizationRoleAssignmentController::class, 'userAssignments']);
        Route::get('/user/{user}/access-summary', [AuthorizationRoleAssignmentController::class, 'accessSummary']);
        Route::get('/audit-logs', [AuthorizationRoleAssignmentController::class, 'auditLogs']);
    });

    // أدوار المشاريع (ضمن مسارات المشاريع)
    Route::prefix('projects/{project}/roles')->group(function () {
        Route::get('/', [AuthorizationRoleAssignmentController::class, 'projectMembers']);
        Route::post('/', [AuthorizationRoleAssignmentController::class, 'assignProjectRole'])->middleware('idempotency');
        Route::put('/{user}', [AuthorizationRoleAssignmentController::class, 'updateProjectRole'])->middleware(['idempotency']);
        Route::delete('/{user}', [AuthorizationRoleAssignmentController::class, 'removeFromProject']);
    });

    // أدوار الأقسام (فقط super_admin و admin)
    Route::prefix('departments/{department}/roles')
        ->middleware('engine_capability:'.Capability::DEPARTMENTS_ASSIGN_ROLES)
        ->group(function () {
            Route::get('/', [AuthorizationRoleAssignmentController::class, 'departmentManagers']);
            Route::post('/', [AuthorizationRoleAssignmentController::class, 'assignDepartmentRole'])->middleware('idempotency');
            Route::delete('/{user}', [AuthorizationRoleAssignmentController::class, 'removeFromDepartment']);
        });

    // ========================================
    // إدارة المؤسسات (Organizations)
    // ========================================
    Route::prefix('organizations')->group(function () {
        Route::get('/', [OrganizationController::class, 'index'])
            ->middleware('engine_capability:'.Capability::CORE_VIEW_ORGANIZATIONS);
        Route::post('/', [OrganizationController::class, 'store'])
            ->middleware(['engine_capability:'.Capability::CLUSTER_TREE_MANAGE, 'idempotency']);
        Route::get('/{organization}', [OrganizationController::class, 'show'])
            ->middleware('engine_capability:'.Capability::CORE_VIEW_ORGANIZATIONS);
        Route::put('/{organization}', [OrganizationController::class, 'update'])
            ->middleware(['engine_capability:'.Capability::CLUSTER_TREE_MANAGE, 'idempotency']);
        Route::patch('/{organization}', [OrganizationController::class, 'update'])
            ->middleware(['engine_capability:'.Capability::CLUSTER_TREE_MANAGE, 'idempotency']);
        Route::delete('/{organization}', [OrganizationController::class, 'destroy'])
            ->middleware(['engine_capability:'.Capability::CLUSTER_TREE_MANAGE, 'throttle:delete']);
    });

    // ========================================
    // أنواع النطاقات (Scope Types)
    // ========================================
    Route::prefix('scope-types')->group(function () {
        Route::get('/', [ScopeTypeController::class, 'index'])
            ->middleware('engine_capability:'.Capability::SETTINGS_VIEW);
    });

    // ========================================
    // لوحة الحوكمة على مستوى النظام (M1)
    // Read-mostly KPI / aggregated metadata. No mutations.
    // ========================================
    Route::prefix('admin')->group(function () {
        Route::get('/overview', [SuperAdminDashboardController::class, 'overview'])
            ->middleware('engine_capability:'.Capability::CORE_VIEW_ORGANIZATIONS);
        Route::get('/security/alerts', [SuperAdminDashboardController::class, 'securityAlerts'])
            ->middleware('engine_capability:'.Capability::AUDIT_VIEW);
        Route::get('/audit/recent', [SuperAdminDashboardController::class, 'auditRecent'])
            ->middleware('engine_capability:'.Capability::AUDIT_VIEW);
    });
});
