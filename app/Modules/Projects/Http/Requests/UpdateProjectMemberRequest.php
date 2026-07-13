<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PUT /api/projects/{project}/members/{user} and the
 * /roles/{user} alias. The project scope comes exclusively from the route.
 */
class UpdateProjectMemberRequest extends FormRequest
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

        return $this->user()?->can('assignProjectRoles', $project) ?? false;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
