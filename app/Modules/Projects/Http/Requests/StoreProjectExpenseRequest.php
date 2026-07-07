<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/projects/{project}/expenses.
 *
 * Authorizes against the project's `update` ability via the engine
 * (AccessDecision::can via ProjectPolicy). The "task must belong to this
 * project" check is a cross-field rule (it needs the resolved expense record)
 * and stays in the controller — only pure input shape lives here.
 */
class StoreProjectExpenseRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'category' => ['required', 'in:'.implode(',', array_keys(ProjectExpense::CATEGORIES))],
            'expense_date' => ['required', 'date'],
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
