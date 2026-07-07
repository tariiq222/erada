<?php

use App\Modules\Performance\Http\Controllers\KpiController;
use App\Modules\Performance\Http\Controllers\KpiLinkController;
use App\Modules\Performance\Http\Controllers\KpiMeasurementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('performance')->group(function () {
    Route::get('/context/{type}/{id}/kpis', [KpiLinkController::class, 'contextKpis']);

    Route::get('/kpis/export/{format}', [KpiController::class, 'export'])->whereIn('format', ['csv', 'xlsx']);
    Route::post('/kpis/import', [KpiController::class, 'import']);

    Route::get('/kpis/{kpi}/measurements', [KpiMeasurementController::class, 'index']);
    Route::post('/kpis/{kpi}/measurements', [KpiMeasurementController::class, 'store']);

    Route::get('/kpis/{kpi}/links', [KpiLinkController::class, 'index']);
    Route::post('/kpis/{kpi}/links', [KpiLinkController::class, 'store']);
    Route::delete('/kpis/{kpi}/links/{link}', [KpiLinkController::class, 'destroy']);

    Route::apiResource('kpis', KpiController::class);
});
