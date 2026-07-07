<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PUT /api/projects/{project}/risks/{risk}.
 */
class UpdateProjectRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            $project = Project::find($project);
        }

        if (! $project) {
            return false;
        }

        return $this->user()->can('update', $project);
    }

    public function rules(): array
    {
        return [
            'risk' => ['sometimes', 'required', 'string', 'max:1000'],
            'probability' => ['sometimes', 'required', 'in:low,medium,high'],
            'impact' => ['sometimes', 'required', 'in:low,medium,high'],
            'response' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:open,mitigated,closed'],
        ];
    }
}
