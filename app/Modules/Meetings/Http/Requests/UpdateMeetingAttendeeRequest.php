<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateMeetingAttendeeRequest - التحقق من بيانات تحديث حضور اجتماع.
 *
 * الصلاحية تمرّ عبر MeetingPolicy::update فقط (engine-only).
 */
class UpdateMeetingAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $meeting = $this->route('meeting');

        if (! $meeting instanceof Meeting) {
            $meeting = Meeting::find($meeting);
        }

        if (! $meeting) {
            return false;
        }

        return $this->user()?->can('update', $meeting) ?? false;
    }

    public function rules(): array
    {
        return [
            'role' => ['nullable', 'string', 'max:50'],
            'attended' => ['nullable', 'boolean'],
        ];
    }
}
