<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Models\GovernanceRule;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a governing-department change (ADR-UNIFIED-ROLE-ACCESS, Phase 5).
 * The route is already gated to super_admin; this request enforces the payload
 * shape and organization isolation on the target department.
 */
class UpdateGovernanceRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware role:super_admin is the gate; nothing extra here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource_type' => ['required', 'string', Rule::in([
                GovernanceRule::TYPE_PROJECT,
                GovernanceRule::TYPE_RISK,
                GovernanceRule::TYPE_OVR,
            ])],
            'resource_subtype' => ['nullable', 'string', 'max:50'],
            'governing_unit_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $unitId = $this->input('governing_unit_id');
            if ($unitId === null) {
                return;
            }

            // Organization isolation: a department may only govern within its own org.
            $orgId = $this->user()->organization_id;
            $dept = Department::find($unitId);
            if ($dept !== null && $orgId !== null && $dept->organization_id !== $orgId) {
                $validator->errors()->add('governing_unit_id', 'الإدارة الحاكمة يجب أن تكون ضمن مؤسستك.');
            }
        });
    }
}
