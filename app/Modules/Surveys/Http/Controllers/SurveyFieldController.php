<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Surveys\Http\Controllers\Concerns\AuthorizesSurveyAccess;
use App\Modules\Surveys\Http\Requests\DestroySurveyFieldRequest;
use App\Modules\Surveys\Http\Requests\ListSurveyFieldsRequest;
use App\Modules\Surveys\Http\Requests\ReorderSurveyFieldsRequest;
use App\Modules\Surveys\Http\Requests\StoreSurveyFieldRequest;
use App\Modules\Surveys\Http\Requests\UpdateSurveyFieldRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use Illuminate\Http\JsonResponse;

class SurveyFieldController extends Controller
{
    use AuthorizesSurveyAccess;

    public function index(ListSurveyFieldsRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_VIEW on survey) owned by ListSurveyFieldsRequest.
        $fields = $survey->fields()
            ->with('section')
            ->orderBy('order')
            ->get();

        return response()->json([
            'data' => $fields,
        ]);
    }

    public function store(StoreSurveyFieldRequest $request, Survey $survey): JsonResponse
    {
        $validated = $request->validated();

        $maxOrder = $survey->fields()->max('order') ?? 0;

        $field = $survey->fields()->create([
            ...$validated,
            'order' => $maxOrder + 1,
        ]);

        return response()->json([
            'data' => $field,
            'message' => 'تم إضافة الحقل بنجاح',
        ], 201);
    }

    public function update(UpdateSurveyFieldRequest $request, Survey $survey, SurveyField $field): JsonResponse
    {
        // Authz + lifecycle + scope enforced inside UpdateSurveyFieldRequest.
        $field->update($request->validated());

        return response()->json([
            'data' => $field,
            'message' => 'تم تحديث الحقل بنجاح',
        ]);
    }

    public function destroy(DestroySurveyFieldRequest $request, Survey $survey, SurveyField $field): JsonResponse
    {
        // Authz + lifecycle + scope enforced inside DestroySurveyFieldRequest.
        $field->delete();

        return response()->json([
            'message' => 'تم حذف الحقل بنجاح',
        ]);
    }

    public function reorder(ReorderSurveyFieldsRequest $request, Survey $survey): JsonResponse
    {
        // Authz + lifecycle guard + payload validation owned by
        // ReorderSurveyFieldsRequest.
        $validated = $request->validated();

        foreach ($validated['fields'] as $order => $fieldId) {
            SurveyField::where('id', $fieldId)
                ->where('survey_id', $survey->id)
                ->update(['order' => $order + 1]);
        }

        return response()->json([
            'message' => 'تم إعادة ترتيب الحقول بنجاح',
        ]);
    }
}
