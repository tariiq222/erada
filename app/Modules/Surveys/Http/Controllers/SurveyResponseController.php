<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Http\Controllers\Concerns\AuthorizesSurveyAccess;
use App\Modules\Surveys\Http\Resources\SurveyResponseResource;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SurveyResponseController extends Controller
{
    use AuthorizesSurveyAccess;

    public function index(Request $request, Survey $survey): AnonymousResourceCollection
    {
        if (! AccessDecision::can($request->user(), Capability::SURVEYS_REVIEW_RESPONSES)) {
            abort(403, 'لا تملك صلاحية مراجعة ردود الاستبيانات');
        }
        $this->authorizeSurvey($request, $survey);
        $query = $survey->responses()
            ->with(['answers.field'])
            ->latest('submitted_at');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from')) {
            $query->whereDate('submitted_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->whereDate('submitted_at', '<=', $request->to);
        }

        $responses = $query->paginate(min((int) $request->input('per_page', 15), 100));

        return SurveyResponseResource::collection($responses);
    }

    public function show(Request $request, Survey $survey, SurveyResponse $response): SurveyResponseResource
    {
        if (! AccessDecision::can($request->user(), Capability::SURVEYS_REVIEW_RESPONSES)) {
            abort(403, 'لا تملك صلاحية مراجعة ردود الاستبيانات');
        }
        $this->authorizeSurvey($request, $survey);
        if ($response->survey_id !== $survey->id) {
            abort(404, 'الإجابة غير موجودة في هذا الاستبيان');
        }

        $response->load(['answers.field', 'answers.files', 'invitation', 'reviewer']);

        return new SurveyResponseResource($response);
    }

    public function flag(Request $request, Survey $survey, SurveyResponse $response): JsonResponse
    {
        $this->authorizeSurvey($request, $survey);
        $this->authorize('review', $response);
        if ($response->survey_id !== $survey->id) {
            return response()->json([
                'message' => 'الإجابة غير موجودة في هذا الاستبيان',
            ], 404);
        }

        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $response->update([
            'status' => ResponseStatus::Flagged,
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $validated['notes'],
        ]);

        return response()->json([
            'message' => 'تم تنبيه الإجابة بنجاح',
            'data' => new SurveyResponseResource($response),
        ]);
    }

    public function review(Request $request, Survey $survey, SurveyResponse $response): JsonResponse
    {
        $this->authorizeSurvey($request, $survey);
        $this->authorize('review', $response);
        if ($response->survey_id !== $survey->id) {
            return response()->json([
                'message' => 'الإجابة غير موجودة في هذا الاستبيان',
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:submitted,invalid,flagged',
            'notes' => 'nullable|string|max:1000',
        ]);

        $response->update([
            'status' => ResponseStatus::from($validated['status']),
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'reviewer_notes' => $validated['notes'] ?? $response->reviewer_notes,
        ]);

        return response()->json([
            'message' => 'تم مراجعة الإجابة بنجاح',
            'data' => new SurveyResponseResource($response),
        ]);
    }
}
