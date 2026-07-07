<?php

namespace App\Modules\RiskManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reassess', $this->route('risk')) ?? false;
    }

    public function rules(): array
    {
        return [
            'likelihood' => ['required', 'integer', 'between:1,5'],
            'impact' => ['required', 'integer', 'between:1,5'],
            'residual_likelihood' => ['nullable', 'integer', 'between:1,5'],
            'residual_impact' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'next_review_at' => ['nullable', 'date', 'after:today'],
        ];
    }
}
