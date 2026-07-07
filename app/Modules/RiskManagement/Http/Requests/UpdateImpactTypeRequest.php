<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\RiskManagement\Models\RiskImpactType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for updating a RiskImpactType. Engine-only authz via
 * AccessDecision::can + Capability::RISKS_EDIT.
 */
class UpdateImpactTypeRequest extends FormRequest
{
    protected ?RiskImpactType $impactType = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::RISKS_EDIT);
    }

    public function impactType(): ?RiskImpactType
    {
        if ($this->impactType !== null) {
            return $this->impactType;
        }

        $routeParam = $this->route('impactType');

        $this->impactType = $routeParam instanceof RiskImpactType
            ? $routeParam
            : RiskImpactType::query()->find($routeParam);

        return $this->impactType;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $impactType = $this->impactType();
        $ignoreId = $impactType?->id;

        $valueRule = ['sometimes', 'required', 'string', 'max:30'];
        if ($ignoreId !== null) {
            $valueRule[] = Rule::unique('risk_impact_types', 'value')->ignore($ignoreId);
        } else {
            $valueRule[] = Rule::unique('risk_impact_types', 'value');
        }

        return [
            'value' => $valueRule,
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
