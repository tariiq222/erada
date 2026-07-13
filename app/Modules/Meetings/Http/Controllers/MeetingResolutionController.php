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
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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
            // Serialize links-only replacements as well as attribute updates.
            // Without locking the parent, two requests can both delete and
            // reinsert pivots, producing a mixed last-writer result.
            $lockedResolution = MeetingResolution::query()
                ->whereKey($resolution->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedResolution->update($validated);

            if (array_key_exists('links', $validated)) {
                $lockedResolution->links()->delete();
                if (! empty($validated['links'])) {
                    $this->syncLinks($lockedResolution, $validated['links']);
                }
            }

            return $lockedResolution->fresh();
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

        $payload = $request->validated()['tasks'];
        $authId = auth()->id();

        try {
            $created = DB::transaction(function () use ($resolution, $payload, $authId) {
                // Serialize conversion attempts on the canonical row. Route
                // model binding happens before this transaction and can be
                // stale by the time the request reaches the controller.
                $lockedResolution = MeetingResolution::query()
                    ->whereKey($resolution->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedResolution->canTransitionTo(MeetingResolution::STATUS_CONVERTED_TO_TASKS)) {
                    return null;
                }

                $links = $lockedResolution->links()->lockForUpdate()->get();
                $lockedResolution->setRelation('links', $links);
                $lockedResolution->loadMissing('meeting:id,department_id');

                $fallbackProjectId = $links
                    ->firstWhere('linkable_type', ResolutionLink::TYPE_PROJECT)
                    ?->linkable_id;
                $resolvedProjectIds = [];
                foreach ($payload as $index => $taskInput) {
                    $projectId = $taskInput['project_id'] ?? $fallbackProjectId;
                    $resolvedProjectIds[$index] = $projectId === null ? null : (int) $projectId;
                }

                // FormRequest validation protects the normal request path,
                // while this check is deliberately inside the row-locked
                // transaction. It closes the super-admin bypass and the race
                // where a project/link changes between validation and insert.
                $candidateProjectIds = array_values(array_unique(array_filter(
                    $resolvedProjectIds,
                    fn (?int $projectId): bool => $projectId !== null,
                )));
                $validProjectIds = empty($candidateProjectIds)
                    ? []
                    : Project::query()
                        ->whereIn('id', $candidateProjectIds)
                        ->where('organization_id', $lockedResolution->organization_id)
                        ->sharedLock()
                        ->pluck('id')
                        ->map(fn ($projectId): int => (int) $projectId)
                        ->all();

                $projectErrors = [];
                foreach ($resolvedProjectIds as $index => $projectId) {
                    if ($projectId !== null && ! in_array($projectId, $validProjectIds, true)) {
                        $projectErrors["tasks.{$index}.project_id"] = 'المشروع غير موجود أو لا ينتمي إلى مؤسسة المخرج.';
                    }
                }
                if ($projectErrors !== []) {
                    throw ValidationException::withMessages($projectErrors);
                }

                $rows = [];
                foreach ($payload as $index => $taskInput) {
                    // Derive project_id from the payload OR (if absent) from
                    // a `linkable_type=project` resolution_link, so a
                    // resolution linked to a project auto-attaches all
                    // spawned tasks to it.
                    $projectId = $resolvedProjectIds[$index];

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
                        'department_id' => $lockedResolution->meeting?->department_id,
                        // Phase 3 polymorphic source: short basename token
                        // (matching Task::SOURCE_CLASS_MAP). Engine walks
                        // MeetingResolution → Meeting → Department on
                        // scopeParent().
                        'source_type' => 'MeetingResolution',
                        'source_id' => $lockedResolution->id,
                        'organization_id' => $lockedResolution->organization_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $rows[] = $row;
                }

                // Capture every generated bigint id from PostgreSQL RETURNING
                // so the response and notifications refer only to this request.
                // Query Builder insertGetId() avoids model events just like the
                // previous bulk insert while removing the time-window heuristic.
                $insertedTaskIds = [];
                foreach ($rows as $row) {
                    $insertedTaskIds[] = (int) DB::table('tasks')->insertGetId($row, 'id');
                }

                $tasksById = Task::query()
                    ->whereKey($insertedTaskIds)
                    ->get()
                    ->keyBy(fn (Task $task): int => (int) $task->id);
                $tasks = collect($insertedTaskIds)
                    ->map(function (int $taskId) use ($tasksById): Task {
                        $task = $tasksById->get($taskId);

                        if (! $task instanceof Task) {
                            throw new \RuntimeException("Inserted task {$taskId} could not be reloaded.");
                        }

                        return $task;
                    });

                // Flip the resolution status — this is the canonical
                // signal for the SPA to hide the convert button and
                // surface the tasks_count / completion_percentage card.
                $lockedResolution->update([
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to convert meeting resolution to tasks', [
                'resolution_id' => $resolution->id,
                'error' => $e->getMessage(),
            ]);

            // Deliberately do NOT echo the underlying exception message
            // back to the client — it can carry schema/column names,
            // stack-trace hints, or other internal implementation details.
            // The original message is preserved in the log above for
            // ops triage; the API surfaces only a localized generic
            // string.
            return response()->json([
                'message' => 'فشل تحويل المخرج إلى مهام',
            ], 422);
        }

        if ($created === null) {
            return response()->json(['message' => 'لا يمكن تحويل المخرج في الحالة الحالية'], 409);
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
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Resolution links must be synchronized inside a database transaction.');
        }

        $this->validateAndLockLinkTargets($resolution, $links);

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

    /**
     * Revalidate link targets in the write transaction and hold shared row
     * locks until the pivot insert commits. This prevents a target from being
     * soft-deleted or moved to another organization after FormRequest UX
     * validation but before the resolution link is persisted.
     *
     * @param  array<int, array{linkable_type:string, linkable_id:int, link_role?:string|null}>  $links
     */
    protected function validateAndLockLinkTargets(MeetingResolution $resolution, array $links): void
    {
        $validIdsByType = [];

        foreach (ResolutionLink::typeValues() as $type) {
            $requestedIds = collect($links)
                ->where('linkable_type', $type)
                ->pluck('linkable_id')
                ->map(fn ($linkableId): int => (int) $linkableId)
                ->unique()
                ->values()
                ->all();

            if ($requestedIds === []) {
                continue;
            }

            $linkableClass = ResolutionLink::resolveClass($type);
            if ($linkableClass === null) {
                continue;
            }

            $validIdsByType[$type] = $linkableClass::query()
                ->whereKey($requestedIds)
                ->where('organization_id', $resolution->organization_id)
                ->sharedLock()
                ->pluck('id')
                ->map(fn ($linkableId): int => (int) $linkableId)
                ->all();
        }

        $errors = [];
        foreach ($links as $index => $link) {
            $type = $link['linkable_type'];
            $linkableId = (int) $link['linkable_id'];

            if (! in_array($linkableId, $validIdsByType[$type] ?? [], true)) {
                $errors["links.{$index}.linkable_id"] = 'العنصر المرتبط غير موجود أو لا ينتمي إلى مؤسسة الاجتماع.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
