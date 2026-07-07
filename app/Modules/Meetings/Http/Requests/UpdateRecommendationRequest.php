<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecommendationRequest extends FormRequest
{
    use ScopesUsersToOrganization;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recommendation')) ?? false;
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', Rule::in(Recommendation::kindValues())],
            'meeting_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('meetings', 'id')->when(
                    ! $this->user() || ! $this->user()->isSuperAdmin(),
                    fn ($rule) => $rule->where('organization_id', $this->user()?->organization_id)
                ),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(Recommendation::priorityValues())],
            'status' => ['nullable', Rule::in(Recommendation::statusValues())],

            'type' => [
                Rule::requiredIf(fn () => $this->input('kind') === Recommendation::KIND_RULING),
                'nullable',
                'string',
                'max:40',
            ],

            'decidable_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'decidable_id' => ['sometimes', 'nullable', 'integer'],

            'assignee_id' => [
                'sometimes',
                'nullable',
                'integer',
                $this->orgScopedUserRule(),
            ],
            'due_date' => ['sometimes', 'nullable', 'date'],

            'rationale' => ['nullable', 'string', 'max:5000'],
            'impact' => ['nullable', 'string', 'max:2000'],
            'decision_date' => ['nullable', 'date'],
            'effective_date' => ['nullable', 'date'],
        ];
    }
}
