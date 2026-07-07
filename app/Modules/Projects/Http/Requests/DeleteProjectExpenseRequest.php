<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization for DELETE /api/projects/{project}/expenses/{expense}.
 *
 * Authorizes against the project's `update` ability via the engine
 * (AccessDecision::can via ProjectPolicy). The finalized-expense lock and the
 * "expense must belong to this project" check stay in the controller — they
 * need the resolved expense row and the admin-tier override, neither of which
 * is pure input validation.
 */
class DeleteProjectExpenseRequest extends FormRequest
{
    protected ?Project $project = null;

    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            $project = Project::find($project);
        }

        if (! $project) {
            return false;
        }

        $this->project = $project;

        return $this->user()->can('update', $project);
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
