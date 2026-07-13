<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use App\Modules\Meetings\Http\Requests\Concerns\ValidatesRecommendationTarget;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Support\DecidableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecommendationRequest extends FormRequest
{
    use ScopesUsersToOrganization;
    use ValidatesRecommendationTarget;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Recommendation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Direction B: a recommendation is either a ruling (decision-style)
            // or an action_item (task-style). The presence of type/assignee_id
            // is conditional on kind.
            'kind' => ['required', Rule::in(Recommendation::kindValues())],
            'meeting_id' => [
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
            // Status is owned exclusively by the lifecycle endpoints. Allowing
            // it here would let a creator bypass approval / completion gates.
            'status' => ['prohibited'],

            // Ruling-specific: type must be supplied when kind=ruling.
            'type' => [
                Rule::requiredIf(fn () => $this->input('kind') === Recommendation::KIND_RULING),
                'nullable',
                'string',
                'max:40',
            ],

            // Ruling-linkable target (the polymorphic parent the ruling is
            // about — e.g. Project, Program). Optional.
            'decidable_type' => ['nullable', 'required_with:decidable_id', Rule::in(DecidableType::aliases())],
            'decidable_id' => ['nullable', 'required_with:decidable_type', 'integer'],

            // Action_item specific: assignee + due_date are required when
            // kind=action_item.
            'assignee_id' => [
                Rule::requiredIf(fn () => $this->input('kind') === Recommendation::KIND_ACTION_ITEM),
                'nullable',
                'integer',
                $this->orgScopedUserRule(),
            ],
            'due_date' => [
                Rule::requiredIf(fn () => $this->input('kind') === Recommendation::KIND_ACTION_ITEM),
                'nullable',
                'date',
                'after_or_equal:today',
            ],

            // Ruling metadata.
            'rationale' => ['nullable', 'string', 'max:5000'],
            'impact' => ['nullable', 'string', 'max:2000'],
            'decision_date' => ['nullable', 'date'],
            'effective_date' => ['nullable', 'date'],
            'requested_by' => ['nullable', 'integer', $this->orgScopedUserRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'حقل النوع مطلوب للتوصيات من نوع قرار.',
            'assignee_id.required' => 'يجب تحديد المسؤول لتوصيات الإجراء.',
            'due_date.required' => 'يجب تحديد تاريخ الاستحقاق لتوصيات الإجراء.',
        ];
    }
}
