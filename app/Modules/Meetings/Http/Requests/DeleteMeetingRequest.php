<?php

namespace App\Modules\Meetings\Http\Requests;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DeleteMeetingRequest - engine-only authz for deleting a meeting.
 *
 * authorize() routes MEETINGS_DELETE through AccessDecision::can against
 * the resolved meeting; the engine handles super_admin bypass + organization
 * isolation (Meeting is ScopeAware).
 */
class DeleteMeetingRequest extends FormRequest
{
    protected ?Meeting $meeting = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $meeting = $this->route('meeting');

        if (! $meeting instanceof Meeting) {
            $meeting = Meeting::find($meeting);
        }

        // ponytail: null → let route model binding produce the 404.
        if (! $meeting) {
            return true;
        }

        $this->meeting = $meeting;

        return AccessDecision::can($user, Capability::MEETINGS_DELETE, $meeting);
    }

    public function rules(): array
    {
        return [];
    }

    public function getMeeting(): ?Meeting
    {
        return $this->meeting;
    }
}
