<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for creating a RiskImpactType. Engine-only authz via
 * AccessDecision::can + Capability::RISKS_EDIT.
 */
class StoreImpactTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::RISKS_EDIT);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'value' => ['required', 'string', 'max:30', Rule::unique('risk_impact_types', 'value')],
            'label' => ['required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
