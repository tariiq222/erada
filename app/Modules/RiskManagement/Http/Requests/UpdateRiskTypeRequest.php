<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\RiskManagement\Models\RiskType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for updating a RiskType. Engine-only authz via
 * AccessDecision::can + Capability::RISKS_EDIT.
 */
class UpdateRiskTypeRequest extends FormRequest
{
    protected ?RiskType $riskType = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return AccessDecision::can($user, Capability::RISKS_EDIT);
    }

    public function riskType(): ?RiskType
    {
        if ($this->riskType !== null) {
            return $this->riskType;
        }

        $routeParam = $this->route('riskType');

        $this->riskType = $routeParam instanceof RiskType
            ? $routeParam
            : RiskType::query()->find($routeParam);

        return $this->riskType;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $riskType = $this->riskType();
        $ignoreId = $riskType?->id;

        $valueRule = ['sometimes', 'required', 'string', 'max:30'];
        if ($ignoreId !== null) {
            $valueRule[] = Rule::unique('risk_types', 'value')->ignore($ignoreId);
        } else {
            $valueRule[] = Rule::unique('risk_types', 'value');
        }

        return [
            'value' => $valueRule,
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
