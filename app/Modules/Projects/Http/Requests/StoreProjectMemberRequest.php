<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/projects/{project}/members.
 *
 * The route owns the project scope; callers submit only the target user and
 * canonical role identifiers.
 */
class StoreProjectMemberRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role_id' => ['required', 'integer', 'exists:authorization_roles,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
