<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PUT /api/projects/{project}/members/{user} and the
 * /roles/{user} alias. Authorization lives on the project (update), with a
 * delete-tier gate when promoting to manager (enforced in the controller).
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

        return $this->user()->can('update', $project);
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:manager,member,viewer'],
        ];
    }
}
