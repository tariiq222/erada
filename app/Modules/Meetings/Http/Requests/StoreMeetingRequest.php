<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingSettings;
use App\Modules\Meetings\Support\DecidableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Meeting::class) ?? false;
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

    /**
     * Apply per-organization defaults from MeetingSettings before validation.
     * Only fills fields the request did not supply — explicit incoming values
     * always win. Skipped entirely when the org has no row or no defaults.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if (! $user) {
            return;
        }

        $orgId = $user->isSuperAdmin() ? null : $user->organization_id;
        if ($orgId === null) {
            return;
        }

        $settings = MeetingSettings::forOrganization($orgId);

        $defaults = array_filter([
            'duration_minutes' => $settings->default_duration_minutes,
            'category_id' => $settings->default_category_id,
        ], fn ($v) => $v !== null && $v !== '');

        $merged = [];
        foreach ($defaults as $key => $value) {
            if (! $this->has($key)) {
                $merged[$key] = $value;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
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
            'status' => ['nullable', Rule::in([Meeting::STATUS_SCHEDULED, Meeting::STATUS_IN_PROGRESS])],
            'organizer_id' => ['required', 'integer', $this->orgScopedUserRule()],
            'subject_type' => ['nullable', Rule::in(DecidableType::aliases())],
            'subject_id' => ['nullable', 'required_with:subject_type', 'integer'],
            'category_id' => ['nullable', 'integer', $this->orgScopedCategoryRule()],
            'attendee_ids' => ['nullable', 'array'],
            'attendee_ids.*' => ['integer', $this->orgScopedUserRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'العنوان مطلوب',
            'scheduled_at.after_or_equal' => 'الموعد يجب ألا يكون في الماضي',
            'organizer_id.required' => 'المنظِّم مطلوب',
            'subject_type.in' => 'نوع الكيان غير صالح',
        ];
    }
}
