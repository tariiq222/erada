<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Milestone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PUT/PATCH /api/milestones/{milestone}.
 *
 * Authorizes against the milestone's parent project's `update` ability via
 * the engine (AccessDecision::can via ProjectPolicy).
 */
class UpdateProjectMilestoneRequest extends FormRequest
{
    protected ?Milestone $milestone = null;

    public function authorize(): bool
    {
        $milestone = $this->route('milestone');

        if (! $milestone instanceof Milestone) {
            $milestone = Milestone::find($milestone);
        }

        if (! $milestone) {
            return false;
        }

        $this->milestone = $milestone;

        return $this->user()->can('update', $milestone->project);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'completed_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending,in_progress,completed,overdue'],
            'progress' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function getMilestone(): ?Milestone
    {
        return $this->milestone;
    }
}
