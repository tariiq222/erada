<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Requests\Concerns\ScopesDepartmentsToOrganization;
use App\Modules\Strategy\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateProgramRequest - validation + engine-only authz for editing a Program.
 *
 * The previous controller called authorizeStrategy('update') plus
 * assertSameOrganization() and validated inline. authorize() now resolves
 * strategy.edit through AccessDecision::can() against the bound Program;
 * the organization guard stays in the controller (matches the existing
 * trait flow used elsewhere in this module).
 */
class UpdateProgramRequest extends FormRequest
{
    use ScopesDepartmentsToOrganization;

    protected ?Program $program = null;

    public function authorize(): bool
    {
        $program = $this->route('program');

        if (! $program instanceof Program) {
            return false;
        }

        $this->program = $program;

        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_EDIT, $program);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'portfolio_id' => ['sometimes', 'required', Rule::exists('portfolios', 'id')],
            'department_id' => ['nullable', $this->orgScopedDepartmentRule()],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'total_program_budget' => ['nullable', 'numeric', 'min:0'],
            'spent_amount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'progress' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'progress_calculation_method' => ['nullable', Rule::in(['weighted', 'average', 'manual'])],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }
}
