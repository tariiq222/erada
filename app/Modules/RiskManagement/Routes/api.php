<?php

use App\Modules\RiskManagement\Http\Controllers\RiskActionController;
use App\Modules\RiskManagement\Http\Controllers\RiskAssessmentController;
use App\Modules\RiskManagement\Http\Controllers\RiskController;
use App\Modules\RiskManagement\Http\Controllers\RiskDashboardController;
use App\Modules\RiskManagement\Http\Controllers\RiskSettingsController;
use Illuminate\Support\Facades\Route;

// ========================================
// RiskManagement Module - إدارة المخاطر المؤسسية
// ========================================
//
// All routes here are mounted under the `api` prefix by the
// RiskManagementServiceProvider and inherit the `api` middleware
// group. Authentication is enforced via `auth:sanctum` at the group
// level so every endpoint requires a logged-in user.
//
// Endpoints that need a literal URL (dashboard, matrix, export) MUST
// be declared before the apiResource() so Laravel's router does not
// match them as {risk} bindings.

Route::middleware('auth:sanctum')->prefix('risk-management')->group(function () {

    // Static reports / dashboard endpoints
    Route::get('/dashboard', [RiskDashboardController::class, 'dashboard'])
        ->name('risk-management.dashboard');
    Route::get('/matrix', [RiskDashboardController::class, 'matrix'])
        ->name('risk-management.matrix');
    Route::middleware('throttle:5,1')->group(function () {
        Route::get('/export/csv', [RiskDashboardController::class, 'exportCsv'])
            ->name('risk-management.export.csv');
        Route::get('/export/pdf', [RiskDashboardController::class, 'exportPdf'])
            ->name('risk-management.export.pdf');
    });

    // Risk CRUD — literal `create` route MUST be declared before apiResource()
    // so Laravel does not match it as the implicit {risk} binding (the value
    // "create" would then fail the Postgres bigint cast and return 500).
    Route::get('risks/create', [RiskController::class, 'create'])
        ->name('risk-management.risks.create');
    Route::get('risks/creatable-departments', [RiskController::class, 'creatableDepartments'])
        ->name('risk-management.risks.creatable-departments');
    Route::apiResource('risks', RiskController::class)
        ->names('risk-management.risks');

    // Reassess + status lifecycle
    Route::get('risks/{risk}/assessments', [RiskAssessmentController::class, 'index'])
        ->name('risk-management.risks.assessments.index');
    Route::post('risks/{risk}/assessments', [RiskAssessmentController::class, 'store'])
        ->name('risk-management.risks.assessments.store');
    Route::get('risks/{risk}/status-changes', [RiskController::class, 'statusHistory'])
        ->name('risk-management.risks.status-changes.index');
    Route::post('risks/{risk}/status-changes', [RiskController::class, 'changeStatus'])
        ->name('risk-management.risks.status-changes.store');

    // Risk actions
    Route::post('risks/{risk}/actions', [RiskActionController::class, 'store'])
        ->name('risk-management.risks.actions.store');
    Route::get('actions/{action}', [RiskActionController::class, 'show'])
        ->name('risk-management.actions.show');
    Route::match(['put', 'patch'], 'actions/{action}', [RiskActionController::class, 'update'])
        ->name('risk-management.actions.update');
    Route::delete('actions/{action}', [RiskActionController::class, 'destroy'])
        ->name('risk-management.actions.destroy');
    Route::get('actions/{action}/updates', [RiskActionController::class, 'listUpdates'])
        ->name('risk-management.actions.updates.index');
    Route::post('actions/{action}/updates', [RiskActionController::class, 'addUpdate'])
        ->name('risk-management.actions.updates.store');

    // Settings (risk types + impact types) — admin-gated inside the controller.
    Route::get('/settings', [RiskSettingsController::class, 'index'])
        ->name('risk-management.settings.index');
    Route::get('/settings/governing-department', [RiskSettingsController::class, 'getGoverningDepartment'])
        ->name('risk-management.settings.governing-department.show');
    Route::match(['put', 'patch'], '/settings/governing-department', [RiskSettingsController::class, 'updateGoverningDepartment'])
        ->name('risk-management.settings.governing-department.update');
    Route::post('/settings/risk-types', [RiskSettingsController::class, 'storeRiskType'])
        ->name('risk-management.settings.risk-types.store');
    Route::match(['put', 'patch'], '/settings/risk-types/{riskType}', [RiskSettingsController::class, 'updateRiskType'])
        ->name('risk-management.settings.risk-types.update');
    Route::delete('/settings/risk-types/{riskType}', [RiskSettingsController::class, 'destroyRiskType'])
        ->name('risk-management.settings.risk-types.destroy');
    Route::post('/settings/impact-types', [RiskSettingsController::class, 'storeImpactType'])
        ->name('risk-management.settings.impact-types.store');
    Route::match(['put', 'patch'], '/settings/impact-types/{impactType}', [RiskSettingsController::class, 'updateImpactType'])
        ->name('risk-management.settings.impact-types.update');
    Route::delete('/settings/impact-types/{impactType}', [RiskSettingsController::class, 'destroyImpactType'])
        ->name('risk-management.settings.impact-types.destroy');
});
