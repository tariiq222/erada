<?php

namespace App\Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Http\Requests\ApproveRecommendationRequest;
use App\Modules\Meetings\Http\Requests\DeferRecommendationRequest;
use App\Modules\Meetings\Http\Requests\RejectRecommendationRequest;
use App\Modules\Meetings\Http\Requests\StoreRecommendationRequest;
use App\Modules\Meetings\Http\Requests\UpdateRecommendationRequest;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Meetings\Notifications\RecommendationAssignedNotification;
use App\Modules\Meetings\Support\DecidableType;
use App\Modules\Meetings\Support\MeetingOrgGuard;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

class RecommendationController extends Controller
{
    use HasOrganizationScope;

    /**
     * Listing — engine-aware, mirroring RiskAuthorizationService::canViewAny.
     * The plain `can(RECOMMENDATIONS_VIEW)` gate (no target) only matches
     * organization-scoped roles, so department managers and members are wrongly
     * excluded. Allow anyone the engine grants recommendation visibility at any
     * scope; Recommendation::scopeVisibleTo then narrows the rows.
     */
    private function canListRecommendations(User $user): bool
    {
        if ($user->isSuperAdmin()
            || AccessDecision::can($user, Capability::RECOMMENDATIONS_VIEW)
            || AccessDecision::grantsAtOrganization($user, Capability::RECOMMENDATIONS_VIEW)) {
            return true;
        }

        $scopes = AccessDecision::grantingScopes($user, Capability::RECOMMENDATIONS_VIEW);

        return ($scopes['organization'] ?? []) !== []
            || ($scopes['department'] ?? []) !== []
            || ($scopes['project'] ?? []) !== [];
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user || ! $this->canListRecommendations($user)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        $query = Recommendation::query()
            ->with(['meeting:id,title,reference_number', 'assignee:id,name'])
            ->visibleTo($user);

        if ($meetingId = $request->query('meeting_id')) {
            $query->where('meeting_id', $meetingId);
        }
        if ($assigneeId = $request->query('assignee_id')) {
            $query->where('assignee_id', $assigneeId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($request->boolean('overdue')) {
            $query->where('status', '!=', Recommendation::STATUS_COMPLETED)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now());
        }

        $page = $query->orderBy('due_date', 'asc')
            ->paginate(min((int) $request->get('per_page', 15), 100));

        $page->setCollection($page->getCollection()->map(function (Recommendation $recommendation) {
            $recommendation->setAttribute('allowed_actions', [
                'update' => Gate::allows('update', $recommendation),
                'delete' => Gate::allows('delete', $recommendation),
            ]);

            return $recommendation;
        }));

        return response()->json($page);
    }

    public function list(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user || ! $this->canListRecommendations($user)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }

        $query = Recommendation::query()
            ->select('id', 'title', 'reference_number', 'status', 'priority', 'kind')
            ->visibleTo($user);

        return response()->json(['data' => $query->orderBy('title')->limit(200)->get()]);
    }

    public function show(Recommendation $recommendation): JsonResponse
    {
        $this->authorize('view', $recommendation);
        $recommendation->load(['meeting:id,title,reference_number', 'assignee:id,name']);

        $payload = $recommendation->toArray();
        $payload['allowed_actions'] = [
            'update' => Gate::allows('update', $recommendation),
            'delete' => Gate::allows('delete', $recommendation),
            'approve' => Gate::allows('approve', $recommendation),
            'accept' => Gate::allows('accept', $recommendation),
            'reject' => Gate::allows('reject', $recommendation),
            'defer' => Gate::allows('defer', $recommendation),
            'complete' => Gate::allows('complete', $recommendation),
        ];

        return response()->json($payload);
    }

