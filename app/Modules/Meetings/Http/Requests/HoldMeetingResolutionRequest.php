<?php

namespace App\Modules\Meetings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hold a meeting resolution — a metadata-only action that does NOT change
 * status. The held resolution keeps its current status (open / in_progress)
 * until `release-hold` is called. `hold_until` is optional; a null value
 * means "held indefinitely until released".
 */
class HoldMeetingResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hold', $this->route('resolution')) ?? false;
    }

    public function rules(): array
    {
        return [
            'hold_reason' => ['required', 'string', 'min:3', 'max:5000'],
            'hold_until' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'hold_reason.required' => 'يجب إدخال سبب التعليق.',
            'hold_reason.min' => 'سبب التعليق قصير جداً.',
        ];
    }
}
