<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Surveys\Http\Requests\ApplyDataImportRequest;
use App\Modules\Surveys\Http\Requests\ApproveDataImportRequest;
use App\Modules\Surveys\Http\Requests\BulkApproveDataImportRequest;
use App\Modules\Surveys\Http\Requests\BulkRejectDataImportRequest;
use App\Modules\Surveys\Http\Requests\RejectDataImportRequest;
use App\Modules\Surveys\Http\Requests\RetryDataImportRequest;
use App\Modules\Surveys\Http\Resources\DataImportRequestResource;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Services\DataMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class DataImportController extends Controller
{
    public function __construct(
        protected DataMappingService $mappingService
    ) {}

    /**
     * Defense-in-depth: per-record org-floor, runs AFTER the FormRequest
     * engine gate. The engine's SURVEYS_REVIEW_RESPONSES check on the model
     * is the primary authz; this method prevents per-record cross-org access
     * when an admin of org A targets a data-import from org B.
     */
    protected function authorizeImportRequest(Request $request, DataImportRequest $importRequest): void
    {
        $user = $request->user();

        // super_admin يتجاوز (مرآة AuthorizesSurveyAccess) — ليست فحص Gate، فالتجاوز صريح هنا
        if ($user?->isSuperAdmin()) {
            return;
        }

        $survey = $importRequest->response?->survey;

        // deny-not-bypass: لا استبيان OR مؤسسة مختلفة => 403
        // (null-org user: org_id=null !== survey.org => حجب)
        if (! $survey || $survey->organization_id !== $user?->organization_id) {
            abort(403, 'غير مصرح لك بالوصول لهذا الطلب');
        }
    }

    /**
     * قائمة طلبات الاستيراد
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = DataImportRequest::query()
            ->with(['response.survey', 'template', 'reviewer'])
            ->whereHas('response.survey', function ($q) use ($user) {
                // scope-or-deny: كل مستخدم غير super_admin محصور بمؤسسته
                // (null-org => where organization_id = null => صفر صفوف = حجب آمن)
                if (! $user?->isSuperAdmin()) {
                    $q->where('organization_id', $user?->organization_id);
                }
            });

        // فلترة حسب الحالة
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // فلترة حسب الجدول المستهدف
        if ($table = $request->query('target_table')) {
            $query->where('target_table', $table);
        }

        // فلترة حسب الاستبيان
        if ($surveyId = $request->query('survey_id')) {
            $query->whereHas('response', function ($q) use ($surveyId) {
                $q->where('survey_id', $surveyId);
            });
        }

        $requests = $query->orderByDesc('requested_at')
            ->paginate(min((int) $request->query('per_page', 15), 100));

        return DataImportRequestResource::collection($requests)->additional(['success' => true]);
    }

    /**
     * عرض طلب استيراد
     */
    public function show(Request $httpRequest, DataImportRequest $request): JsonResponse
    {
        $this->authorizeImportRequest($httpRequest, $request);

        $request->load(['response.survey', 'response.answers', 'template', 'reviewer']);

        return ApiResponse::successPayload((new DataImportRequestResource($request))->resolve($httpRequest));
    }

    /**
     * اعتماد طلب استيراد
     */
    public function approve(ApproveDataImportRequest $httpRequest, DataImportRequest $request): JsonResponse
    {
        $this->authorizeImportRequest($httpRequest, $request);

        if (! $request->canApprove()) {
            return ApiResponse::error('لا يمكن اعتماد هذا الطلب', [], 403);
        }

        $notes = $httpRequest->input('notes');
        $request->approve($httpRequest->user(), $notes);

        return ApiResponse::success([
            'message' => 'تم اعتماد الطلب بنجاح',
            'data' => new DataImportRequestResource($request),
        ]);
    }

    /**
     * رفض طلب استيراد
     */
    public function reject(RejectDataImportRequest $httpRequest, DataImportRequest $request): JsonResponse
    {
        $this->authorizeImportRequest($httpRequest, $request);

        if (! $request->canReject()) {
            return ApiResponse::error('لا يمكن رفض هذا الطلب', [], 403);
        }

        $request->reject($httpRequest->user(), $httpRequest->input('reason'));

        return ApiResponse::success([
            'message' => 'تم رفض الطلب',
            'data' => new DataImportRequestResource($request),
        ]);
    }

    /**
     * تطبيق طلب استيراد معتمد
     */
    public function apply(ApplyDataImportRequest $httpRequest, DataImportRequest $request): JsonResponse
    {
        $this->authorizeImportRequest($httpRequest, $request);

        return DB::transaction(function () use ($request) {
            $locked = DataImportRequest::where('id', $request->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->applied_at !== null || ! $locked->canApply()) {
                return ApiResponse::error('الطلب غير جاهز للتطبيق', [], 403);
            }

            $success = $this->mappingService->applyImportRequest($locked);

            if ($success) {
                return ApiResponse::success([
                    'message' => 'تم تطبيق الطلب بنجاح',
                    'data' => new DataImportRequestResource($locked->fresh()),
                ]);
            }

            return ApiResponse::errorPayload('فشل تطبيق الطلب', [
                'error' => $locked->error_message,
            ], 500);
        });
    }

    /**
     * اعتماد عدة طلبات
     */
    public function bulkApprove(BulkApproveDataImportRequest $httpRequest): JsonResponse
    {
        $user = $httpRequest->user();
        $ids = $httpRequest->input('ids');

        $approved = 0;
        $failed = 0;

        $requests = DataImportRequest::whereIn('id', $ids)
            ->whereHas('response.survey', function ($q) use ($user) {
                // scope-or-deny: كل مستخدم غير super_admin محصور بمؤسسته
                // (null-org => where organization_id = null => صفر صفوف = حجب آمن)
                if (! $user?->isSuperAdmin()) {
                    $q->where('organization_id', $user?->organization_id);
                }
            })
            ->pending()
            ->get();

        foreach ($requests as $request) {
            if ($request->approve($user)) {
                $approved++;
            } else {
                $failed++;
            }
        }

        return ApiResponse::success([
            'message' => "تم اعتماد {$approved} طلب",
            'approved' => $approved,
            'failed' => $failed,
        ]);
    }

    /**
     * إعادة محاولة طلب فاشل
     */
    public function retry(RetryDataImportRequest $httpRequest, DataImportRequest $request): JsonResponse
    {
        $this->authorizeImportRequest($httpRequest, $request);

        return DB::transaction(function () use ($request) {
            $locked = DataImportRequest::where('id', $request->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->applied_at !== null || ! $locked->status->canRetry()) {
                return ApiResponse::error('لا يمكن إعادة محاولة هذا الطلب', [], 403);
            }

            $locked->resetForRetry();

            $success = $this->mappingService->applyImportRequest($locked);

            if ($success) {
                return ApiResponse::success([
                    'message' => 'تم إعادة محاولة الطلب بنجاح',
                    'data' => new DataImportRequestResource($locked->fresh()),
                ]);
            }

            return ApiResponse::errorPayload('فشلت إعادة المحاولة', [
                'error' => $locked->error_message,
            ], 500);
        });
    }

    /**
     * رفض عدة طلبات
     */
    public function bulkReject(BulkRejectDataImportRequest $httpRequest): JsonResponse
    {
        $user = $httpRequest->user();
        $ids = $httpRequest->input('ids');
        $reason = $httpRequest->input('reason');

        $rejected = 0;

        $requests = DataImportRequest::whereIn('id', $ids)
            ->whereHas('response.survey', function ($q) use ($user) {
                // scope-or-deny: كل مستخدم غير super_admin محصور بمؤسسته
                // (null-org => where organization_id = null => صفر صفوف = حجب آمن)
                if (! $user?->isSuperAdmin()) {
                    $q->where('organization_id', $user?->organization_id);
                }
            })
            ->pending()
            ->get();

        foreach ($requests as $request) {
            if ($request->reject($user, $reason)) {
                $rejected++;
            }
        }

        return ApiResponse::success([
            'message' => "تم رفض {$rejected} طلب",
            'rejected' => $rejected,
        ]);
    }
}
