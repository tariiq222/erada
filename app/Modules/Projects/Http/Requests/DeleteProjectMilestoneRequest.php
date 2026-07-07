<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Milestone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation/authorization for DELETE /api/milestones/{milestone}.
 *
 * Authorizes against the milestone's parent project's `update` ability via
 * the engine (AccessDecision::can via ProjectPolicy). The "milestone must have
 * no tasks" pre-condition is NOT a validation/authz rule — it stays in the
 * controller as a business invariant.
 */
class DeleteProjectMilestoneRequest extends FormRequest
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
        return [];
    }

    public function getMilestone(): ?Milestone
    {
        return $this->milestone;
    }
}
