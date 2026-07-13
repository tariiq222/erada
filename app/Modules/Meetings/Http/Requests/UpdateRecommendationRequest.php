<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use App\Modules\Meetings\Http\Requests\Concerns\ValidatesRecommendationTarget;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Support\DecidableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecommendationRequest extends FormRequest
{
    use ScopesUsersToOrganization;
    use ValidatesRecommendationTarget;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('recommendation')) ?? false;
    }

    public function rules(): array
    {
        $recommendation = $this->route('recommendation');
        $currentKind = $recommendation instanceof Recommendation ? $recommendation->kind : null;

        return [
            // Kind determines the valid lifecycle and metadata shape. It is
            // immutable after creation, but the SPA may submit its unchanged
            // value with the rest of the edit payload.
            'kind' => ['sometimes', Rule::in([$currentKind])],
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
            // Status is owned exclusively by the lifecycle endpoints. A CRUD
            // edit must not be able to bypass their capability and state gates.
            'status' => ['prohibited'],

            'type' => [
                Rule::requiredIf($currentKind === Recommendation::KIND_RULING),
                'nullable',
                'string',
                'max:40',
            ],

            'decidable_type' => ['sometimes', 'nullable', 'required_with:decidable_id', Rule::in(DecidableType::aliases())],
            'decidable_id' => ['sometimes', 'nullable', 'required_with:decidable_type', 'integer'],

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
