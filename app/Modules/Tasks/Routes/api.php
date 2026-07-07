<?php

use App\Modules\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tasks Module API Routes
|--------------------------------------------------------------------------
|
| جميع مسارات API الخاصة بموديول المهام الموحد
| يستخدم prefix /unified-tasks لتجنب التعارض مع /tasks في Projects module
|
*/

Route::middleware(['auth:sanctum'])->prefix('unified-tasks')->group(function () {
    // مهامي الشخصية
    Route::get('my', [TaskController::class, 'myTasks'])->name('unified-tasks.my');

    // إحصائيات المهام
    Route::get('stats', [TaskController::class, 'stats'])->name('unified-tasks.stats');

    // CRUD للمهام
    Route::get('/', [TaskController::class, 'index'])->name('unified-tasks.index');
    Route::post('/', [TaskController::class, 'store'])->name('unified-tasks.store')->middleware('idempotency');
    Route::get('/{task}', [TaskController::class, 'show'])->name('unified-tasks.show');
    Route::put('/{task}', [TaskController::class, 'update'])->name('unified-tasks.update')->middleware('idempotency');
    Route::patch('/{task}', [TaskController::class, 'update'])->middleware('idempotency');
    Route::delete('/{task}', [TaskController::class, 'destroy'])->name('unified-tasks.destroy');

    // تحديث حالة المهمة فقط
    Route::patch('/{task}/status', [TaskController::class, 'updateStatus'])
        ->name('unified-tasks.update-status')
        ->middleware(['throttle:sensitive', 'idempotency']);

    // تعيين مهمة لموظف
    Route::patch('/{task}/assign', [TaskController::class, 'assign'])
        ->name('unified-tasks.assign')
        ->middleware(['throttle:sensitive', 'idempotency']);

    // سجل نشاطات المهمة
    Route::get('/{task}/activity-log', [TaskController::class, 'activityLog'])
        ->name('unified-tasks.activity-log');
});
