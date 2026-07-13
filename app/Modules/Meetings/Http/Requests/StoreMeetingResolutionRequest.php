<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use App\Modules\Meetings\Http\Requests\Concerns\ValidatesResolutionLinks;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\Meetings\Support\MeetingOrgGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Phase 1 / Direction R — create a new typed meeting output.
 *
 * `kind` is mandatory (no default — the DB has a default but the API
 * contract requires the caller to be explicit). `owner_id` is mandatory
 * (every resolution must have a responsible party). Links are optional
 * via the `links` array, validated to the constrained `linkable_type`
 * allowlist (project | risk).
 */
class StoreMeetingResolutionRequest extends FormRequest
{
    use ScopesUsersToOrganization, ValidatesResolutionLinks;

    public function authorize(): bool
    {
        $user = $this->user();
        $meeting = $this->route('meeting');

        if (! $user || ! $meeting instanceof Meeting) {
            return false;
        }

        if (! app(MeetingOrgGuard::class)->sameOrganizationForMeeting($user, $meeting)) {
            return false;
        }

        return $user->can('create', MeetingResolution::class);
    }

    public function rules(): array
    {
        return [
            'meeting_id' => [
                'sometimes',
                'integer',
                Rule::in([(int) $this->route('meeting')->getKey()]),
            ],
            'kind' => ['required', Rule::in(MeetingResolution::kindValues())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['required', 'integer', $this->orgScopedUserRule()],
            'priority' => ['nullable', Rule::in(MeetingResolution::priorityValues())],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],

            'links' => ['sometimes', 'array', 'max:10'],
            'links.*.linkable_type' => ['required_with:links', Rule::in(ResolutionLink::typeValues())],
            'links.*.linkable_id' => ['required_with:links', 'integer', 'min:1'],
            'links.*.link_role' => ['nullable', Rule::in(ResolutionLink::roleValues())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $meeting = $this->route('meeting');

            if ($meeting instanceof Meeting) {
                $this->validateResolutionLinks($validator, $meeting->organization_id);
            }
        });
    }

    public function messages(): array
    {
        return [
            'kind.required' => 'يجب تحديد نوع المخرج (توصية أو قرار).',
            'kind.in' => 'نوع المخرج يجب أن يكون توصية أو قرار.',
            'owner_id.required' => 'يجب تحديد مسؤول المخرج.',
            'title.required' => 'عنوان المخرج مطلوب.',
            'meeting_id.in' => 'معرّف الاجتماع في الطلب لا يطابق الاجتماع في المسار.',
        ];
    }
}
