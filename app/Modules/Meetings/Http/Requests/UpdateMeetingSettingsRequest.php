<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class UpdateMeetingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Meeting::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'default_duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'reminder_window_hours' => ['required', 'integer', 'min:1', 'max:336'],
            'attendee_roles' => ['required', 'array', 'min:1', 'max:20'],
            'attendee_roles.*' => ['required', 'string', 'max:50'],
            'default_category_id' => ['nullable', 'integer', $this->orgScopedCategoryRule()],
            'agenda_request_enabled' => ['required', 'boolean'],
            'agenda_request_lead_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'decision_pending_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'recommendation_overdue_grace_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }

    private function orgScopedCategoryRule(): Exists
    {
        $user = $this->user();
        $rule = Rule::exists('meeting_categories', 'id')->whereNull('deleted_at');

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        return $rule->where('organization_id', $user->organization_id);
    }
}
