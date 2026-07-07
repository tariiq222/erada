<?php

namespace App\Modules\Surveys\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BulkCreateSurveyInvitationsRequest - engine-only authz + payload
 * validation for bulk-creating survey invitations. Surfaces
 * SURVEYS_EDIT on the survey (invitations are a write-side surface).
 */
class BulkCreateSurveyInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $survey = $this->route('survey');

        if (! $survey instanceof Survey) {
            $survey = Survey::find($survey);
        }

        if (! $survey) {
            return true;
        }

        return AccessDecision::can($user, Capability::SURVEYS_EDIT, $survey);
    }

    public function rules(): array
    {
        return [
            'invitations' => ['required', 'array', 'min:1', 'max:100'],
            'invitations.*.email' => ['required', 'email'],
            'invitations.*.name' => ['nullable', 'string', 'max:255'],
            'invitations.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'invitations.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
