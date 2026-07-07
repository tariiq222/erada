<?php

use App\Modules\Core\Http\Controllers\AuthController;
use App\Modules\Core\Http\Controllers\DashboardController;
use App\Modules\Core\Http\Controllers\GovernanceRulesController;
use App\Modules\Core\Http\Controllers\OrganizationController;
use App\Modules\Core\Http\Controllers\PasswordResetController;
use App\Modules\Core\Http\Controllers\RegistrationController;
use App\Modules\Core\Http\Controllers\RoleController;
use App\Modules\Core\Http\Controllers\ScopedRoleController;
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

    // لوحة التحكم - محمية بصلاحية view_dashboard
    Route::prefix('dashboard')->middleware('can:view_dashboard')->group(function () {
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
        ->middleware(['role:super_admin', 'throttle:admin', 'idempotency']);

    // ========================================
    // إدارة الأدوار والصلاحيات
    // ========================================

    // الأدوار (System Roles) - فقط super_admin
    Route::prefix('roles')->middleware('role:super_admin')->group(function () {
        Route::get('/', [RoleController::class, 'index']);
        Route::get('/permissions', [RoleController::class, 'permissions']);
        Route::get('/abilities', [RoleController::class, 'abilities']);
        Route::get('/scope-options', [RoleController::class, 'scopeOptions']);
        Route::post('/', [RoleController::class, 'store'])->middleware(['idempotency']);
        Route::get('/{roleDefinition}', [RoleController::class, 'show']);
        Route::put('/{roleDefinition}', [RoleController::class, 'update'])->middleware(['idempotency']);
        Route::delete('/{roleDefinition}', [RoleController::class, 'destroy']);
        Route::post('/assign', [RoleController::class, 'assignToUser'])->middleware('idempotency');
    });

    // Governing departments (unified governance_rules) - super_admin only
    Route::prefix('governance-rules')->middleware('role:super_admin')->group(function () {
        Route::get('/', [GovernanceRulesController::class, 'index']);
        Route::match(['put', 'patch'], '/', [GovernanceRulesController::class, 'update']);
    });

    // الأدوار السياقية (Scoped Roles)
    Route::prefix('scoped-roles')->group(function () {
        // أدوار المستخدم
        Route::get('/user/{user}', [ScopedRoleController::class, 'userScopedRoles']);
        Route::get('/user/{user}/access-summary', [ScopedRoleController::class, 'accessSummary']);

        // سجل التغييرات
        Route::get('/audit-logs', [ScopedRoleController::class, 'auditLogs']);
    });

    // أدوار المشاريع (ضمن مسارات المشاريع)
    Route::prefix('projects/{project}/roles')->group(function () {
        Route::get('/', [ScopedRoleController::class, 'projectMembers']);
        Route::post('/', [ScopedRoleController::class, 'assignProjectRole'])->middleware('idempotency');
        Route::put('/{user}', [ScopedRoleController::class, 'updateProjectRole'])->middleware(['idempotency']);
        Route::delete('/{user}', [ScopedRoleController::class, 'removeFromProject']);
    });

    // أدوار الأقسام (فقط super_admin و admin)
    Route::prefix('departments/{department}/roles')->middleware('role:super_admin,admin')->group(function () {
        Route::get('/', [ScopedRoleController::class, 'departmentManagers']);
        Route::post('/', [ScopedRoleController::class, 'assignDepartmentRole'])->middleware('idempotency');
        Route::delete('/{user}', [ScopedRoleController::class, 'removeFromDepartment']);
    });

    // ========================================
    // إدارة المؤسسات (Organizations) - super_admin فقط
    // ========================================
    Route::prefix('organizations')->middleware('role:super_admin')->group(function () {
        Route::get('/', [OrganizationController::class, 'index']);
        Route::post('/', [OrganizationController::class, 'store'])->middleware(['idempotency']);
        Route::get('/{organization}', [OrganizationController::class, 'show']);
        Route::put('/{organization}', [OrganizationController::class, 'update'])->middleware(['idempotency']);
        Route::patch('/{organization}', [OrganizationController::class, 'update'])->middleware(['idempotency']);
        Route::delete('/{organization}', [OrganizationController::class, 'destroy'])->middleware(['throttle:delete']);
    });

    // ========================================
    // أنواع النطاقات (Scope Types) - super_admin فقط
    // ========================================
    Route::prefix('scope-types')->middleware('role:super_admin')->group(function () {
        Route::get('/', [ScopeTypeController::class, 'index']);
        Route::post('/', [ScopeTypeController::class, 'store'])->middleware(['idempotency']);
        Route::get('/{scopeType}', [ScopeTypeController::class, 'show']);
        Route::put('/{scopeType}', [ScopeTypeController::class, 'update'])->middleware(['idempotency']);
        Route::patch('/{scopeType}', [ScopeTypeController::class, 'update'])->middleware(['idempotency']);
        Route::delete('/{scopeType}', [ScopeTypeController::class, 'destroy'])->middleware(['throttle:delete']);
    });

    // ========================================
    // لوحة الحوكمة على مستوى النظام (M1) — super_admin فقط
    // Read-mostly KPI / aggregated metadata. No mutations.
    // Mounted under role:super_admin middleware (engine still governs
    // per-capability decisions elsewhere; this gate enforces a single
    // governance tenant per spec §6).
    // ========================================
    Route::prefix('admin')->middleware('role:super_admin')->group(function () {
        Route::get('/overview', [SuperAdminDashboardController::class, 'overview']);
        Route::get('/security/alerts', [SuperAdminDashboardController::class, 'securityAlerts']);
        Route::get('/audit/recent', [SuperAdminDashboardController::class, 'auditRecent']);
    });
});
