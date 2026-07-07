<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Surveys\Http\Controllers\Concerns\AuthorizesSurveyAccess;
use App\Modules\Surveys\Http\Requests\DestroySurveySectionRequest;
use App\Modules\Surveys\Http\Requests\ListSurveySectionsRequest;
use App\Modules\Surveys\Http\Requests\ReorderSurveySectionsRequest;
use App\Modules\Surveys\Http\Requests\StoreSurveySectionRequest;
use App\Modules\Surveys\Http\Requests\UpdateSurveySectionRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveySection;
use Illuminate\Http\JsonResponse;

class SurveySectionController extends Controller
{
    use AuthorizesSurveyAccess;

    public function index(ListSurveySectionsRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_VIEW on survey) owned by ListSurveySectionsRequest.
        $sections = $survey->sections()
            ->orderBy('order')
            ->get();

        return response()->json([
            'data' => $sections,
        ]);
    }

    public function store(StoreSurveySectionRequest $request, Survey $survey): JsonResponse
    {
        $validated = $request->validated();

        $maxOrder = $survey->sections()->max('order') ?? 0;

        $section = $survey->sections()->create([
            ...$validated,
            'order' => $maxOrder + 1,
        ]);

        return response()->json([
            'data' => $section,
            'message' => 'تم إضافة القسم بنجاح',
        ], 201);
    }

    public function update(UpdateSurveySectionRequest $request, Survey $survey, SurveySection $section): JsonResponse
    {
        // Authz + lifecycle + scope enforced inside UpdateSurveySectionRequest.
        $section->update($request->validated());

        return response()->json([
            'data' => $section,
            'message' => 'تم تحديث القسم بنجاح',
        ]);
    }

    public function destroy(DestroySurveySectionRequest $request, Survey $survey, SurveySection $section): JsonResponse
    {
        // Authz + lifecycle + scope enforced inside DestroySurveySectionRequest.
        $section->delete();

        return response()->json([
            'message' => 'تم حذف القسم بنجاح',
        ]);
    }

    public function reorder(ReorderSurveySectionsRequest $request, Survey $survey): JsonResponse
    {
        // Authz + lifecycle guard + payload validation owned by
        // ReorderSurveySectionsRequest.
        $validated = $request->validated();

        foreach ($validated['sections'] as $order => $sectionId) {
            SurveySection::where('id', $sectionId)
                ->where('survey_id', $survey->id)
                ->update(['order' => $order + 1]);
        }

        return response()->json([
            'message' => 'تم إعادة ترتيب الأقسام بنجاح',
        ]);
    }
}
