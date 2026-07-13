<?php

use App\Modules\Core\Authorization\Capability;
use App\Modules\HR\Http\Controllers\DepartmentCapacityRoleController;
use App\Modules\HR\Http\Controllers\DepartmentController;
use App\Modules\HR\Http\Controllers\EmployeeCertificateController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HR Module API Routes
|--------------------------------------------------------------------------
|
| مسارات API للموارد البشرية:
| - الأقسام (Departments)
|
*/

// مسارات تتطلب مصادقة
Route::middleware('auth:sanctum')->group(function () {

    // ========================================
    // الأقسام (Departments)
    // ========================================

    Route::prefix('hr')->group(function () {
        // الأقسام: القراءة تتطلب Capability::DEPARTMENTS_VIEW عبر محرّك AuthZ الموحّد (engine_capability)؛
        // والكتابة لها فحوص أدق داخل الكنترولر والـ FormRequest (DEPARTMENTS_CREATE / EDIT / DELETE / ...).
        Route::middleware('engine_capability:'.Capability::DEPARTMENTS_VIEW)->group(function () {
            Route::get('/departments/list', [DepartmentController::class, 'list']);
            Route::get('/departments/tree', [DepartmentController::class, 'tree']);
            Route::get('/departments/hierarchy', [DepartmentController::class, 'hierarchy']);
            Route::get('/departments/allowed-levels', [DepartmentController::class, 'allowedLevels']);
            Route::get('/departments/capacity-roles/available', [DepartmentCapacityRoleController::class, 'available']);
            Route::get('/departments/{department}/capacity-roles', [DepartmentCapacityRoleController::class, 'show']);
            Route::put('/departments/{department}/capacity-roles', [DepartmentCapacityRoleController::class, 'update']);
            Route::apiResource('departments', DepartmentController::class);
        });

        // ========================================
        // الموظفون (Employees = User + HR profile)
        // القراءة تتطلب Capability::HR_VIEW عبر محرّك AuthZ الموحّد (engine_capability)؛
        // والتعديل يتطلب Capability::HR_MANAGE (يُفرض داخل الكنترولر والـ FormRequest).
        // ========================================
        Route::middleware('engine_capability:'.Capability::HR_VIEW)->group(function () {
            Route::get('/employees/stats', [EmployeeController::class, 'statistics']);
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);

            // ========================================
            // Employee certificates (private storage)
            // The controller enforces manage_hr on writes and view_hr on
            // download, plus same-organization scoping.
            // ========================================
            Route::post('/employees/{employee}/certificates', [EmployeeCertificateController::class, 'store']);
            Route::delete('/certificates/{certificate}', [EmployeeCertificateController::class, 'destroy']);
        });

        // Signed, time-limited download link (the signature is the gate, so it
        // is not nested under the engine_capability:HR_VIEW middleware group; the
        // controller still checks Capability::HR_VIEW and organization scope).
        Route::middleware('signed')
            ->get('/certificates/{certificate}/download', [EmployeeCertificateController::class, 'download'])
            ->name('hr.certificates.download');
    });

    Route::prefix('admin/departments')
        ->middleware(['engine_capability:'.Capability::DEPARTMENTS_VIEW])
        ->group(function () {
            Route::get('/list', [DepartmentController::class, 'list']);
            Route::get('/tree', [DepartmentController::class, 'tree']);
            Route::get('/hierarchy', [DepartmentController::class, 'hierarchy']);
            Route::get('/allowed-levels', [DepartmentController::class, 'allowedLevels']);
            Route::get('/', [DepartmentController::class, 'index']);
            Route::post('/', [DepartmentController::class, 'store']);
            Route::get('/{department}', [DepartmentController::class, 'show']);
            Route::match(['put', 'patch'], '/{department}', [DepartmentController::class, 'update']);
            Route::delete('/{department}', [DepartmentController::class, 'destroy']);
        });
});
