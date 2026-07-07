<?php

use App\Modules\Projects\Http\Controllers\MilestoneController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\ProjectExpenseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Projects Module API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // ========================================
    // المشاريع (Projects)
    // ========================================

    Route::get('/projects/settings', [ProjectController::class, 'getSettings']);
    Route::get('/projects/creatable-departments', [ProjectController::class, 'creatableDepartments']);
    Route::get('/projects/assignable-managers', [ProjectController::class, 'assignableManagers']);
    Route::get('/projects/governing-departments', [ProjectController::class, 'getGoverningDepartments']);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::get('/projects/{project}/stats', [ProjectController::class, 'stats']);
    Route::get('/projects/{project}/activity-log', [ProjectController::class, 'activityLog']);
    Route::get('/projects/{project}/members', [ProjectController::class, 'getMembers']);
    Route::get('/projects/{project}/stakeholders', [ProjectController::class, 'getStakeholders']);
    Route::get('/projects/{project}/stakeholders/{stakeholder}', [ProjectController::class, 'getStakeholder']);

    // عمليات المشاريع الحساسة (إنشاء/تحديث/حذف)
    Route::middleware(['throttle:sensitive', 'idempotency'])->group(function () {
        // Settings + governing-departments writes: admin-only by capability,
        // but they touch the entire system settings blob / type→dept map.
        // Without throttle:sensitive an admin has an unlimited write vector.
        Route::put('/projects/settings', [ProjectController::class, 'updateSettings']);
        Route::put('/projects/governing-departments', [ProjectController::class, 'updateGoverningDepartments']);

        Route::post('/projects', [ProjectController::class, 'store']);
        Route::put('/projects/{project}', [ProjectController::class, 'update']);
        Route::patch('/projects/{project}', [ProjectController::class, 'update']);
        Route::patch('/projects/{project}/pdca-phase', [ProjectController::class, 'updatePdcaPhase']);
        Route::post('/projects/{project}/members', [ProjectController::class, 'addMember']);
        Route::put('/projects/{project}/members/{user}', [ProjectController::class, 'updateMemberRole']);
        Route::put('/projects/{project}/roles/{user}', [ProjectController::class, 'updateMemberRole']);
        Route::post('/projects/{project}/stakeholders', [ProjectController::class, 'addStakeholder']);
        Route::put('/projects/{project}/stakeholders/{stakeholder}', [ProjectController::class, 'updateStakeholder']);
    });

    // عمليات الحذف (حد أقل)
    Route::middleware('throttle:delete')->group(function () {
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
        Route::delete('/projects/{project}/members/{user}', [ProjectController::class, 'removeMember']);
        Route::delete('/projects/{project}/stakeholders/{stakeholder}', [ProjectController::class, 'removeStakeholder']);
        Route::delete('/projects/{project}/risks/{risk}', [ProjectController::class, 'removeRisk']);
    });

    // المخاطر (Risks)
    Route::middleware(['throttle:sensitive', 'idempotency'])->group(function () {
        Route::post('/projects/{project}/risks', [ProjectController::class, 'addRisk']);
        Route::put('/projects/{project}/risks/{risk}', [ProjectController::class, 'updateRisk']);
    });

    // ========================================
    // مصروفات المشاريع (Project Expenses)
    // ========================================

    Route::prefix('projects/{project}/expenses')->group(function () {
        Route::get('/', [ProjectExpenseController::class, 'index']);
        Route::get('/summary', [ProjectExpenseController::class, 'summary']);
        Route::get('/{expense}/attachment', [ProjectExpenseController::class, 'downloadAttachment']);
        Route::get('/{expense}', [ProjectExpenseController::class, 'show']);
        Route::middleware(['throttle:sensitive', 'idempotency'])->group(function () {
            Route::post('/', [ProjectExpenseController::class, 'store']);
            Route::put('/{expense}', [ProjectExpenseController::class, 'update']);
        });
        Route::delete('/{expense}', [ProjectExpenseController::class, 'destroy'])
            ->middleware('throttle:delete');
    });

    // ========================================
    // المراحل (Milestones)
    // ========================================

    Route::get('/milestones', [MilestoneController::class, 'index']);
    Route::get('/milestones/{milestone}', [MilestoneController::class, 'show']);
    Route::middleware(['throttle:sensitive', 'idempotency'])->group(function () {
        Route::post('/milestones', [MilestoneController::class, 'store']);
        Route::put('/milestones/{milestone}', [MilestoneController::class, 'update']);
        Route::patch('/milestones/{milestone}', [MilestoneController::class, 'update']);
    });
    Route::delete('/milestones/{milestone}', [MilestoneController::class, 'destroy'])
        ->middleware('throttle:delete');

    // ========================================
    // المهام (Tasks)
    // ========================================
    // The legacy `/api/tasks/*` compatibility shim was removed. All task
    // operations are served by the unified API at `/api/unified-tasks/*`
    // (see app/Modules/Tasks/Routes/api.php).
});
