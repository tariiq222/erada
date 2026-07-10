<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Meetings\Http\Requests\DeleteMeetingRequest;
use App\Modules\Meetings\Http\Requests\StoreMeetingRequest;
use App\Modules\Meetings\Http\Requests\UpdateMeetingRequest;
use App\Modules\Meetings\Http\Requests\UpdateMinutesRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Notifications\AgendaRequestedNotification;
use App\Modules\Meetings\Notifications\MeetingScheduledNotification;
use App\Modules\Meetings\Scopes\UserMeetingScope;
use App\Modules\Meetings\Support\DecidableType;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeetingController extends Controller
{
    use HasOrganizationScope;

    private const NULL_ORG_MESSAGE = 'المستخدم لا ينتمي لمؤسسة';

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);

        $query = Meeting::query()->with(['organizer:id,name', 'subject', 'attendees:id,name', 'category:id,name']);

        // Phase CFA-06: Use the scope's applyToMeetings() so cluster_tree
        // widening kicks in automatically when MEETINGS_VIEW +
        // CLUSTER_TREE_VIEW are both held by the actor. Without this, only
        // the inline same-org filter would run — defeating the cluster
        // widening at the list endpoints.
        app(UserMeetingScope::class)->applyToMeetings($query, auth()->user());

        if ($type = $request->query('subject_type')) {
            $request->validate([
                'subject_type' => ['string', Rule::in(DecidableType::aliases())],
                'subject_id' => 'sometimes|integer',
            ]);
            $query->where('subject_type', DecidableType::classFor($type));
            if ($id = $request->query('subject_id')) {
                $query->where('subject_id', $id);
            }
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($organizerId = $request->query('organizer_id')) {
            $query->where('organizer_id', $organizerId);
        }
        if ($from = $request->query('from')) {
            $query->where('scheduled_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('scheduled_at', '<=', $to);
        }
        if ($request->boolean('pending_reminder')) {
            $query->where('status', Meeting::STATUS_SCHEDULED)
                ->whereBetween('scheduled_at', [now(), now()->addDay()])
                ->whereNull('reminder_sent_at');
        }

        return response()->json(
            $query->orderBy('scheduled_at', 'desc')
                ->paginate(min((int) $request->get('per_page', 15), 100))
        );
    }

    public function list(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Meeting::class);
        $query = Meeting::query()->select('id', 'title', 'reference_number', 'scheduled_at', 'status');
        // Phase CFA-06: Same cluster-aware floor as index().
        app(UserMeetingScope::class)->applyToMeetings($query, auth()->user());

        return response()->json(['data' => $query->orderBy('scheduled_at', 'desc')->limit(200)->get()]);
    }

    public function show(Meeting $meeting): JsonResponse
    {
        $this->authorize('view', $meeting);
        $meeting->load(['organizer:id,name', 'attendees:id,name', 'subject', 'category:id,name']);

        return response()->json($meeting);
    }

    public function store(StoreMeetingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! empty($validated['subject_type'])) {
            $modelClass = DecidableType::classFor($validated['subject_type']);
            $subject = $modelClass::find($validated['subject_id']);
            if (! $subject) {
                throw ValidationException::withMessages(['subject_id' => 'العنصر المرتبط غير موجود']);
            }
            $this->assertSameOrganization($subject);
            $validated['subject_type'] = $modelClass;
            $validated['organization_id'] = $subject->organization_id;
        } else {
            // Null-org fail-closed floor: a non-super user without an
            // organization_id cannot create a meeting without a subject.
            // Previously organization_id was defaulted from auth()->user()
            // and could end up as null, creating a tenant-less meeting.
            abort_if(
                ! auth()->user()->isSuperAdmin() && auth()->user()->organization_id === null,
                403,
                self::NULL_ORG_MESSAGE
            );
            $validated['organization_id'] = auth()->user()->organization_id;
        }

        $validated['status'] = $validated['status'] ?? Meeting::STATUS_SCHEDULED;
        $meeting = Meeting::create($validated);

        if (! empty($validated['attendee_ids'])) {
            $meeting->attendees()->attach(
                array_fill_keys($validated['attendee_ids'], ['role' => 'attendee'])
            );
        }

        $meeting->load(['organizer:id,name', 'attendees:id,name', 'subject', 'category:id,name']);

        $recipients = $meeting->attendees()->get();
        if (! $recipients->contains('id', $meeting->organizer_id)) {
            $recipients->push($meeting->organizer);
        }
        Notification::send($recipients, new MeetingScheduledNotification($meeting));

        return response()->json(['message' => 'تم إنشاء الاجتماع بنجاح', 'meeting' => $meeting], 201);
    }

    public function update(UpdateMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $validated = $request->validated();

        if (! empty($validated['subject_type'])) {
            $modelClass = DecidableType::classFor($validated['subject_type']);
            $subject = $modelClass::find($validated['subject_id']);
            if (! $subject) {
                throw ValidationException::withMessages(['subject_id' => 'العنصر المرتبط غير موجود']);
            }
            $this->assertSameOrganization($subject);
            $validated['subject_type'] = $modelClass;
        }

        $meeting->update($validated);
        $meeting->load(['organizer:id,name', 'attendees:id,name', 'subject', 'category:id,name']);

        return response()->json(['message' => 'تم تحديث الاجتماع بنجاح', 'meeting' => $meeting]);
    }

    public function destroy(DeleteMeetingRequest $request, Meeting $meeting): JsonResponse
    {
        // Authz (MEETINGS_DELETE on the meeting) owned by DeleteMeetingRequest.
        $meeting->delete();

        return response()->json(['message' => 'تم حذف الاجتماع بنجاح']);
    }

    public function start(Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);
        // Null-org fail-closed floor: a non-super user with null org must not be
        // able to flip a meeting's status by hitting this endpoint.
        abort_if(! auth()->user()->isSuperAdmin() && auth()->user()->organization_id === null, 403, self::NULL_ORG_MESSAGE);
        if (! $meeting->canTransitionTo(Meeting::STATUS_IN_PROGRESS)) {
            return response()->json(['message' => 'لا يمكن بدء الاجتماع في الحالة الحالية'], 409);
        }
        $meeting->update(['status' => Meeting::STATUS_IN_PROGRESS]);

        return response()->json(['message' => 'تم بدء الاجتماع', 'meeting' => $meeting]);
    }

    public function complete(Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);
        abort_if(! auth()->user()->isSuperAdmin() && auth()->user()->organization_id === null, 403, self::NULL_ORG_MESSAGE);
        if (! $meeting->canTransitionTo(Meeting::STATUS_COMPLETED)) {
            return response()->json(['message' => 'لا يمكن إكمال الاجتماع في الحالة الحالية'], 409);
        }
        $meeting->update(['status' => Meeting::STATUS_COMPLETED]);

        return response()->json(['message' => 'تم إكمال الاجتماع', 'meeting' => $meeting]);
    }

    public function cancel(Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);
        abort_if(! auth()->user()->isSuperAdmin() && auth()->user()->organization_id === null, 403, self::NULL_ORG_MESSAGE);
        if (! $meeting->canTransitionTo(Meeting::STATUS_CANCELLED)) {
            return response()->json(['message' => 'لا يمكن إلغاء الاجتماع في الحالة الحالية'], 409);
        }
        $meeting->update(['status' => Meeting::STATUS_CANCELLED]);

        return response()->json(['message' => 'تم إلغاء الاجتماع', 'meeting' => $meeting]);
    }

    public function updateMinutes(UpdateMinutesRequest $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validated();
        $meeting->update(['minutes' => $validated['minutes']]);

        return response()->json(['message' => 'تم حفظ المحضر', 'meeting' => $meeting]);
    }

    public function attendees(Meeting $meeting): JsonResponse
    {
        $this->authorize('view', $meeting);
        $meeting->load('attendees:id,name,email');

        return response()->json(['data' => $meeting->attendees]);
    }

    public function requestAgenda(Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $meeting->update(['agenda_requested_at' => now()]);

        $recipients = $meeting->attendees()->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AgendaRequestedNotification($meeting));
        }

        return response()->json([
            'message' => 'تم إرسال طلب النقاط للمدعوين',
            'agenda_requested_at' => $meeting->agenda_requested_at?->toIso8601String(),
        ]);
    }
}
