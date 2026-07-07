<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Http\Requests\CloseSurveyRequest;
use App\Modules\Surveys\Http\Requests\CreateNewRevisionRequest;
use App\Modules\Surveys\Http\Requests\DestroySurveyRequest;
use App\Modules\Surveys\Http\Requests\ExportSurveyRequest;
use App\Modules\Surveys\Http\Requests\ListSurveyRevisionsRequest;
use App\Modules\Surveys\Http\Requests\ListSurveysRequest;
use App\Modules\Surveys\Http\Requests\PublishSurveyRequest;
use App\Modules\Surveys\Http\Requests\StoreSurveyRequest;
use App\Modules\Surveys\Http\Requests\SurveyAnalyticsRequest;
use App\Modules\Surveys\Http\Requests\SurveyStatsRequest;
use App\Modules\Surveys\Http\Requests\UpdateSurveyRequest;
use App\Modules\Surveys\Http\Requests\ViewSurveyRequest;
use App\Modules\Surveys\Http\Resources\SurveyResource;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Services\SurveyExportService;
use App\Modules\Surveys\Services\VersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SurveyController extends Controller
{
    public function __construct(
        protected VersioningService $versioningService,
        protected SurveyExportService $exportService
    ) {}

    /**
     * قائمة الاستبيانات
     */
    public function index(ListSurveysRequest $request): AnonymousResourceCollection
    {
        // Authz (SURVEYS_VIEW) owned by ListSurveysRequest.
        $user = $request->user();

        // Null-org fail-closed floor: a non-super user without an organization_id
        // has no scope to query against, so deny rather than returning every
        // survey (or none) by accident.
        abort_if(! $user->isSuperAdmin() && $user->organization_id === null, 403);

        $query = $user->isSuperAdmin()
            ? Survey::query()
            : Survey::query()->forOrganization($user->organization_id);

        $query = $query
            ->canonical() // فقط الأصلية
            ->with(['creator', 'latestVersion'])
            ->withCount(['responses', 'fields']);

        // فلترة حسب الحالة
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // فلترة حسب النوع
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        // البحث
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $surveys = $query->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 15), 100));

        return SurveyResource::collection($surveys);
    }

    /**
     * إنشاء استبيان جديد
     */
    public function store(StoreSurveyRequest $request): JsonResponse
    {
        $user = $request->user();

        // Null-org fail-closed floor: never default organization_id to null,
        // never create an org-less survey on behalf of an org-less user.
        abort_if($user->organization_id === null, 403, 'المستخدم لا ينتمي لمؤسسة');

        $survey = Survey::create([
            ...$request->validated(),
            'organization_id' => $user->organization_id,
            'created_by' => $user->id,
            'status' => SurveyStatus::Draft,
        ]);

        return response()->json([
            'message' => 'تم إنشاء الاستبيان بنجاح',
            'data' => new SurveyResource($survey),
        ], 201);
    }

    /**
     * عرض استبيان
     */
    public function show(ViewSurveyRequest $request, Survey $survey): SurveyResource
    {
        // Authz (SURVEYS_VIEW on survey) owned by ViewSurveyRequest.

        $survey->load([
            'sections.fields',
            'fields',
            'creator',
            'mappingTemplates',
            'latestVersion',
        ]);

        $survey->loadCount(['responses', 'fields']);

        return new SurveyResource($survey);
    }

    /**
     * تحديث استبيان
     */
    public function update(UpdateSurveyRequest $request, Survey $survey): JsonResponse
    {
        // Authz + lifecycle enforced inside UpdateSurveyRequest.
        $survey->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث الاستبيان بنجاح',
            'data' => new SurveyResource($survey),
        ]);
    }

    /**
     * حذف استبيان
     */
    public function destroy(DestroySurveyRequest $request, Survey $survey): JsonResponse
    {
        // Authz + business rule enforced inside DestroySurveyRequest.
        $survey->delete();

        return response()->json([
            'message' => 'تم حذف الاستبيان بنجاح',
        ]);
    }

    /**
     * نشر الاستبيان
     */
    public function publish(PublishSurveyRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_EDIT on survey) owned by PublishSurveyRequest.

        if (! $survey->status->canPublish()) {
            return response()->json([
                'message' => 'لا يمكن نشر الاستبيان. الاستبيان ليس في حالة مسودة.',
            ], 403);
        }

        if ($survey->fields()->count() === 0) {
            return response()->json([
                'message' => 'لا يمكن نشر الاستبيان. يجب إضافة حقل واحد على الأقل.',
            ], 422);
        }

        // إنشاء version وقفل الاستبيان
        $version = $this->versioningService->createVersionAndLock($survey);

        $survey->status = SurveyStatus::Published;
        $survey->published_at = now();
        $survey->accepting_responses = true;
        $survey->save();

        return response()->json([
            'message' => 'تم نشر الاستبيان بنجاح',
            'data' => new SurveyResource($survey),
            'public_url' => $survey->getPublicUrl(),
        ]);
    }

    /**
     * إغلاق الاستبيان
     */
    public function close(CloseSurveyRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_EDIT on survey) + optional `reason` validation owned
        // by CloseSurveyRequest.

        if (! $survey->canClose()) {
            return response()->json([
                'message' => 'لا يمكن إغلاق هذا الاستبيان',
            ], 403);
        }

        $survey->status = SurveyStatus::Closed;
        $survey->closed_at = now();
        $survey->close_reason = $request->input('reason');
        $survey->accepting_responses = false;
        $survey->save();

        return response()->json([
            'message' => 'تم إغلاق الاستبيان بنجاح',
            'data' => new SurveyResource($survey),
        ]);
    }

    /**
     * إنشاء نسخة جديدة
     */
    public function createNewRevision(CreateNewRevisionRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_CREATE on survey) owned by CreateNewRevisionRequest.

        $newSurvey = $this->versioningService->createNewRevision($survey);

        return response()->json([
            'message' => 'تم إنشاء نسخة جديدة بنجاح',
            'data' => new SurveyResource($newSurvey),
        ], 201);
    }

    /**
     * الحصول على نسخ الاستبيان
     */
    public function revisions(ListSurveyRevisionsRequest $request, Survey $survey): AnonymousResourceCollection
    {
        // Authz (SURVEYS_VIEW on survey) owned by ListSurveyRevisionsRequest.

        $revisions = $this->versioningService->getAllRevisions($survey->code);

        return SurveyResource::collection($revisions);
    }

    /**
     * إحصائيات عامة
     */
    public function stats(SurveyStatsRequest $request): JsonResponse
    {
        // Authz (SURVEYS_VIEW) owned by SurveyStatsRequest.
        $user = $request->user();

        // Null-org fail-closed floor (mirrors index/store): a non-super user
        // without an org has no scope to compute stats against.
        abort_if(! $user->isSuperAdmin() && $user->organization_id === null, 403);

        $query = $user->isSuperAdmin()
            ? Survey::query()->canonical()
            : Survey::forOrganization($user->organization_id)->canonical();

        $stats = [
            'total' => (clone $query)->count(),
            'published' => (clone $query)->where('status', 'published')->count(),
            'draft' => (clone $query)->where('status', 'draft')->count(),
            'closed' => (clone $query)->where('status', 'closed')->count(),
            'by_type' => (clone $query)
                ->selectRaw('type, COUNT(*) as aggregate')
                ->groupBy('type')
                ->pluck('aggregate', 'type')
                ->map(fn ($count) => (int) $count)
                ->toArray(),
        ];

        return response()->json($stats);
    }

    /**
     * تحليلات الاستبيان
     */
    public function analytics(SurveyAnalyticsRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_REVIEW_RESPONSES on survey) owned by SurveyAnalyticsRequest.

        $responsesCount = $survey->responses()->count();
        $submittedCount = $survey->responses()->submitted()->count();
        $flaggedCount = $survey->responses()->flagged()->count();

        // إحصائيات حسب اليوم
        $dailyResponses = $survey->responses()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->limit(30)
            ->get();

        return response()->json([
            'total_responses' => $responsesCount,
            'submitted' => $submittedCount,
            'flagged' => $flaggedCount,
            'daily_responses' => $dailyResponses,
            'fields_count' => $survey->fields()->count(),
            'invitations_count' => $survey->invitations()->count(),
            'invitations_used' => $survey->invitations()->where('status', 'used')->count(),
        ]);
    }

    /**
     * تصدير الإجابات
     */
    public function export(ExportSurveyRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_REVIEW_RESPONSES on survey) + format/date validation
        // owned by ExportSurveyRequest.

        $format = $request->validated()['format'] ?? 'csv';
        $filters = $request->only(['status', 'from_date', 'to_date']);

        try {
            $path = match ($format) {
                'json' => $this->exportService->exportToJson($survey, $filters),
                default => $this->exportService->exportToCsv($survey, $filters),
            };

            $filename = basename($path);

            return response()->json([
                'message' => 'تم تصدير البيانات بنجاح',
                'filename' => $filename,
                'format' => $format,
                'responses_count' => $survey->responses()->count(),
            ]);
        } catch (\Exception $e) {
            $errorId = uniqid('survey_export_err_');
            \Log::error('Survey export error', [
                'error_id' => $errorId,
                'survey_id' => $survey->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء التصدير',
                'error_id' => $errorId,
            ], 500);
        }
    }
}