    /**
     * Store a new recommendation.
     *
     * Kind-aware body validation lives in StoreRecommendationRequest. We add a
     * gate here: recommendations cannot be raised against a cancelled meeting
     * — once a meeting is cancelled, no new rulings or action items should
     * attach to it (the status would be inconsistent).
     */
    public function store(StoreRecommendationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated = $this->normalizeDecidableType($validated);

        if (! empty($validated['meeting_id'])) {
            $meeting = Meeting::find($validated['meeting_id']);
            if ($meeting && $meeting->status === Meeting::STATUS_CANCELLED) {
                return response()->json([
                    'message' => 'لا يمكن إضافة توصيات لاجتماع ملغى',
                ], 422);
            }
            if ($meeting) {
                // Phase 5.B: belt-and-braces defense in case FormRequest validation
                // is bypassed (e.g. legacy fixture) — enforce same-org here too.
                app(MeetingOrgGuard::class)->abortUnlessSameOrganization(
                    auth()->user(),
                    $meeting->organization_id
                );
            }
        }

        if (empty($validated['organization_id']) && ! empty($validated['meeting_id'])) {
            $meeting = $meeting ?? Meeting::find($validated['meeting_id']);
            $validated['organization_id'] = $meeting?->organization_id;
        }

        $defaultsByKind = match ($validated['kind']) {
            Recommendation::KIND_RULING => ['status' => Recommendation::STATUS_PENDING],
            Recommendation::KIND_ACTION_ITEM => ['status' => Recommendation::STATUS_PROPOSED],
            default => ['status' => Recommendation::STATUS_PROPOSED],
        };
        $validated = array_merge($defaultsByKind, $validated);
        // Action items that already carry an assignee at create time are
        // "proposed" — the notification fires once an actor accepts them.

        $rec = Recommendation::create($validated);

        DB::afterCommit(function () use ($rec): void {
            if ($rec->assignee_id && $rec->kind === Recommendation::KIND_ACTION_ITEM) {
                $assignee = User::find($rec->assignee_id);
                if ($assignee) {
                    Notification::send($assignee, new RecommendationAssignedNotification($rec));
                }
            }
        });

        $rec->load(['meeting:id,title', 'assignee:id,name']);

        return response()->json(['message' => 'تم إنشاء التوصية بنجاح', 'recommendation' => $rec], 201);
    }

    public function update(UpdateRecommendationRequest $request, Recommendation $recommendation): JsonResponse
    {
        $this->authorize('update', $recommendation);
        $validated = $request->validated();
        $validated = $this->normalizeDecidableType($validated);

        // Phase 5.B: if the request moves the recommendation to a different
        // meeting, ensure the new meeting is in the actor's organization
        // BEFORE we persist the change.
        if (! empty($validated['meeting_id'])
            && (int) $validated['meeting_id'] !== (int) $recommendation->meeting_id) {
            $newMeeting = Meeting::find($validated['meeting_id']);
            if ($newMeeting) {
                app(MeetingOrgGuard::class)->abortUnlessSameOrganization(
                    auth()->user(),
                    $newMeeting->organization_id
                );
            }
        }

        $oldAssigneeId = $recommendation->assignee_id;
        $recommendation->update($validated);
        $newAssigneeId = $recommendation->assignee_id;

        DB::afterCommit(function () use ($recommendation, $oldAssigneeId, $newAssigneeId): void {
            if ($newAssigneeId && (int) $newAssigneeId !== (int) $oldAssigneeId) {
                $newAssignee = User::find($newAssigneeId);
                if ($newAssignee) {
                    Notification::send($newAssignee, new RecommendationAssignedNotification($recommendation));
                }
            }
        });

        $recommendation->load(['meeting:id,title', 'assignee:id,name']);

        return response()->json(['message' => 'تم تحديث التوصية بنجاح', 'recommendation' => $recommendation]);
    }

    public function destroy(Recommendation $recommendation): JsonResponse
    {
        $this->authorize('delete', $recommendation);
        $recommendation->delete();

        return response()->json(['message' => 'تم حذف التوصية بنجاح']);
    }

    // ============================================================
    // Action item state machine (legacy accept/reject/defer/complete)
    // ============================================================

