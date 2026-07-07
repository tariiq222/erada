<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Surveys\Http\Resources\SurveyPublicResource;
use App\Modules\Surveys\Http\Resources\SurveyResponseResource;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Services\ResponseService;
use App\Modules\Surveys\Services\VersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PublicSurveyController extends Controller
{
    public function __construct(
        protected ResponseService $responseService,
        protected VersioningService $versioningService
    ) {}

    /**
     * عرض الاستبيان بالكود
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $revision = $request->query('rev');

        $survey = $revision
            ? $this->versioningService->getRevision($code, (int) $revision)
            : $this->versioningService->getLatestPublishedByCode($code);

        if (! $survey) {
            return ApiResponse::error('الاستبيان غير موجود', [], 404);
        }

        if (! $survey->is_public) {
            return ApiResponse::error('هذا الاستبيان غير متاح للعامة', [], 403);
        }

        if ($survey->requires_auth && ! $request->user()) {
            return ApiResponse::error('يجب تسجيل الدخول لعرض هذا الاستبيان', [], 403);
        }

        if (! $survey->isActive()) {
            return ApiResponse::errorPayload('الاستبيان غير متاح حالياً', [
                'status' => $survey->status->value,
            ], 403);
        }

        // الحصول على version hash
        $version = $this->versioningService->getOrCreateVersion($survey);

        return ApiResponse::success([
            'data' => new SurveyPublicResource($survey),
            'version_hash' => $version->version_hash,
        ]);
    }

    /**
     * إرسال الإجابة بالكود
     */
    public function submit(Request $request, string $code): JsonResponse
    {
        $survey = $this->versioningService->getLatestPublishedByCode($code);

        if (! $survey || ! $survey->is_public) {
            return ApiResponse::error('الاستبيان غير موجود أو غير متاح', [], 404);
        }

        if ($survey->requires_auth && ! $request->user()) {
            return ApiResponse::error('يجب تسجيل الدخول لتقديم هذا الاستبيان', [], 403);
        }

        // التحقق من version hash
        $providedHash = $request->input('version_hash');
        if ($providedHash && ! $this->versioningService->validateVersionHash($survey, $providedHash)) {
            return ApiResponse::errorPayload('تم تحديث الاستبيان. يرجى تحديث الصفحة والمحاولة مرة أخرى.', [
                'error' => 'version_mismatch',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array|max:500',
            'respondent_name' => 'nullable|string|max:255|regex:/^[\p{L}\p{N}\s\-_.]+$/u',
            'respondent_email' => 'nullable|email|max:255',
            'respondent_phone' => 'nullable|string|max:20',
            'version_hash' => 'required|string',
            '_honey' => 'sometimes|in:',
            'completion_time' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', $validator->errors()->toArray(), 422);
        }

        try {
            $response = $this->responseService->createPublicResponse(
                $survey,
                $request->input('answers', []),
                $request
            );

            return ApiResponse::success([
                'message' => 'تم إرسال الإجابة بنجاح',
                'data' => new SurveyResponseResource($response),
                'thank_you_message' => $survey->thank_you_message,
            ], status: 201);

        } catch (ValidationException $e) {
            return ApiResponse::error('خطأ في البيانات', $e->errors(), 422);
        }
    }

    /**
     * عرض الاستبيان بالدعوة
     */
    public function showByInvitation(string $token): JsonResponse
    {
        $invitation = SurveyInvitation::where('token', $token)
            ->with('survey')
            ->first();

        if (! $invitation) {
            return response()->json([
                'message' => 'الدعوة غير موجودة',
            ], 404);
        }

        // تحديث حالة الانتهاء
        $invitation->updateExpiredStatus();

        if (! $invitation->canUse()) {
            $reason = match ($invitation->status->value) {
                'used' => 'تم استخدام هذه الدعوة مسبقاً',
                'expired' => 'انتهت صلاحية هذه الدعوة',
                'revoked' => 'تم إلغاء هذه الدعوة',
                default => 'الدعوة غير متاحة',
            };

            return response()->json([
                'message' => $reason,
                'status' => $invitation->status->value,
            ], 403);
        }

        // تسجيل الفتح
        $invitation->markAsOpened();

        $survey = $invitation->survey;
        $version = $this->versioningService->getOrCreateVersion($survey);

        return response()->json([
            'data' => new SurveyPublicResource($survey),
            'invitation' => [
                'name' => $invitation->name,
                'email' => $invitation->email,
                'department_id' => $invitation->department_id,
            ],
            'version_hash' => $version->version_hash,
        ]);
    }

    /**
     * إرسال الإجابة بالدعوة
     */
    public function submitByInvitation(Request $request, string $token): JsonResponse
    {
        return DB::transaction(function () use ($request, $token) {
            $invitation = SurveyInvitation::where('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                return response()->json([
                    'message' => 'الدعوة غير موجودة',
                ], 404);
            }

            // Eager-load survey once inside the locked region (avoids a second read).
            $invitation->setRelation('survey', $invitation->survey()->first());

            $invitation->updateExpiredStatus();

            if (! $invitation->canUse()) {
                return response()->json([
                    'message' => 'الدعوة غير متاحة للاستخدام',
                    'status' => $invitation->status->value,
                ], 403);
            }

            $survey = $invitation->survey;

            if ($survey->requires_auth && ! $request->user()) {
                return response()->json([
                    'message' => 'يجب تسجيل الدخول لتقديم هذا الاستبيان',
                ], 403);
            }

            // التحقق من version hash
            $providedHash = $request->input('version_hash');
            if ($providedHash && ! $this->versioningService->validateVersionHash($survey, $providedHash)) {
                return response()->json([
                    'message' => 'تم تحديث الاستبيان. يرجى تحديث الصفحة والمحاولة مرة أخرى.',
                    'error' => 'version_mismatch',
                ], 409);
            }

            $request->validate([
                'answers' => 'required|array|max:500',
                'respondent_name' => 'nullable|string|max:255|regex:/^[\p{L}\p{N}\s\-_.]+$/u',
                'respondent_email' => 'nullable|email|max:255',
                'version_hash' => 'required|string',
                '_honey' => 'sometimes|in:',
                'completion_time' => 'nullable|integer|min:0',
            ]);

            try {
                // استخدام بيانات الدعوة كـ respondent info
                $request->merge([
                    'respondent_name' => $invitation->name ?? $request->input('respondent_name'),
                    'respondent_email' => $invitation->email ?? $request->input('respondent_email'),
                ]);

                $response = $this->responseService->createPublicResponse(
                    $survey,
                    $request->input('answers', []),
                    $request,
                    $invitation
                );

                return response()->json([
                    'message' => 'تم إرسال الإجابة بنجاح',
                    'data' => new SurveyResponseResource($response),
                    'thank_you_message' => $survey->thank_you_message,
                ], 201);

            } catch (ValidationException $e) {
                return response()->json([
                    'message' => 'خطأ في البيانات',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    }
}
