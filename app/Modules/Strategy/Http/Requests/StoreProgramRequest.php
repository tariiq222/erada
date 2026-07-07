<?php

namespace App\Modules\Strategy\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Requests\Concerns\ScopesDepartmentsToOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreProgramRequest - validation + engine-only authz for creating a Program.
 *
 * The previous controller called authorizeStrategy('create'), validated
 * inline, then looked up the parent Portfolio and asserted org match on the
 * portfolio. authorize() now resolves strategy.create through
 * AccessDecision::can(); the portfolio existence rule moves to rules() and
 * the controller still loads + org-asserts the portfolio (the lookup is
 * needed for the create flow regardless).
 */
class StoreProgramRequest extends FormRequest
{
    use ScopesDepartmentsToOrganization;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_CREATE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'portfolio_id' => ['required', Rule::exists('portfolios', 'id')],
            'department_id' => ['nullable', $this->orgScopedDepartmentRule()],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'total_program_budget' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'planning', 'in_progress', 'on_hold', 'completed', 'cancelled'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'progress_calculation_method' => ['nullable', Rule::in(['weighted', 'average', 'manual'])],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
