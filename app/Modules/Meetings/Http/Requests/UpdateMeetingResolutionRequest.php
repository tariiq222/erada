<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\ScopesUsersToOrganization;
use App\Modules\Meetings\Http\Requests\Concerns\ValidatesResolutionLinks;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\ResolutionLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMeetingResolutionRequest extends FormRequest
{
    use ScopesUsersToOrganization, ValidatesResolutionLinks;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('resolution')) ?? false;
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', Rule::in(MeetingResolution::kindValues())],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_id' => ['sometimes', 'required', 'integer', $this->orgScopedUserRule()],
            'priority' => ['sometimes', 'nullable', Rule::in(MeetingResolution::priorityValues())],
            'due_date' => ['sometimes', 'nullable', 'date'],

            'links' => ['sometimes', 'array', 'max:10'],
            'links.*.linkable_type' => ['required_with:links', Rule::in(ResolutionLink::typeValues())],
            'links.*.linkable_id' => ['required_with:links', 'integer', 'min:1'],
            'links.*.link_role' => ['nullable', Rule::in(ResolutionLink::roleValues())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $resolution = $this->route('resolution');

            if ($resolution instanceof MeetingResolution) {
                $this->validateResolutionLinks($validator, $resolution->organization_id);
            }
        });
    }
}
