<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Support\DecidableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class UpdateMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('meeting')) ?? false;
    }

    /**
     * Returns a Rule::exists scoped to the authenticated user's organization.
     * Super-admins (no organization_id) bypass the org scope and can reference any user.
     */
    private function orgScopedUserRule(): Exists
    {
        $user = $this->user();
        $rule = Rule::exists('users', 'id');

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        return $rule->where('organization_id', $user->organization_id);
    }

    /** Org-scoped existence rule for the meeting category. */
    private function orgScopedCategoryRule(): Exists
    {
        $user = $this->user();
        $rule = Rule::exists('meeting_categories', 'id')->whereNull('deleted_at');

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        return $rule->where('organization_id', $user->organization_id);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:'.now()->subDay()->toDateString()],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'location' => ['nullable', 'string', 'max:255'],
            'virtual_link' => ['nullable', 'url', 'max:2048'],
            'agenda' => ['nullable', 'string', 'max:20000'],
            'minutes' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', Rule::in(Meeting::statusValues())],
            'organizer_id' => ['required', 'integer', $this->orgScopedUserRule()],
            'subject_type' => ['nullable', Rule::in(DecidableType::aliases())],
            'subject_id' => ['required_with:subject_type', 'integer'],
            'category_id' => ['nullable', 'integer', $this->orgScopedCategoryRule()],
        ];
    }
}
