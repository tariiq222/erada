<?php

use App\Modules\Shared\Http\Controllers\ActivityLogController;
use App\Modules\Shared\Http\Controllers\AttachmentController;
use App\Modules\Shared\Http\Controllers\CommentController;
use App\Modules\Shared\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared Module API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // ========================================
    // التعليقات (Comments)
    // ========================================

    Route::get('/comments', [CommentController::class, 'index']);
    Route::middleware('throttle:sensitive')->group(function () {
        Route::post('/comments', [CommentController::class, 'store']);
        Route::put('/comments/{comment}', [CommentController::class, 'update']);
        Route::patch('/comments/{comment}', [CommentController::class, 'update']);
        Route::post('/comments/{comment}/attachments', [CommentController::class, 'addAttachments']);
    });
    Route::middleware('throttle:delete')->group(function () {
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
        Route::delete('/comments/{comment}/attachments/{attachment}', [CommentController::class, 'deleteAttachment']);
    });

    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download']);

    // ========================================
    // رفع الملفات (Uploads)
    // ========================================

    Route::prefix('upload')->middleware('throttle:uploads')->group(function () {
        Route::post('/image', [UploadController::class, 'uploadImage']);
        Route::post('/attachment', [UploadController::class, 'uploadAttachment']);
        Route::post('/logo', [UploadController::class, 'uploadLogo']);
    });

    // Authenticated, org-scoped serving of privately-stored user images (M-17).
    Route::get('/upload/image/{orgId}/{filename}', [UploadController::class, 'serveImage'])
        ->where('filename', '[A-Za-z0-9._-]+');

    // ========================================
    // سجل النشاط (Activity Log) - super_admin/admin
    // ========================================

    Route::prefix('activity-logs')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index']);
        Route::get('/export', [ActivityLogController::class, 'export']);
        Route::get('/{activityLog}', [ActivityLogController::class, 'show']);
    });

});
