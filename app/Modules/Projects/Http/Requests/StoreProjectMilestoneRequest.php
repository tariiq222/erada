<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/milestones.
 *
 * The project is identified by body field `project_id` (not a route binding on
 * this endpoint — see Routes/api.php). The FormRequest authorizes against the
 * project's `update` ability via the engine (AccessDecision::can via ProjectPolicy).
 */
class StoreProjectMilestoneRequest extends FormRequest
{
    protected ?Project $project = null;

    public function authorize(): bool
    {
        $projectId = $this->input('project_id');

        // ponytail: defer a missing/empty project_id to validation rules
        // (exists:projects,id) so the response is 422 with field-level
        // errors, not a misleading 403. The controller will get the
        // validated model afterwards.
        if ($projectId === null || $projectId === '' || ! ctype_digit((string) $projectId)) {
            return true;
        }

        $project = Project::find((int) $projectId);
        if (! $project) {
            return true;
        }

        $this->project = $project;

        return $this->user()->can('update', $project);
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', 'in:day,week,month'],
            'status' => ['nullable', 'in:pending,in_progress,completed,overdue'],
        ];
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }
}
