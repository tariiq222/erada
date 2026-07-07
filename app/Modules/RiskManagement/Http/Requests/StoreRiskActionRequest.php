<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use App\Modules\RiskManagement\Models\RiskAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreRiskActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RiskAction::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_column(RiskActionType::cases(), 'value'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', $this->orgScopedUserRule()],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'status' => ['nullable', Rule::in(array_column(RiskActionStatus::cases(), 'value'))],
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
