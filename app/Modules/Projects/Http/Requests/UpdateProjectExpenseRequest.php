<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for PUT /api/projects/{project}/expenses/{expense}.
 *
 * Authorizes against the project's `update` ability via the engine
 * (AccessDecision::can via ProjectPolicy). Two cross-field invariants stay in
 * the controller (NOT in the FormRequest):
 *   - expense must belong to the project
 *   - finalized-expense lock (admin-only override)
 *   - task must belong to this project (cross-table)
 */
class UpdateProjectExpenseRequest extends FormRequest
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
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'category' => ['sometimes', 'required', 'in:'.implode(',', array_keys(ProjectExpense::CATEGORIES))],
            'expense_date' => ['sometimes', 'required', 'date'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }
}
