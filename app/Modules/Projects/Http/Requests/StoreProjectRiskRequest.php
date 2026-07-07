<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/projects/{project}/risks.
 *
 * Accepts either `risk` or `description` (legacy field alias) — the controller
 * normalizes to `risk` after validation.
 */
class StoreProjectRiskRequest extends FormRequest
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
            'risk' => ['required_without:description', 'nullable', 'string', 'max:1000'],
            'description' => ['required_without:risk', 'nullable', 'string', 'max:1000'],
            'probability' => ['required', 'in:low,medium,high'],
            'impact' => ['required', 'in:low,medium,high'],
            'response' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:open,mitigated,closed'],
        ];
    }
}
