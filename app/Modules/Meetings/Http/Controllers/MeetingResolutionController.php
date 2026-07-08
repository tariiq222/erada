<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Http\Requests\ConvertResolutionToTasksRequest;
use App\Modules\Meetings\Http\Requests\HoldMeetingResolutionRequest;
use App\Modules\Meetings\Http\Requests\StoreMeetingResolutionRequest;
use App\Modules\Meetings\Http\Requests\UpdateMeetingResolutionRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use App\Modules\Meetings\Models\ResolutionLink;
use App\Modules\Meetings\Notifications\ResolutionConvertedToTasksNotification;
use App\Modules\Meetings\Notifications\ResolutionRecordedNotification;
use App\Modules\Meetings\Support\MeetingOrgGuard;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 1 / Direction R — Meeting Resolutions Foundation.
 *
 * Two route groups:
 *   - Nested under meeting: GET/POST /api/meetings/{meeting}/resolutions
 *   - Flat by id:          GET/PATCH/DELETE /api/meeting-resolutions/{resolution}
 *                          POST /api/meeting-resolutions/{resolution}/{start,hold,...}
 *
 * Authz follows the engine pattern (FormRequest::authorize() delegates to
 * MeetingResolutionPolicy). All mutating routes pass through throttle:sensitive
 * to match Tasks/Projects style (per CLAUDE.md middleware guidance).
 *
 * No `approve` / `reject` / `adopt` / `deliberate` endpoints exist on this
 * controller — by design. The legacy Direction B lifecycle stays on
 * RecommendationController.
 */
class MeetingResolutionController extends Controller
{
    private const NULL_ORG_MESSAGE = 'المستخدم لا ينتمي لمؤسسة';

