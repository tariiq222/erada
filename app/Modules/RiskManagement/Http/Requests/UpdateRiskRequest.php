<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesDepartmentsToOrganization;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Http\Requests\Concerns\ValidatesRiskableOwnership;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class UpdateRiskRequest extends FormRequest
{
    use ScopesDepartmentsToOrganization;
    use ValidatesRiskableOwnership;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('risk')) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'discovery_date' => ['sometimes', 'required', 'date'],
            'type' => ['sometimes', 'required', Rule::in(array_column(RiskType::cases(), 'value'))],
            'department_id' => ['nullable', $this->orgScopedDepartmentRule()],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', $this->orgScopedUserRule()],
            'stakeholder_ids' => ['nullable', 'array'],
            'stakeholder_ids.*' => ['integer', $this->orgScopedUserRule()],
            'preventive_measures' => ['nullable', 'string', 'max:5000'],
            'target_close_date' => ['nullable', 'date'],
            'response_type' => ['sometimes', 'required', Rule::in(array_column(RiskResponseType::cases(), 'value'))],
            'riskable_type' => ['nullable', 'string', Rule::in(['project', 'program', 'portfolio', 'task'])],
            'riskable_id' => ['nullable', 'integer', 'required_with:riskable_type'],
            'current_likelihood' => ['prohibited'],
            'current_impact' => ['prohibited'],
            'current_score' => ['prohibited'],
            'current_level' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function orgScopedUserRule(): Exists
    {
        $rule = Rule::exists('users', 'id');
        $user = $this->user();

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        return $rule->where('organization_id', $user->organization_id);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            // M-08: re-pointing the risk at a cross-org riskable is rejected.
            $this->validateRiskableOwnership($v);

            // M-07: moving the risk to a different department requires create
            // authority on that department (same-org is already enforced by the
            // org-scoped exists rule above).
            if (! $this->has('department_id')) {
                return;
            }
            $risk = $this->route('risk');
            $newDeptId = $this->input('department_id');
            $newDeptId = ($newDeptId !== null && $newDeptId !== '') ? (int) $newDeptId : null;
            if ($newDeptId === ($risk?->department_id)) {
                return;
            }
            if (! app(RiskAuthorizationService::class)->canCreate($this->user(), $newDeptId)) {
                $v->errors()->add('department_id', 'ليس لديك صلاحية على هذه الإدارة');
            }
        });
    }
}
