<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Meetings\Http\Requests\UpdateMeetingAttendeeRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Support\MeetingOrgGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class MeetingAttendeeController extends Controller
{
    /**
     * Returns a Rule::exists scoped to the authenticated user's organization.
     * Super-admins (no organization_id) bypass the org scope and can reference any user.
     */
    private function orgScopedUserRule(): Exists
    {
        $user = auth()->user();
        $rule = Rule::exists('users', 'id');

        if ($user?->isSuperAdmin()) {
            return $rule;
        }

        return $rule->where('organization_id', $user->organization_id);
    }

    public function attach(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'user_id' => ['required_without:user_ids', 'integer', $this->orgScopedUserRule()],
            'user_ids' => ['required_without:user_id', 'array'],
            'user_ids.*' => ['integer', $this->orgScopedUserRule()],
            'role' => ['nullable', 'string', 'max:50'],
        ]);

        $role = $validated['role'] ?? 'attendee';

        if (! empty($validated['user_ids'])) {
            $meeting->attendees()->attach(
                array_fill_keys($validated['user_ids'], ['role' => $role])
            );
        } else {
            $meeting->attendees()->attach($validated['user_id'], ['role' => $role]);
        }

        $meeting->load('attendees:id,name,email');

        return response()->json([
            'message' => 'تم إضافة الحضور',
            'attendees' => $meeting->attendees,
        ]);
    }

    public function update(UpdateMeetingAttendeeRequest $request, Meeting $meeting, int $user): JsonResponse
    {
        $this->authorize('update', $meeting);

        // Phase 5.B: actor must belong to the meeting's org.
        $guard = app(MeetingOrgGuard::class);
        $guard->abortUnlessSameOrganization($request->user(), $meeting->organization_id);

        // The route-bound {user} must also belong to the meeting's org.
        // super_admin actors may target any attendee (their org_id may also be null).
        $userOrgId = $guard->attendeeUserOrgId($meeting, $user);
        if (! $request->user()->isSuperAdmin() && ($userOrgId === null || $userOrgId !== (int) $meeting->organization_id)) {
            abort(403, 'المستخدم لا ينتمي لمنظمة الاجتماع');
        }

        if (! $meeting->attendees()->where('user_id', $user)->exists()) {
            throw ValidationException::withMessages(['user' => 'الحاضر غير موجود']);
        }

        $validated = $request->validated();

        $meeting->attendees()->updateExistingPivot($user, $validated);

        return response()->json([
            'message' => 'تم تحديث الحضور',
            'attendee' => $meeting->attendees()->where('user_id', $user)->first(),
        ]);
    }

    public function detach(Meeting $meeting, int $user): JsonResponse
    {
        $this->authorize('update', $meeting);

        // Phase 5.B: actor must belong to the meeting's org.
        $guard = app(MeetingOrgGuard::class);
        $guard->abortUnlessSameOrganization(request()->user(), $meeting->organization_id);

        // The route-bound {user} must also belong to the meeting's org.
        // super_admin actors may target any attendee (their org_id may also be null).
        $userOrgId = $guard->attendeeUserOrgId($meeting, $user);
        if (! request()->user()->isSuperAdmin() && ($userOrgId === null || $userOrgId !== (int) $meeting->organization_id)) {
            abort(403, 'المستخدم لا ينتمي لمنظمة الاجتماع');
        }

        $meeting->attendees()->detach($user);

        return response()->json(['message' => 'تم إزالة الحاضر']);
    }
}
