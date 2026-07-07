<?php

use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Http\Controllers\DataImportController;
use App\Modules\Surveys\Http\Controllers\DataMappingController;
use App\Modules\Surveys\Http\Controllers\PublicSurveyController;
use App\Modules\Surveys\Http\Controllers\SurveyController;
use App\Modules\Surveys\Http\Controllers\SurveyFieldController;
use App\Modules\Surveys\Http\Controllers\SurveyInvitationController;
use App\Modules\Surveys\Http\Controllers\SurveyResponseController;
use App\Modules\Surveys\Http\Controllers\SurveySectionController;
use Illuminate\Support\Facades\Route;

// ========================================
// الاستبيانات العامة (بدون مصادقة)
// ========================================
Route::prefix('surveys/public')->group(function () {
    // عرض الاستبيان بالكود
    Route::get('/{code}', [PublicSurveyController::class, 'show'])
        ->name('surveys.public.show');

    // إرسال الإجابة
    Route::post('/{code}/submit', [PublicSurveyController::class, 'submit'])
        ->middleware('throttle:survey-submit')
        ->name('surveys.public.submit');

    // عرض الاستبيان بالدعوة
    Route::get('/invitation/{token}', [PublicSurveyController::class, 'showByInvitation'])
        ->name('surveys.public.invitation.show');

    // إرسال الإجابة بالدعوة
    Route::post('/invitation/{token}/submit', [PublicSurveyController::class, 'submitByInvitation'])
        ->middleware('throttle:survey-submit')
        ->name('surveys.public.invitation.submit');
});

