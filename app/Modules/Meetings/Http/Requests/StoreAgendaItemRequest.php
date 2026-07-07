<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreAgendaItemRequest - validate agenda item creation.
 *
 * authorize() allows the meeting organizer or an enrolled attendee to add
 * items. Engine-capability holders (MEETINGS_VIEW) are also admitted.
 * The approve/reject decision remains inside the Controller.
 */
class StoreAgendaItemRequest extends FormRequest
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

        $user = $this->user();

        if (! $user) {
            return false;
        }

        // Organizer always allowed.
        if ($meeting->organizer_id === $user->id) {
            return true;
        }

        // Enrolled attendee allowed.
        if ($meeting->attendees()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Engine-capability holders (e.g. admin via MEETINGS_VIEW).
        return $user->can('view', $meeting);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
