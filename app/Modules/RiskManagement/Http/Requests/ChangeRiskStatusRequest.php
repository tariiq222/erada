<?php

namespace App\Modules\RiskManagement\Http\Requests;

use App\Modules\RiskManagement\Enums\RiskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeRiskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeStatus', $this->route('risk')) ?? false;
    }

    public function rules(): array
    {
        return [
            'to_status' => ['required', Rule::in(array_column(RiskStatus::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
