<?php

namespace App\Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Surveys\Http\Controllers\Concerns\AuthorizesSurveyAccess;
use App\Modules\Surveys\Http\Requests\DestroyDataMappingRequest;
use App\Modules\Surveys\Http\Requests\ListDataMappingTemplatesRequest;
use App\Modules\Surveys\Http\Requests\StoreDataMappingRequest;
use App\Modules\Surveys\Http\Requests\UpdateDataMappingRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Http\JsonResponse;

class DataMappingController extends Controller
{
    use AuthorizesSurveyAccess;

    public function index(ListDataMappingTemplatesRequest $request, Survey $survey): JsonResponse
    {
        // Authz (SURVEYS_VIEW on survey) owned by ListDataMappingTemplatesRequest.
        $templates = $survey->mappingTemplates()
            ->with('createdBy')
            ->get();

        return response()->json([
            'data' => $templates,
        ]);
    }

    public function store(StoreDataMappingRequest $request, Survey $survey): JsonResponse
    {
        $validated = $request->validated();

        $template = $survey->mappingTemplates()->create([
            ...$validated,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'data' => $template,
            'message' => 'تم إنشاء قالب الربط بنجاح',
        ], 201);
    }

    public function update(UpdateDataMappingRequest $request, Survey $survey, DataMappingTemplate $template): JsonResponse
    {
        // Authz + scope + content checks enforced inside UpdateDataMappingRequest.
        $template->update($request->validated());

        return response()->json([
            'data' => $template,
            'message' => 'تم تحديث قالب الربط بنجاح',
        ]);
    }

    public function destroy(DestroyDataMappingRequest $request, Survey $survey, DataMappingTemplate $template): JsonResponse
    {
        // Authz + scope + safety checks enforced inside DestroyDataMappingRequest.
        $template->delete();

        return response()->json([
            'message' => 'تم حذف قالب الربط بنجاح',
        ]);
    }

    public function availableTargets(): JsonResponse
    {
        return response()->json([
            'data' => DataMappingTemplate::getAvailableTargetModels(),
        ]);
    }
}
