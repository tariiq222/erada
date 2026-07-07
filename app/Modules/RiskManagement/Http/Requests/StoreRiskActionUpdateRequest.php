<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\RiskManagement\Enums\RiskActionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskActionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('action')) ?? false;
    }

    public function rules(): array
    {
        return [
            'progress_pct' => ['nullable', 'integer', 'between:0,100'],
            'status' => ['nullable', Rule::in(array_column(RiskActionStatus::cases(), 'value'))],
            'notes' => ['required', 'string', 'max:5000'],
        ];
    }
}
