<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class UpdateRiskActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('action')) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(array_column(RiskActionType::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', $this->orgScopedUserRule()],
            'due_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'required', Rule::in(array_column(RiskActionStatus::cases(), 'value'))],
            'progress_pct' => ['nullable', 'integer', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:5000'],
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
}
