<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Meetings\Http\Requests\DestroyAgendaItemRequest;
use App\Modules\Meetings\Http\Requests\StoreAgendaItemRequest;
use App\Modules\Meetings\Http\Requests\UpdateAgendaItemRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AgendaItemController extends Controller
{
    use HasOrganizationScope;

    public function index(Meeting $meeting): JsonResponse
    {
        $this->authorizeParticipate($meeting);

        $items = $meeting->agendaItems()
            ->with('proposedBy:id,name')
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $items,
            'can_manage' => Gate::allows('update', $meeting),
            'agenda_requested_at' => $meeting->agenda_requested_at?->toIso8601String(),
        ]);
    }

    public function store(StoreAgendaItemRequest $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validated();

        $canManage = Gate::allows('update', $meeting);

        $item = $meeting->agendaItems()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'proposed_by_id' => $request->user()->id,
            'status' => $canManage ? MeetingAgendaItem::STATUS_APPROVED : MeetingAgendaItem::STATUS_PENDING,
            'position' => $canManage ? $this->nextPosition($meeting) : 0,
            'organization_id' => $meeting->organization_id,
        ]);

        $item->load('proposedBy:id,name');

        return response()->json(['message' => 'تمت إضافة النقطة', 'item' => $item], 201);
    }

    public function update(UpdateAgendaItemRequest $request, Meeting $meeting, MeetingAgendaItem $agendaItem): JsonResponse
    {
        // Org-floor (P0 IDOR defense): even if the engine grants view, a mismatched
        // org on the agenda item itself must abort before any mutation.
        $this->assertSameOrganization($agendaItem);

        $canManage = Gate::allows('update', $meeting);
        $isOwner = $agendaItem->proposed_by_id === $request->user()->id;

        if (! $canManage && ! ($isOwner && $agendaItem->status === MeetingAgendaItem::STATUS_PENDING)) {
            abort(403, 'لا يمكنك تعديل هذه النقطة. يجب أن تكون منظِّم الاجتماع أو مقترحها وهي بانتظار المراجعة.');
        }

        $validated = $request->validated();

        $agendaItem->update($validated);
        $agendaItem->load('proposedBy:id,name');

        return response()->json(['message' => 'تم تحديث النقطة', 'item' => $agendaItem]);
    }

    public function destroy(DestroyAgendaItemRequest $request, Meeting $meeting, MeetingAgendaItem $agendaItem): JsonResponse
    {
        // Org-floor (P0 IDOR defense).
        $this->assertSameOrganization($agendaItem);

        $canManage = Gate::allows('update', $meeting);
        $isOwner = $agendaItem->proposed_by_id === $request->user()->id;

        if (! $canManage && ! $isOwner) {
            abort(403, 'لا يمكنك حذف هذه النقطة. يجب أن تكون منظِّم الاجتماع أو مقترحها.');
        }

        $agendaItem->delete();

        return response()->json(['message' => 'تم حذف النقطة']);
    }

    public function approve(Meeting $meeting, MeetingAgendaItem $agendaItem): JsonResponse
    {
        // Org-floor (P0 IDOR defense).
        $this->assertSameOrganization($agendaItem);

        $this->authorize('update', $meeting);

        $agendaItem->update([
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'review_note' => null,
            'position' => $this->nextPosition($meeting),
        ]);
        $agendaItem->load('proposedBy:id,name');

        return response()->json(['message' => 'تم اعتماد النقطة', 'item' => $agendaItem]);
    }

    public function reject(Request $request, Meeting $meeting, MeetingAgendaItem $agendaItem): JsonResponse
    {
        // Org-floor (P0 IDOR defense).
        $this->assertSameOrganization($agendaItem);

        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $agendaItem->update([
            'status' => MeetingAgendaItem::STATUS_REJECTED,
            'review_note' => $validated['review_note'] ?? null,
        ]);
        $agendaItem->load('proposedBy:id,name');

        return response()->json(['message' => 'تم رفض النقطة', 'item' => $agendaItem]);
    }

    public function reorder(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['integer', Rule::exists('meeting_agenda_items', 'id')->where('meeting_id', $meeting->id)],
        ]);

        foreach ($validated['items'] as $position => $id) {
            $meeting->agendaItems()->whereKey($id)->update(['position' => $position]);
        }

        return response()->json(['message' => 'تم إعادة الترتيب']);
    }

    private function nextPosition(Meeting $meeting): int
    {
        return (int) $meeting->agendaItems()
            ->where('status', MeetingAgendaItem::STATUS_APPROVED)
            ->max('position') + 1;
    }

    private function authorizeParticipate(Meeting $meeting): void
    {
        $user = request()->user();

        $allowed = $user->isSuperAdmin()
            || (int) $meeting->organizer_id === (int) $user->id
            || Gate::allows('view', $meeting)
            || $meeting->attendees()->whereKey($user->id)->exists();

        abort_unless($allowed, 403);
    }
}