    public function indexForMeeting(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('viewAny', MeetingResolution::class);
        abort_if(
            ! auth()->user()->isSuperAdmin() && auth()->user()->organization_id === null,
            403,
            self::NULL_ORG_MESSAGE,
        );
        app(MeetingOrgGuard::class)->abortUnlessSameOrganization(
            auth()->user(),
            $meeting->organization_id
        );

        $query = MeetingResolution::query()
            ->where('meeting_id', $meeting->id)
            ->with(['owner:id,name', 'creator:id,name', 'links'])
            // Phase 3: list endpoint surfaces task-progress aggregates via
            // a single grouped subquery so we don't N+1 on every row.
            ->withCount([
                'tasks as tasks_count' => fn ($q) => $q->where('source_type', 'MeetingResolution'),
                'tasks as completed_tasks_count' => fn ($q) => $q->where('source_type', 'MeetingResolution')->where('status', 'completed'),
            ]);

        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->boolean('overdue')) {
            $query->whereNotIn('status', [MeetingResolution::STATUS_COMPLETED, MeetingResolution::STATUS_CANCELLED])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now());
        }

        $page = $query->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->get('per_page', 15), 100));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function storeForMeeting(StoreMeetingResolutionRequest $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validated();

        abort_if(
            ! auth()->user()->isSuperAdmin() && auth()->user()->organization_id === null,
            403,
            self::NULL_ORG_MESSAGE,
        );
        app(MeetingOrgGuard::class)->abortUnlessSameOrganization(
            auth()->user(),
            $meeting->organization_id
        );

        $validated['meeting_id'] = $meeting->id;
        $validated['organization_id'] = $meeting->organization_id;
        $validated['created_by'] = auth()->id();
        $validated['status'] = $validated['status'] ?? MeetingResolution::STATUS_OPEN;
        $validated['priority'] = $validated['priority'] ?? MeetingResolution::PRIORITY_MEDIUM;

        $resolution = DB::transaction(function () use ($validated) {
            $resolution = MeetingResolution::create($validated);

            if (! empty($validated['links'])) {
                $this->syncLinks($resolution, $validated['links']);
            }

            return $resolution;
        });

        DB::afterCommit(function () use ($resolution) {
            $this->notifyOwnerIfNew($resolution);
        });

        $resolution->load(['owner:id,name', 'creator:id,name', 'links']);

        return response()->json([
            'message' => 'تم إنشاء المخرج بنجاح',
            'resolution' => $resolution,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MeetingResolution::class);

        $query = MeetingResolution::query()
            ->with(['owner:id,name', 'meeting:id,title,reference_number', 'links'])
            ->withCount([
                'tasks as tasks_count' => fn ($q) => $q->where('source_type', 'MeetingResolution'),
                'tasks as completed_tasks_count' => fn ($q) => $q->where('source_type', 'MeetingResolution')->where('status', 'completed'),
            ]);

        $user = auth()->user();
        abort_if(! $user, 403, self::NULL_ORG_MESSAGE);
        if (! $user->isSuperAdmin()) {
            abort_if($user->organization_id === null, 403, self::NULL_ORG_MESSAGE);
            $query->where('organization_id', $user->organization_id);
        }

        if ($meetingId = $request->query('meeting_id')) {
            $query->where('meeting_id', $meetingId);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($ownerId = $request->query('owner_id')) {
            $query->where('owner_id', $ownerId);
        }
        if ($request->boolean('overdue')) {
            $query->whereNotIn('status', [MeetingResolution::STATUS_COMPLETED, MeetingResolution::STATUS_CANCELLED])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now());
        }

        $page = $query->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->get('per_page', 15), 100));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(MeetingResolution $resolution): JsonResponse
    {
        $this->authorize('view', $resolution);
        $resolution->load(['owner:id,name', 'creator:id,name', 'meeting:id,title,reference_number', 'links']);

        // Phase 4: the detail endpoint relies on `$appends` to surface the
        // four progress aggregates without a per-row query. We still run
        // a single grouped query to populate `tasks_count` and
        // `completed_tasks_count` so the accessors read the eager
        // attributes instead of falling back to a subquery.
        $resolution->loadCount([
            'tasks as tasks_count' => fn ($q) => $q->where('source_type', 'MeetingResolution'),
            'tasks as completed_tasks_count' => fn ($q) => $q->where('source_type', 'MeetingResolution')->where('status', 'completed'),
        ]);

        $tasks = $resolution->tasks()
            ->select(['id', 'title', 'status', 'priority', 'due_date', 'assigned_to', 'owner_id', 'completed_date'])
            ->with(['assignee:id,name'])
            ->orderBy('id')
            ->limit(100)
            ->get();

        $payload = $resolution->toArray();
        $payload['tasks'] = $tasks;

        return response()->json($payload);
    }

    public function update(UpdateMeetingResolutionRequest $request, MeetingResolution $resolution): JsonResponse
    {
        $validated = $request->validated();

        $resolution = DB::transaction(function () use ($resolution, $validated) {
            $resolution->update($validated);

            if (array_key_exists('links', $validated)) {
                $resolution->links()->delete();
                if (! empty($validated['links'])) {
                    $this->syncLinks($resolution, $validated['links']);
                }
            }

            return $resolution->fresh();
        });

        $resolution->load(['owner:id,name', 'creator:id,name', 'links']);

        return response()->json([
            'message' => 'تم تحديث المخرج بنجاح',
            'resolution' => $resolution,
        ]);
    }

    public function destroy(MeetingResolution $resolution): JsonResponse
    {
        $this->authorize('delete', $resolution);
        $resolution->delete();

        return response()->json(['message' => 'تم حذف المخرج بنجاح']);
    }

    // ============================================================
    // State machine: open → in_progress → (converted_to_tasks | completed | cancelled)
    // ============================================================

    public function start(MeetingResolution $resolution): JsonResponse
    {
        $this->authorize('update', $resolution);

        if (! $resolution->canTransitionTo(MeetingResolution::STATUS_IN_PROGRESS)) {
            return response()->json(['message' => 'لا يمكن بدء المخرج في الحالة الحالية'], 409);
        }

        $resolution->update(['status' => MeetingResolution::STATUS_IN_PROGRESS]);
        $resolution->load(['owner:id,name', 'meeting:id,title']);

        return response()->json(['message' => 'تم بدء المخرج', 'resolution' => $resolution]);
    }

    public function hold(HoldMeetingResolutionRequest $request, MeetingResolution $resolution): JsonResponse
    {
        // NOTE: hold does NOT change status by design — the resolution stays
        // at its current status until release-hold is called. We only stamp
        // hold_* metadata. The 409 guard above for state transitions is
        // intentionally absent here.
        $payload = $request->validated();

        $resolution->update([
            'hold_reason' => $payload['hold_reason'],
            'hold_until' => $payload['hold_until'] ?? null,
            'hold_by' => auth()->id(),
            'hold_at' => now(),
        ]);
        $resolution->load(['holder:id,name', 'owner:id,name']);

        return response()->json([
            'message' => 'تم تعليق المخرج',
            'resolution' => $resolution,
        ]);
    }

    public function releaseHold(MeetingResolution $resolution): JsonResponse
    {
        $this->authorize('releaseHold', $resolution);

        $resolution->update([
            'hold_reason' => null,
            'hold_until' => null,
            'hold_by' => null,
            'hold_at' => null,
        ]);
        $resolution->load(['owner:id,name']);

        return response()->json([
            'message' => 'تم فك تعليق المخرج',
            'resolution' => $resolution,
        ]);
    }

    public function convertToTasks(ConvertResolutionToTasksRequest $request, MeetingResolution $resolution): JsonResponse
    {
        $this->authorize('convertToTasks', $resolution);

        // Re-conversion guard: once a resolution has spawned tasks it cannot
        // spawn another batch. The SPA disables the button after the first
        // successful call; this 409 protects against duplicate POSTs.
        if (! $resolution->canTransitionTo(MeetingResolution::STATUS_CONVERTED_TO_TASKS)) {
            return response()->json(['message' => 'لا يمكن تحويل المخرج في الحالة الحالية'], 409);
        }

        $payload = $request->validated()['tasks'];
        $authId = auth()->id();

        // Resolve linkable-type metadata from the resolution_links pivot
        // so a single resolution can simultaneously link to a project (via
        // tasks.project_id) and a risk (via a separate Task with
        // source_type='Risk'). Currently the tasks schema has no `risk_id`
        // column, so risk linking is exposed in the payload but rejected
        // at validation time — see ConvertResolutionToTasksRequest.
        $resolution->loadMissing('links');

        try {
            $created = DB::transaction(function () use ($resolution, $payload, $authId) {
                $rows = [];
                $assigneeTaskInputs = []; // assignee_id => [row, ...] (in-order)
                foreach ($payload as $taskInput) {
                    // Derive project_id from the payload OR (if absent) from
                    // a `linkable_type=project` resolution_link, so a
                    // resolution linked to a project auto-attaches all
                    // spawned tasks to it.
                    $projectId = $taskInput['project_id'] ?? null;
                    if ($projectId === null) {
                        $projectLink = $resolution->links
                            ->firstWhere('linkable_type', ResolutionLink::TYPE_PROJECT);
                        $projectId = $projectLink?->linkable_id;
                    }

                    $assignee = (int) $taskInput['assignee_id'];
                    $row = [
                        'type' => TaskType::PROJECT->value,
                        'title' => $taskInput['title'],
                        'description' => $taskInput['description'] ?? null,
                        'status' => TaskStatus::TODO->value,
                        'priority' => $taskInput['priority'] ?? 'medium',
                        'progress' => 0,
                        'due_date' => $taskInput['due_date'] ?? null,
                        // The tasks schema uses `assigned_to` (the column
                        // alias for the user assigned to the task). The
                        // FormRequest accepts the alias `assignee_id` from
                        // the SPA because it reads more naturally; we
                        // map to the column here.
                        'assigned_to' => $assignee,
                        'owner_id' => $authId,
                        'created_by' => $authId,
                        'project_id' => $projectId,
                        'department_id' => $resolution->meeting?->department_id,
                        // Phase 3 polymorphic source: short basename token
                        // (matching Task::SOURCE_CLASS_MAP). Engine walks
                        // MeetingResolution → Meeting → Department on
                        // scopeParent().
                        'source_type' => 'MeetingResolution',
                        'source_id' => $resolution->id,
                        'organization_id' => $resolution->organization_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $rows[] = $row;
                    $assigneeTaskInputs[$assignee][] = $row;
                }

                // Bulk insert is faster than N round-trips and respects the
                // outer transaction. If any row fails validation the whole
                // batch rolls back via the DB::transaction wrapper.
                Task::insert($rows);

                // Reload the inserted rows so we can group them by assignee
                // for the post-commit notification. We use insert() + reload
                // instead of ::create() per row to keep the payload compact
                // and avoid N+1 model events firing on sibling rows.
                $tasks = Task::query()
                    ->where('source_type', 'MeetingResolution')
                    ->where('source_id', $resolution->id)
                    ->where('created_at', '>=', now()->subSeconds(2))
                    ->orderBy('id')
                    ->limit(count($rows))
                    ->get();

                // Flip the resolution status — this is the canonical
                // signal for the SPA to hide the convert button and
                // surface the tasks_count / completion_percentage card.
                $resolution->update([
                    'status' => MeetingResolution::STATUS_CONVERTED_TO_TASKS,
                ]);

                // Build per-assignee groups (in-order) so the post-commit
                // notifier knows which task ids to attach to each recipient.
                // Keyed by assignee_id to dedupe: 5 tasks for the same user
                // produce ONE notification, not five.
                $perAssignee = [];
                foreach ($tasks as $task) {
                    $assignee = $task->assigned_to;
                    $perAssignee[$assignee][] = $task;
                }

                return [
                    'tasks' => $tasks,
                    'per_assignee' => $perAssignee,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('Failed to convert meeting resolution to tasks', [
                'resolution_id' => $resolution->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'فشل تحويل المخرج إلى مهام',
                'error' => $e->getMessage(),
            ], 422);
        }

        $createdTasks = $created['tasks'] ?? collect();
        $perAssignee = $created['per_assignee'] ?? [];

        // Phase 4 notifications — one per unique assignee, skipping the
        // actor. The notification class is ShouldQueue so even if we
        // dispatch inside the test transaction, the queue worker will
        // only process it after the outer commit. In production this
        // guarantees a rolled-back conversion never delivers an email.
        $meeting = $resolution->meeting;
        $meetingId = $meeting?->id;
        $resolutionTitle = $resolution->title;
        $resolutionId = $resolution->id;
        $totalCount = $createdTasks->count();

        if ($totalCount > 0) {
            foreach ($perAssignee as $assigneeId => $tasksForUser) {
                if ((int) $assigneeId === (int) $authId) {
                    // Skip the actor — they triggered the conversion.
                    continue;
                }
                $user = User::find($assigneeId);
                if (! $user) {
                    continue;
                }
                $count = count($tasksForUser);
                Notification::send(
                    $user,
                    new ResolutionConvertedToTasksNotification(
                        resolutionId: $resolutionId,
                        resolutionTitle: $resolutionTitle,
                        meetingId: $meetingId ?? 0,
                        totalTaskCount: $totalCount,
                        assigneeTaskCount: $count,
                        tasksForAssignee: $tasksForUser,
                        assigneeTaskCountLocalized: $count === 1 ? 'مهمة واحدة' : "{$count} مهام",
                    ),
                );
            }
        }

        $resolution->refresh()->load(['owner:id,name', 'creator:id,name', 'links']);
        // $appends on MeetingResolution already includes the four
        // progress keys; no manual append() needed.

        return response()->json([
            'message' => 'تم تحويل المخرج إلى مهام',
            'resolution' => $resolution,
            'tasks' => $createdTasks,
        ], 201);
    }

    public function complete(MeetingResolution $resolution): JsonResponse
    {
        $this->authorize('complete', $resolution);

        if (! $resolution->canTransitionTo(MeetingResolution::STATUS_COMPLETED)) {
            return response()->json(['message' => 'لا يمكن إكمال المخرج في الحالة الحالية'], 409);
        }

        $resolution->update([
            'status' => MeetingResolution::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $resolution->load(['owner:id,name']);

        return response()->json(['message' => 'تم إكمال المخرج', 'resolution' => $resolution]);
    }

    public function cancel(MeetingResolution $resolution): JsonResponse
    {
        $this->authorize('cancel', $resolution);

        if (! $resolution->canTransitionTo(MeetingResolution::STATUS_CANCELLED)) {
            return response()->json(['message' => 'لا يمكن إلغاء المخرج في الحالة الحالية'], 409);
        }

        $resolution->update([
            'status' => MeetingResolution::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
        $resolution->load(['owner:id,name']);

        return response()->json(['message' => 'تم إلغاء المخرج', 'resolution' => $resolution]);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * @param  array<int, array{linkable_type:string, linkable_id:int, link_role?:string|null}>  $links
     */
    protected function syncLinks(MeetingResolution $resolution, array $links): void
    {
        $authId = auth()->id();
        $rows = [];
        foreach ($links as $link) {
            $rows[] = [
                'resolution_id' => $resolution->id,
                'linkable_type' => $link['linkable_type'],
                'linkable_id' => (int) $link['linkable_id'],
                'link_role' => $link['link_role'] ?? ResolutionLink::ROLE_RELATED_TO,
                'created_by' => $authId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        ResolutionLink::insert($rows);
    }

    protected function notifyOwnerIfNew(MeetingResolution $resolution): void
    {
        if (! $resolution->owner_id) {
            return;
        }
        $owner = User::find($resolution->owner_id);
        if (! $owner) {
            return;
        }
        Notification::send($owner, new ResolutionRecordedNotification($resolution));
    }
}
