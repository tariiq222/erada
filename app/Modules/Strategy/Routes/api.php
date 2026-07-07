<?php

use App\Modules\Strategy\Http\Controllers\BlockerController;
use App\Modules\Strategy\Http\Controllers\PortfolioController;
use App\Modules\Strategy\Http\Controllers\ProgramController;
use App\Modules\Strategy\Http\Controllers\ReviewController;
use App\Modules\Strategy\Http\Controllers\StrategyDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Strategy Module API Routes
|--------------------------------------------------------------------------
|
| مسارات API للتخطيط التنفيذي (PMI Standard):
| - المحافظ / الالتزامات التنفيذية (Portfolios)
| - المبادرات / البرامج (Programs)
| - مؤشرات الأداء (KPIs)
| - التعثرات (Blockers)
| - المراجعات (Reviews)
|
| الهيكل الجديد: Portfolio -> Program -> Project
|
*/

Route::middleware('auth:sanctum')->prefix('strategy')->group(function () {

    // ========================================
    // لوحة التحكم التنفيذية
    // ========================================
    Route::prefix('dashboard')->middleware('throttle:30,1')->group(function () {
        Route::get('/summary', [StrategyDashboardController::class, 'summary']);
        Route::get('/golden-chain/{type}/{id}', [StrategyDashboardController::class, 'goldenChain']);
        Route::get('/portfolio/{portfolio}/tree', [StrategyDashboardController::class, 'tree']);
    });

    // ========================================
    // المحافظ (Portfolios) - الالتزامات التنفيذية
    // ========================================
    Route::prefix('portfolios')->group(function () {
        Route::get('/list', [PortfolioController::class, 'list']);
        Route::get('/summary', [PortfolioController::class, 'summary']);
        Route::put('/{portfolio}/priority', [PortfolioController::class, 'updatePriority']);
        Route::put('/{portfolio}/strategic-status', [PortfolioController::class, 'updateStrategicStatus']);
    });
    Route::apiResource('portfolios', PortfolioController::class);

    // ========================================
    // المبادرات / البرامج (Programs)
    // PMI Standard: Portfolio -> Program -> Project
    // ========================================
    Route::prefix('programs')->group(function () {
        Route::get('/list', [ProgramController::class, 'list']);
        Route::get('/unlinked-projects', [ProgramController::class, 'unlinkedProjects']);
        Route::post('/{program}/link-project', [ProgramController::class, 'linkProject']);
        Route::delete('/{program}/unlink-project/{project}', [ProgramController::class, 'unlinkProject']);
    });
    Route::apiResource('programs', ProgramController::class);

    // ========================================
    // التعثرات
    // ========================================
    Route::post('/blockers/{blocker}/resolve', [BlockerController::class, 'resolve']);
    Route::post('/blockers/{blocker}/escalate', [BlockerController::class, 'escalate']);
    Route::apiResource('blockers', BlockerController::class);

    // ========================================
    // المراجعات (PDCA)
    // ========================================
    Route::apiResource('reviews', ReviewController::class);
});
