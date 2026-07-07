<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PUT /api/projects/{project}/stakeholders/{stakeholder}.
 */
class UpdateProjectStakeholderRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', 'required', 'string', 'in:end_user,implementer,consultant,governance,operations,influencer,other'],
            'organization' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'influence' => ['nullable', 'string', 'in:low,medium,high'],
            'interest' => ['nullable', 'string', 'in:low,medium,high'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