    public function accept(Recommendation $recommendation): JsonResponse
    {
        $this->authorize('accept', $recommendation);

        return DB::transaction(function () use ($recommendation) {
            if (! $recommendation->canTransitionTo(Recommendation::STATUS_ACCEPTED)) {
                return response()->json(['message' => 'لا يمكن قبول التوصية في الحالة الحالية'], 409);
            }

            $updated = Recommendation::whereKey($recommendation->id)
                ->whereIn('status', [
                    Recommendation::STATUS_PROPOSED,
                    Recommendation::STATUS_DEFERRED,
                ])
                ->update(['status' => Recommendation::STATUS_ACCEPTED]);

            if ($updated === 0) {
                return response()->json(['message' => 'لا يمكن قبول التوصية في الحالة الحالية'], 409);
            }

            $locked = $recommendation->fresh()->load(['meeting:id,title', 'assignee:id,name']);

            return response()->json(['message' => 'تم قبول التوصية', 'recommendation' => $locked]);
        });
    }

    public function reject(RejectRecommendationRequest $request, Recommendation $recommendation): JsonResponse
    {
        $this->authorize('reject', $recommendation);

        return DB::transaction(function () use ($recommendation, $request) {
            $rationale = $request->validated()['rationale'] ?? null;

            // Action items: proposed/deferred -> rejected (accepted cannot be
            // rejected — once accepted it must be completed or deferred).
            // Rulings: pending/deferred -> rejected.
            $guardedFrom = match ($recommendation->kind) {
                Recommendation::KIND_RULING => [Recommendation::STATUS_PENDING, Recommendation::STATUS_DEFERRED],
                Recommendation::KIND_ACTION_ITEM => [
                    Recommendation::STATUS_PROPOSED,
                    Recommendation::STATUS_DEFERRED,
                ],
                default => [],
            };

            if (! $recommendation->canTransitionTo(Recommendation::STATUS_REJECTED)) {
                return response()->json(['message' => 'لا يمكن رفض التوصية في الحالة الحالية'], 409);
            }

            $updated = Recommendation::whereKey($recommendation->id)
                ->whereIn('status', $guardedFrom)
                ->update([
                    'status' => Recommendation::STATUS_REJECTED,
                    'rationale' => $rationale ?? $recommendation->rationale,
                    'made_by' => auth()->id(),
                    'decision_date' => now(),
                ]);

            if ($updated === 0) {
                return response()->json(['message' => 'لا يمكن رفض التوصية في الحالة الحالية'], 409);
            }

            $locked = $recommendation->fresh()->load(['meeting:id,title', 'assignee:id,name']);

            return response()->json(['message' => 'تم رفض التوصية', 'recommendation' => $locked]);
        });
    }

    public function defer(DeferRecommendationRequest $request, Recommendation $recommendation): JsonResponse
    {
        $this->authorize('defer', $recommendation);

        return DB::transaction(function () use ($recommendation, $request) {
            $payload = $request->validated();
            $reason = $payload['defer_reason'] ?? null;
            $until = $payload['deferred_until'] ?? null;

            // Allow defer from proposed/accepted (action_item) or
            // pending/approved (ruling). Each kind's allowed source set is
            // enforced by canTransitionTo() — we additionally guard the
            // status at the DB level so two concurrent defers can't both
            // succeed if a sibling accept/reject completed first.
            $guardedFrom = match ($recommendation->kind) {
                Recommendation::KIND_RULING => [
                    Recommendation::STATUS_PENDING,
                    Recommendation::STATUS_APPROVED,
                ],
                Recommendation::KIND_ACTION_ITEM => [
                    Recommendation::STATUS_PROPOSED,
                    Recommendation::STATUS_ACCEPTED,
                ],
                default => [],
            };

            if (! $recommendation->canTransitionTo(Recommendation::STATUS_DEFERRED)) {
                return response()->json(['message' => 'لا يمكن تأجيل التوصية في الحالة الحالية'], 409);
            }

            $updated = Recommendation::whereKey($recommendation->id)
                ->whereIn('status', $guardedFrom)
                ->update([
                    'status' => Recommendation::STATUS_DEFERRED,
                    'defer_reason' => $reason,
                    'deferred_until' => $until,
                    'deferred_by' => auth()->id(),
                    'deferred_at' => now(),
                ]);

            if ($updated === 0) {
                return response()->json(['message' => 'لا يمكن تأجيل التوصية في الحالة الحالية'], 409);
            }

            $locked = $recommendation->fresh()->load(['meeting:id,title', 'assignee:id,name']);

            return response()->json(['message' => 'تم تأجيل التوصية', 'recommendation' => $locked]);
        });
    }