// ========================================
// مسارات تتطلب مصادقة
// ========================================
Route::middleware('auth:sanctum')->group(function () {
    // الطبقة الأساسية: أي تعامل مع الاستبيانات يتطلب view_surveys (super_admin يتجاوز).
    // الصلاحيات الأدق (create/edit/delete/view_survey_responses) تُضاف على المسارات المعنية.
    Route::prefix('surveys')->middleware('engine_capability:'.Capability::SURVEYS_VIEW)->group(function () {
        // ========================================
        // الجداول المتاحة للربط (يجب أن يكون قبل apiResource)
        // ========================================
        Route::get('/mapping-targets', [DataMappingController::class, 'availableTargets'])
            ->name('surveys.mapping-targets');

        // ========================================
        // إدارة الاستبيانات
        // ========================================
        Route::get('/stats', [SurveyController::class, 'stats'])
            ->name('surveys.stats');

        Route::post('/{survey}/publish', [SurveyController::class, 'publish'])
            ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
            ->name('surveys.publish');

        Route::post('/{survey}/close', [SurveyController::class, 'close'])
            ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
            ->name('surveys.close');

        Route::post('/{survey}/new-revision', [SurveyController::class, 'createNewRevision'])
            ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
            ->name('surveys.new-revision');

        Route::get('/{survey}/analytics', [SurveyController::class, 'analytics'])
            ->name('surveys.analytics');

        Route::get('/{survey}/export', [SurveyController::class, 'export'])
            ->middleware('engine_capability:'.Capability::SURVEYS_REVIEW_RESPONSES)
            ->name('surveys.export');

        // نسخ الاستبيان
        Route::get('/{survey}/revisions', [SurveyController::class, 'revisions'])
            ->name('surveys.revisions');

        // CRUD صريح بدل apiResource لفرض صلاحية لكل عملية
        Route::get('/', [SurveyController::class, 'index'])->name('surveys.index');
        Route::post('/', [SurveyController::class, 'store'])
            ->middleware('engine_capability:'.Capability::SURVEYS_CREATE)->name('surveys.store');
        Route::get('/{survey}', [SurveyController::class, 'show'])->name('surveys.show');
        Route::match(['put', 'patch'], '/{survey}', [SurveyController::class, 'update'])
            ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)->name('surveys.update');
        Route::delete('/{survey}', [SurveyController::class, 'destroy'])
            ->middleware('engine_capability:'.Capability::SURVEYS_DELETE)->name('surveys.destroy');

        // ========================================
        // أقسام الاستبيان
        // ========================================
        Route::prefix('{survey}/sections')->group(function () {
            Route::get('/', [SurveySectionController::class, 'index'])
                ->name('surveys.sections.index');
            Route::post('/', [SurveySectionController::class, 'store'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.sections.store');
            Route::put('/{section}', [SurveySectionController::class, 'update'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.sections.update');
            Route::delete('/{section}', [SurveySectionController::class, 'destroy'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.sections.destroy');
            Route::post('/reorder', [SurveySectionController::class, 'reorder'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.sections.reorder');
        });

        // ========================================
        // حقول الاستبيان
        // ========================================
        Route::prefix('{survey}/fields')->group(function () {
            Route::get('/', [SurveyFieldController::class, 'index'])
                ->name('surveys.fields.index');
            Route::post('/', [SurveyFieldController::class, 'store'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.fields.store');
            Route::put('/{field}', [SurveyFieldController::class, 'update'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.fields.update');
            Route::delete('/{field}', [SurveyFieldController::class, 'destroy'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.fields.destroy');
            Route::post('/reorder', [SurveyFieldController::class, 'reorder'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.fields.reorder');
        });

        // ========================================
        // الإجابات
        // ========================================
        // Phase 8-D: `permission:view_survey_responses` (Spatie) → engine_capability.
        // تصحيح Phase 1 القديم: الـ route يعرض response data (PII) لا survey metadata،
        // فالـ capability الصحيح هو SURVEYS_REVIEW_RESPONSES (نفسه المستخدم في
        // SurveyResponseController::authorize() و SurveyController::export::authorize()).
        Route::prefix('{survey}/responses')->middleware('engine_capability:'.Capability::SURVEYS_REVIEW_RESPONSES)->group(function () {
            Route::get('/', [SurveyResponseController::class, 'index'])
                ->name('surveys.responses.index');
            Route::get('/{response}', [SurveyResponseController::class, 'show'])
                ->name('surveys.responses.show');
            Route::post('/{response}/flag', [SurveyResponseController::class, 'flag'])
                ->name('surveys.responses.flag');
            Route::post('/{response}/review', [SurveyResponseController::class, 'review'])
                ->name('surveys.responses.review');
        });

        // ========================================
        // دعوات الاستبيان
        // ========================================
        Route::prefix('{survey}/invitations')->group(function () {
            Route::get('/', [SurveyInvitationController::class, 'index'])
                ->name('surveys.invitations.index');
            Route::post('/', [SurveyInvitationController::class, 'store'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.invitations.store');
            Route::post('/bulk', [SurveyInvitationController::class, 'bulkCreate'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.invitations.bulk');
            Route::post('/{invitation}/resend', [SurveyInvitationController::class, 'resend'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.invitations.resend');
            Route::delete('/{invitation}', [SurveyInvitationController::class, 'destroy'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.invitations.destroy');
            Route::post('/{invitation}/revoke', [SurveyInvitationController::class, 'revoke'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.invitations.revoke');
        });

        // ========================================
        // قوالب الربط
        // ========================================
        Route::prefix('{survey}/mappings')->group(function () {
            Route::get('/', [DataMappingController::class, 'index'])
                ->name('surveys.mappings.index');
            Route::post('/', [DataMappingController::class, 'store'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.mappings.store');
            Route::put('/{template}', [DataMappingController::class, 'update'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.mappings.update');
            Route::delete('/{template}', [DataMappingController::class, 'destroy'])
                ->middleware('engine_capability:'.Capability::SURVEYS_EDIT)
                ->name('surveys.mappings.destroy');
        });
    });

    // ========================================
    // طلبات استيراد البيانات
    // ========================================
    Route::prefix('data-imports')->group(function () {
        // قراءة فقط: محصورة بالمؤسسة عبر authorizeImportRequest/scope — بدون صلاحية مراجعة
        Route::get('/', [DataImportController::class, 'index'])
            ->name('data-imports.index');
        Route::get('/{request}', [DataImportController::class, 'show'])
            ->name('data-imports.show');

        // العمليات المؤثّرة (مراجعة/تطبيق) تتطلب القدرة engine-only surveys.review_data_imports
        // (super_admin يتجاوز عبر AccessDecision::can). Phase 8-C: كانت permission:review_data_imports
        // (Spatie) ثم نُقلت إلى engine_capability: Capability::SURVEYS_REVIEW_DATA_IMPORTS.
        Route::middleware('engine_capability:'.Capability::SURVEYS_REVIEW_DATA_IMPORTS)->group(function () {
            Route::post('/{request}/approve', [DataImportController::class, 'approve'])
                ->name('data-imports.approve');
            Route::post('/{request}/reject', [DataImportController::class, 'reject'])
                ->name('data-imports.reject');
            Route::post('/bulk-approve', [DataImportController::class, 'bulkApprove'])
                ->name('data-imports.bulk-approve');
            Route::post('/bulk-reject', [DataImportController::class, 'bulkReject'])
                ->name('data-imports.bulk-reject');
            Route::post('/{request}/apply', [DataImportController::class, 'apply'])
                ->name('data-imports.apply');
            Route::post('/{request}/retry', [DataImportController::class, 'retry'])
                ->name('data-imports.retry');
        });
    });
});
