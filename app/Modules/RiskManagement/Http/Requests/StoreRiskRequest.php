<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskType;
use App\Modules\RiskManagement\Http\Requests\Concerns\ValidatesRiskableOwnership;
use App\Modules\RiskManagement\Services\RiskAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreRiskRequest extends FormRequest
{
    use ValidatesRiskableOwnership;

    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $departmentId = $this->input('department_id');

        return app(RiskAuthorizationService::class)->canCreate(
            $user,
            $departmentId !== null && $departmentId !== '' ? (int) $departmentId : null
        );
    }

    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'discovery_date' => ['required', 'date'],
            'type' => ['required', Rule::in(array_column(RiskType::cases(), 'value'))],
            'department_id' => ['nullable', Rule::exists('departments', 'id')],
            'description' => ['nullable', 'string', 'max:5000'],
            'consequences' => ['nullable', 'string', 'max:5000'],
            'initial_likelihood' => ['required', 'integer', 'between:1,5'],
            'initial_impact' => ['required', 'integer', 'between:1,5'],
            'owner_id' => ['nullable', $this->orgScopedUserRule()],
            'stakeholder_ids' => ['nullable', 'array'],
            'stakeholder_ids.*' => ['integer', $this->orgScopedUserRule()],
            'preventive_measures' => ['nullable', 'string', 'max:5000'],
            'target_close_date' => ['nullable', 'date', 'after_or_equal:discovery_date'],
            'response_type' => ['sometimes', Rule::in(array_column(RiskResponseType::cases(), 'value'))],
            'riskable_type' => ['nullable', 'string', Rule::in($this->allowedRiskableAliases())],
            'riskable_id' => ['nullable', 'integer', 'required_with:riskable_type'],
            'actions' => ['nullable', 'array'],
            'actions.*.title' => ['required_with:actions', 'string', 'max:255'],
            'actions.*.owner_id' => ['nullable', $this->orgScopedUserRule()],
            'actions.*.due_date' => ['nullable', 'date'],
        ];

        if ($this->user()?->isSuperAdmin()) {
            $rules['organization_id'] = ['nullable', 'integer', Rule::exists('organizations', 'id')];
        }

        return $rules;
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
        $validator->after(fn ($v) => $this->validateRiskableOwnership($v));
    }
}
