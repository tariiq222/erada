<?php

use App\Modules\Meetings\Http\Controllers\AgendaItemController;
use App\Modules\Meetings\Http\Controllers\MeetingAttendeeController;
use App\Modules\Meetings\Http\Controllers\MeetingCategoryController;
use App\Modules\Meetings\Http\Controllers\MeetingController;
use App\Modules\Meetings\Http\Controllers\MeetingResolutionController;
use App\Modules\Meetings\Http\Controllers\MeetingSettingsController;
use App\Modules\Meetings\Http\Controllers\NotificationController;
use App\Modules\Meetings\Http\Controllers\RecommendationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Meetings Module API Routes (الاجتماعات)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

Route::middleware('auth:sanctum')->prefix('recommendations')->group(function () {
    // Direction B: the legacy /api/decisions/* CRUD group is gone. Both
    // ruling and action_item lifecycle actions live here now, branched by
    // `kind` on the model + policy.
    Route::get('/list', [RecommendationController::class, 'list']);

    // Action item transitions.
    Route::post('/{recommendation}/accept', [RecommendationController::class, 'accept']);
    Route::post('/{recommendation}/defer', [RecommendationController::class, 'defer']);
    Route::post('/{recommendation}/complete', [RecommendationController::class, 'complete']);

    // Shared transition (ruling and action_item both use /reject).
    Route::post('/{recommendation}/reject', [RecommendationController::class, 'reject']);

    // Ruling-only transition (pending/deferred -> approved).
    Route::post('/{recommendation}/approve', [RecommendationController::class, 'approve']);

    Route::apiResource('/', RecommendationController::class)
        ->names('recommendations')
        ->parameters(['' => 'recommendation']);
});

Route::middleware('auth:sanctum')->prefix('meeting-settings')->group(function () {
    Route::get('/', [MeetingSettingsController::class, 'show']);
    Route::put('/', [MeetingSettingsController::class, 'update']);
});

// Phase 1 / Direction R — typed meeting outputs (recommendation | decision).
// No approve / reject / adopt / deliberate endpoints by design.
Route::middleware(['auth:sanctum', 'throttle:sensitive'])->prefix('meetings/{meeting}/resolutions')->group(function () {
    Route::get('/', [MeetingResolutionController::class, 'indexForMeeting']);
    Route::post('/', [MeetingResolutionController::class, 'storeForMeeting']);
});

Route::middleware(['auth:sanctum', 'throttle:sensitive'])->prefix('meeting-resolutions')->group(function () {
    Route::get('/', [MeetingResolutionController::class, 'index']);
    Route::get('/{resolution}', [MeetingResolutionController::class, 'show']);
    Route::patch('/{resolution}', [MeetingResolutionController::class, 'update']);
    Route::delete('/{resolution}', [MeetingResolutionController::class, 'destroy']);
    Route::post('/{resolution}/start', [MeetingResolutionController::class, 'start']);
    Route::post('/{resolution}/hold', [MeetingResolutionController::class, 'hold']);
    Route::post('/{resolution}/release-hold', [MeetingResolutionController::class, 'releaseHold']);
    Route::post('/{resolution}/convert-to-tasks', [MeetingResolutionController::class, 'convertToTasks']);
    Route::post('/{resolution}/complete', [MeetingResolutionController::class, 'complete']);
    Route::post('/{resolution}/cancel', [MeetingResolutionController::class, 'cancel']);
});

Route::middleware('auth:sanctum')->prefix('meeting-categories')->group(function () {
    Route::get('/', [MeetingCategoryController::class, 'index']);
    Route::post('/', [MeetingCategoryController::class, 'store']);
    Route::put('/{meetingCategory}', [MeetingCategoryController::class, 'update']);
    Route::delete('/{meetingCategory}', [MeetingCategoryController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->prefix('meetings')->group(function () {
    Route::get('/list', [MeetingController::class, 'list']);
    Route::get('/{meeting}/attendees', [MeetingController::class, 'attendees']);
    Route::post('/{meeting}/attendees', [MeetingAttendeeController::class, 'attach']);
    Route::put('/{meeting}/attendees/{user}', [MeetingAttendeeController::class, 'update']);
    Route::delete('/{meeting}/attendees/{user}', [MeetingAttendeeController::class, 'detach']);
    // جدول الأعمال التشاركي (Collaborative agenda)
    Route::get('/{meeting}/agenda-items', [AgendaItemController::class, 'index']);
    Route::post('/{meeting}/agenda-items', [AgendaItemController::class, 'store']);
    Route::post('/{meeting}/agenda-items/reorder', [AgendaItemController::class, 'reorder']);
    // Mutations: nested under the meeting so Laravel scopes the item by `meeting_id`
    // (P0 IDOR fix — replaces the standalone /api/agenda-items/{id} routes).
    Route::put('/{meeting}/agenda-items/{agendaItem}', [AgendaItemController::class, 'update']);
    Route::delete('/{meeting}/agenda-items/{agendaItem}', [AgendaItemController::class, 'destroy']);
    Route::post('/{meeting}/agenda-items/{agendaItem}/approve', [AgendaItemController::class, 'approve']);
    Route::post('/{meeting}/agenda-items/{agendaItem}/reject', [AgendaItemController::class, 'reject']);
    Route::post('/{meeting}/request-agenda', [MeetingController::class, 'requestAgenda']);
    Route::post('/{meeting}/start', [MeetingController::class, 'start']);
    Route::post('/{meeting}/complete', [MeetingController::class, 'complete']);
    Route::post('/{meeting}/cancel', [MeetingController::class, 'cancel']);
    Route::post('/{meeting}/minutes', [MeetingController::class, 'updateMinutes']);
    Route::apiResource('/', MeetingController::class)
        ->names('meetings')
        ->parameters(['' => 'meeting']);
});
