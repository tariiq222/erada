<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Enums\SurveyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && AccessDecision::can($user, Capability::SURVEYS_CREATE);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::enum(SurveyType::class)],
            'category' => ['nullable', 'string', 'max:50'],

            'is_public' => ['boolean'],
            'requires_auth' => ['boolean'],
            'allow_multiple_responses' => ['boolean'],
            'allow_edit_response' => ['boolean'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            'consent_text' => ['nullable', 'string', 'max:5000'],
            'consent_required' => ['boolean'],
            'welcome_message' => ['nullable', 'string', 'max:5000'],
            'thank_you_message' => ['nullable', 'string', 'max:5000'],

            'settings' => ['nullable', 'array'],
            'settings.audience' => ['nullable', 'array'],
            'settings.audience.department_ids' => ['nullable', 'array'],
            'settings.audience.role_names' => ['nullable', 'array'],
            'settings.audience.user_ids' => ['nullable', 'array'],
            'settings.enable_import' => ['nullable', 'boolean'],
            'settings.submission_window_minutes' => ['nullable', 'integer', 'min:1'],
            'settings.require_captcha' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'عنوان الاستبيان مطلوب',
            'type.required' => 'نوع الاستبيان مطلوب',
            'ends_at.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البداية',
        ];
    }
}
