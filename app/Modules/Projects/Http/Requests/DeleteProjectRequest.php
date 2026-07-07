<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization for DELETE /api/projects/{project}.
 *
 * Authorizes against the project's `delete` ability via the engine
 * (AccessDecision::can via ProjectPolicy). No payload rules — delete accepts
 * an empty body.
 */
class DeleteProjectRequest extends FormRequest
{
    protected ?Project $project = null;

    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            $project = Project::find($project);
        }

        // ponytail: return true on null so route model binding's natural 404 path
        // runs (e.g. /api/projects/999999). The engine's can('delete', null)
        // would throw; returning false here would yield a misleading 403 instead
        // of the 404 the HTTP semantics demand.
        if (! $project) {
            return true;
        }

        $this->project = $project;

        return $this->user()->can('delete', $project);
    }

    public function rules(): array
    {
        return [];
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }
}
