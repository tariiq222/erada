<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateMinutesRequest - التحقق من بيانات تحديث محضر الاجتماع.
 *
 * authorize() يمرّ عبر سياسة MeetingPolicy::update فقط (engine-only).
 */
class UpdateMinutesRequest extends FormRequest
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
            'minutes' => ['required', 'string', 'max:20000'],
        ];
    }
}