    public function complete(Recommendation $recommendation): JsonResponse
    {
        $this->authorize('complete', $recommendation);

        // Completion gate (Direction B): an action_item recommendation cannot
        // be marked complete while it still has open (non-terminal) tasks
        // hanging off it. Hitting this returns 422 with the pending task ids.
        $pendingTaskIds = $this->pendingTaskIdsFor($recommendation);
        if ($pendingTaskIds !== []) {
            return response()->json([
                'message' => 'لا يمكن إنجاز التوصية قبل إنجاز جميع المهام المرتبطة',
                'pending_task_ids' => $pendingTaskIds,
            ], 422);
        }

        return DB::transaction(function () use ($recommendation) {
            if (! $recommendation->canTransitionTo(Recommendation::STATUS_COMPLETED)) {
                return response()->json(['message' => 'لا يمكن إنجاز التوصية في الحالة الحالية'], 409);
            }

            $updated = Recommendation::whereKey($recommendation->id)
                ->whereIn('status', [Recommendation::STATUS_ACCEPTED, Recommendation::STATUS_DEFERRED])
                ->update([
                    'status' => Recommendation::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

            if ($updated === 0) {
                return response()->json(['message' => 'لا يمكن إنجاز التوصية في الحالة الحالية'], 409);
            }

            $locked = $recommendation->fresh()->load(['meeting:id,title', 'assignee:id,name']);

            return response()->json(['message' => 'تم إنجاز التوصية', 'recommendation' => $locked]);
        });
    }

    // ============================================================
    // Ruling state machine (Direction B: pending -> approved/rejected/deferred)
    // ============================================================

    public function approve(ApproveRecommendationRequest $request, Recommendation $recommendation): JsonResponse
    {
        $this->authorize('approve', $recommendation);

        return DB::transaction(function () use ($recommendation, $request) {
            $rationale = $request->validated()['rationale'] ?? null;

            $updated = Recommendation::whereKey($recommendation->id)
                ->whereIn('status', [
                    Recommendation::STATUS_PENDING,
                    Recommendation::STATUS_DEFERRED,
                ])
                ->update([
                    'status' => Recommendation::STATUS_APPROVED,
                    'made_by' => auth()->id(),
                    'decision_date' => now(),
                    'rationale' => $rationale ?? $recommendation->rationale,
                ]);

            if ($updated === 0) {
                return response()->json(['message' => 'لا يمكن اعتماد التوصية في الحالة الحالية'], 409);
            }

            $locked = $recommendation->fresh();
            $requester = $locked->requester;

            DB::afterCommit(function () use ($locked, $requester): void {
                if ($requester && (int) $requester->id !== (int) auth()->id()) {
                    // Direction B: the recommendation controller does not own a
                    // dedicated approval notification class. The assign-side
                    // notification is reused — it carries the same reference
                    // and lets the requester know the ruling has been
                    // resolved.
                    Notification::send($requester, new RecommendationAssignedNotification($locked));
                }
            });

            return response()->json(['message' => 'تمت الموافقة على التوصية', 'recommendation' => $locked->load(['meeting:id,title', 'assignee:id,name'])]);
        });
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * @return array<int, int> List of non-terminal task ids attached to this
     *                         recommendation via the polymorphic
     *                         `source_type`/`source_id` link on tasks.
     *                         An action_item recommendation cannot be
     *                         completed while this list is non-empty.
     */
    private function pendingTaskIdsFor(Recommendation $recommendation): array
    {
        return Task::query()
            ->where('source_type', Recommendation::class)
            ->where('source_id', $recommendation->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->pluck('id')
            ->all();
    }

    private function normalizeDecidableType(array $validated): array
    {
        if (isset($validated['decidable_type'])) {
            $validated['decidable_type'] = DecidableType::classFor($validated['decidable_type']);
        }

        return $validated;
    }
}
