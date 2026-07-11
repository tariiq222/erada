<?php

use App\Modules\OVR\Http\Controllers\IncidentReportController;
use App\Modules\OVR\Http\Controllers\IncidentTypeController;
use App\Modules\OVR\Http\Controllers\OvrSettingsController;
use App\Modules\OVR\Http\Controllers\ReportCommentController;
use Illuminate\Support\Facades\Route;

// ========================================
// OVR Module - تقارير الحوادث والمخالفات
// ========================================

// Public report tracking (no auth) — limited, non-sensitive fields only.
// Route param is the per-report random tracking_token (NOT report_number)
// generated at report creation. See migration
// 2026_07_07_000005_add_tracking_token_to_incident_reports and
// IncidentReportController::publicTrack.
Route::middleware('throttle:30,1')
    ->get('/ovr/track/{tracking_token}', [IncidentReportController::class, 'publicTrack'])
    ->name('ovr.public.track');

Route::middleware('auth:sanctum')->prefix('ovr')->group(function () {

    // Departments the user may target when creating a report + governance settings
    Route::get('/incidents/creatable-departments', [IncidentReportController::class, 'creatableDepartments'])
        ->name('ovr.incidents.creatable-departments');
    Route::get('/settings/governing-department', [OvrSettingsController::class, 'getGoverningDepartment'])
        ->name('ovr.settings.governing-department.show');
    Route::match(['put', 'patch'], '/settings/governing-department', [OvrSettingsController::class, 'updateGoverningDepartment'])
        ->name('ovr.settings.governing-department.update');

    // Incident Reports
    Route::get('/incidents', [IncidentReportController::class, 'index'])
        ->name('ovr.incidents.index');
    Route::post('/incidents', [IncidentReportController::class, 'store'])
        ->name('ovr.incidents.store');
    Route::get('/incidents/recent', [IncidentReportController::class, 'recent'])
        ->name('ovr.incidents.recent');
    Route::get('/incidents/stats', [IncidentReportController::class, 'stats'])
        ->name('ovr.incidents.stats');
    // Phase CFA-09 — cluster aggregate stats (NEVER raw). Gated by
    // IncidentReportPolicy::viewStats (OVR_VIEW_STATISTICS + CLUSTER_TREE_VIEW).
    Route::get('/incidents/cluster-stats', [IncidentReportController::class, 'clusterStats'])
        ->name('ovr.incidents.cluster-stats');
    Route::get('/incidents/export', [IncidentReportController::class, 'export'])
        ->name('ovr.incidents.export');
    // Phase CFA-09 — cluster aggregate export (NEVER raw). Gated by
    // IncidentReportPolicy::exportsAggregates. OVR_EXPORT permits same-org
    // aggregates; CLUSTER_TREE_EXPORT additionally widens to descendants.
    Route::get('/incidents/cluster-export', [IncidentReportController::class, 'clusterExport'])
        ->name('ovr.incidents.cluster-export');
    Route::get('/incidents/{report}', [IncidentReportController::class, 'show'])
        ->name('ovr.incidents.show');
    Route::put('/incidents/{report}', [IncidentReportController::class, 'update'])
        ->name('ovr.incidents.update');
    Route::delete('/incidents/{report}', [IncidentReportController::class, 'destroy'])
        ->name('ovr.incidents.destroy');
    Route::get('/incidents/{report}/audit', [IncidentReportController::class, 'auditLog'])
        ->name('ovr.incidents.audit');
    Route::patch('/incidents/{report}/status', [IncidentReportController::class, 'updateStatus'])
        ->name('ovr.incidents.status');
    Route::post('/incidents/{report}/submit', [IncidentReportController::class, 'submit'])
        ->name('ovr.incidents.submit');

    // Participants (cross-department invitations).
    // Route gates on the engine capability OVR_EDIT (closest available constant to
    // "manage OVR report participants"; there is no Capability::OVR_MANAGE). The
    // controller re-checks AccessDecision against the target report so the org
    // floor + sensitive layer still run, and abort_unless enforces the same-org gate
    // (defense-in-depth against cross-org participant injection).
    Route::post('/incidents/{report}/participants', [IncidentReportController::class, 'addParticipant'])
        ->middleware('engine_capability:ovr.edit')
        ->where(['report' => '[A-Za-z0-9\-]+'])
        ->name('ovr.incidents.participants.store');
    Route::delete('/incidents/{report}/participants/{user}', [IncidentReportController::class, 'removeParticipant'])
        ->middleware('engine_capability:ovr.edit')
        ->where(['report' => '[A-Za-z0-9\-]+', 'user' => '[0-9]+'])
        ->name('ovr.incidents.participants.destroy');

    // Comments
    Route::get('/incidents/{report}/comments', [ReportCommentController::class, 'index'])
        ->name('ovr.incidents.comments.index');
    Route::post('/incidents/{report}/comments', [ReportCommentController::class, 'store'])
        ->name('ovr.incidents.comments.store');
    Route::delete('/incidents/{report}/comments/{comment}', [ReportCommentController::class, 'destroy'])
        ->name('ovr.incidents.comments.destroy');

    // Incident Types (Categories)
    // Route gates now delegate to the AuthZ engine (Capability::OVR_VIEW / OVR_MANAGE_TYPES).
    // The legacy permission:view_ovr_categories / permission:manage_ovr_categories Spatie
    // middleware is removed in favor of AccessDecision via engine_capability. The
    // controller re-checks the same Capability with a target when applicable.
    Route::get('/categories', [IncidentTypeController::class, 'index'])
        ->middleware(['throttle:60,1', 'engine_capability:ovr.view'])
        ->name('ovr.categories.index');
    Route::get('/categories/list', [IncidentTypeController::class, 'list'])
        ->middleware(['throttle:60,1', 'engine_capability:ovr.view'])
        ->name('ovr.categories.list');
    Route::post('/categories', [IncidentTypeController::class, 'store'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types'])
        ->name('ovr.categories.store');
    Route::put('/categories/{type}', [IncidentTypeController::class, 'update'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types'])
        ->name('ovr.categories.update');
    Route::delete('/categories/{type}', [IncidentTypeController::class, 'destroy'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types'])
        ->name('ovr.categories.destroy');
    Route::post('/categories/{type}/reportable-types', [IncidentTypeController::class, 'storeReportableType'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types'])
        ->name('ovr.categories.reportable-types.store');
});

Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('admin/incident-types')->group(function () {
    Route::get('/', [IncidentTypeController::class, 'index'])
        ->middleware(['throttle:60,1', 'engine_capability:ovr.view']);
    Route::get('/list', [IncidentTypeController::class, 'list'])
        ->middleware(['throttle:60,1', 'engine_capability:ovr.view']);
    Route::post('/', [IncidentTypeController::class, 'store'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types']);
    Route::put('/{type}', [IncidentTypeController::class, 'update'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types']);
    Route::delete('/{type}', [IncidentTypeController::class, 'destroy'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types']);
    Route::post('/{type}/reportable-types', [IncidentTypeController::class, 'storeReportableType'])
        ->middleware(['throttle:30,1', 'engine_capability:ovr.manage_types']);
});
