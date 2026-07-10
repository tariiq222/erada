<?php

namespace App\Modules\OVR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Http\Requests\DestroyIncidentReportRequest;
use App\Modules\OVR\Http\Requests\StoreIncidentReportRequest;
use App\Modules\OVR\Http\Requests\UpdateIncidentReportRequest;
use App\Modules\OVR\Http\Requests\UpdateStatusRequest;
use App\Modules\OVR\Http\Resources\IncidentReportResource;
use App\Modules\OVR\Models\IncidentParticipant;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Notifications\ReportAssignedNotification;
use App\Modules\OVR\Notifications\ReportSubmittedNotification;
use App\Modules\OVR\Notifications\StatusChangedNotification;
use App\Modules\OVR\Scopes\UserOvrScope;
use App\Modules\OVR\Services\IncidentExportService;
use App\Modules\OVR\Services\OvrAuthorizationService;
use App\Modules\Shared\Http\Resources\ActivityLogResource;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Shared\Scopes\UserActivityLogScope;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $this->authorize('viewAny', IncidentReport::class);

        $query = IncidentReport::query()
            ->forOrganization($user->organization_id)
            ->visibleTo($user)
            ->with(['reporter', 'incidentType', 'assignee'])
            ->withCount(['comments', 'statusHistory']);

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Filter by severity
        if ($severity = $request->query('severity')) {
            $query->where('severity_level', $severity);
        }

        // Filter by incident type
        if ($typeId = $request->query('incident_type_id')) {
            $query->where('incident_type_id', $typeId);
        }

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('report_number', 'like', "%{$search}%")
                    ->orWhere('incident_description', 'like', "%{$search}%")
                    ->orWhere('reporter_name', 'like', "%{$search}%");
            });
        }

        $reports = $query->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 15), 100));

        return IncidentReportResource::collection($reports->through(
            fn (IncidentReport $report) => IncidentReportResource::summary($report)
        ))->additional(['success' => true]);
    }

    public function store(StoreIncidentReportRequest $request): JsonResponse
    {
        // Authorization is handled by StoreIncidentReportRequest::authorize()
        // (reporting is open to any authenticated org member).
        $user = $request->user();

        $report = DB::transaction(function () use ($request, $user) {
            $data = $request->validated();
            $data['organization_id'] = $user->organization_id;
            $data['reporter_id'] = $user->id;
            $data['reporter_name'] = $user->name;
            $data['reporter_email'] = $user->email;
            $data['reporter_job_title'] = $user->job_title;
            $data['reporter_extension'] = $user->extension;
            // Governing-department members may target a different department;
            // everyone else is pinned to their own department (field removed below
            // to prevent spoofing). Authorization already validated the target.
            if (empty($data['reporter_department_id'])) {
                $data['reporter_department_id'] = $user->department_id;
            }
            $data['status'] = ReportStatus::Draft;

            $report = IncidentReport::create($data);

            // Set due date based on severity
            $report->due_date = $report->calculateDueDate();
            $report->save();

            return $report;
        });

        return response()->json([
            'message' => __('ovr.api.created'),
            'data' => IncidentReportResource::detail($report->load(['reporter', 'incidentType'])),
        ], 201);
    }

    /**
     * Departments the current user may target as reporter_department_id when
     * creating a report. Governs the create-form department picker.
     */
    public function creatableDepartments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return response()->json(['message' => __('ovr.api.user_not_in_organization')], 403);
        }

        $allowedIds = app(OvrAuthorizationService::class)
            ->creatableDepartmentIds($user);

        $query = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'parent_id', 'level')
            ->orderBy('level')
            ->orderBy('name');

        if ($allowedIds !== null) {
            $query->whereIn('id', $allowedIds === [] ? [-1] : $allowedIds);
        }

        $departments = $query->get()->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'parent_id' => $d->parent_id,
            'level' => $d->level,
            'level_name' => $d->getLevelNameAttribute(),
        ]);

        return response()->json(['all' => $departments]);
    }

    /**
     * Invite a participant to a report (cross-department read access).
     *
     * Authorization chain (defense-in-depth, P0 #3 fix):
     *   1. engine OVR_EDIT against the report (positional scope + confidentiality).
     *   2. Same-org gate — a user from another organization may not add participants
     *      to a report they cannot view. super_admin bypasses the org check.
     *
     * ponytail: Capability::OVR_MANAGE does not exist; OVR_EDIT is the closest
     * available constant for "manage OVR report participants" and is the same gate
     * the route middleware enforces upstream.
     */
    public function addParticipant(Request $request, IncidentReport $report): JsonResponse
    {
        $user = $request->user();

        if (! AccessDecision::can($user, Capability::OVR_EDIT, $report)) {
            abort(403);
        }

        abort_unless(
            $user->isSuperAdmin() || (int) $report->organization_id === (int) $user->organization_id,
            403
        );

        $validated = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')
                    ->where('organization_id', $user->isSuperAdmin() ? $report->organization_id : $user->organization_id),
            ],
        ]);

        IncidentParticipant::firstOrCreate([
            'incident_report_id' => $report->id,
            'user_id' => $validated['user_id'],
        ], [
            'invited_by' => $user->id,
        ]);

        $participants = $report->participants()->with('user:id,name,email')->get()->map(fn ($p) => [
            'user_id' => $p->user_id,
            'name' => $p->user?->name,
            'invited_by' => $p->invited_by,
        ]);

        return response()->json(['message' => __('ovr.api.participant_invited'), 'participants' => $participants], 201);
    }

    /**
     * Remove an invited participant from a report.
     *
     * Same two-gate authorization chain as addParticipant (P0 #3 mirror).
     */
    public function removeParticipant(Request $request, IncidentReport $report, User $participant): JsonResponse
    {
        $user = $request->user();

        if (! AccessDecision::can($user, Capability::OVR_EDIT, $report)) {
            abort(403);
        }

        abort_unless(
            $user->isSuperAdmin() || (int) $report->organization_id === (int) $user->organization_id,
            403
        );

        IncidentParticipant::where('incident_report_id', $report->id)
            ->where('user_id', $participant->id)
            ->delete();

        return response()->json(['message' => __('ovr.api.participant_removed')]);
    }

    public function show(Request $request, IncidentReport $report): JsonResponse
    {
        $user = $request->user();
        $this->authorize('view', $report);

        $report->load(['reporter', 'incidentType', 'reportableType', 'assignee', 'closer', 'reopener', 'comments.user', 'statusHistory.changer']);

        return ApiResponse::success([
            'data' => IncidentReportResource::detail($report),
        ]);
    }

    public function update(UpdateIncidentReportRequest $request, IncidentReport $report): JsonResponse
    {
        $this->authorize('update', $report);

        $report->update($request->validated());

        // P2 audit fix: a severity change must recompute the SLA due_date,
        // otherwise a New report with severity Low (due in 48h) keeps the
        // 48-hour window even after being upgraded to Critical (4h).
        // saveQuietly() skips LogsActivity/observer noise — the audit log row
        // already captures the severity_level change in the same update().
        if ($report->wasChanged('severity_level')) {
            $report->due_date = $report->calculateDueDate();
            $report->saveQuietly();
        }

        return response()->json([
            'message' => __('ovr.api.updated'),
            'data' => IncidentReportResource::detail($report->load(['reporter', 'incidentType'])),
        ]);
    }

    public function destroy(DestroyIncidentReportRequest $request, IncidentReport $report): JsonResponse
    {
        // Authorization is handled by DestroyIncidentReportRequest::authorize()
        // (engine-first: OVR_DELETE/OVR_DELETE_ALL via IncidentReportPolicy::delete()).
        $report->delete();

        return response()->json([
            'message' => __('ovr.api.deleted'),
        ]);
    }

    /**
     * Update report status with state machine validation
     *
     * Race defense (P0 #5): the fast-path 422 check runs against the route-bound
     * model; the actual mutation runs against a row re-loaded under
     * SELECT FOR UPDATE, and `canTransitionTo()` is re-checked after the lock
     * closes. Two writers advancing the same report can no longer both observe
     * a transition as legal and commit a torn state.
     */
    public function updateStatus(UpdateStatusRequest $request, IncidentReport $report): JsonResponse
    {
        $this->authorize('changeStatus', $report);

        $actor = $request->user();
        $newStatus = ReportStatus::from($request->input('status'));
        $oldStatus = $report->status;

        // Fast-path pre-lock check: gives a clean 422 surface without taking a
        // row lock when the request itself is invalid.
        if (! $report->canTransitionTo($newStatus)) {
            return response()->json([
                'message' => __('ovr.api.status_transition_invalid', ['from' => $oldStatus->label(), 'to' => $newStatus->label()]),
            ], 422);
        }

        // P1 audit fix: prevent self-resolution. A reporter cannot be the
        // assignee + resolver + closer of their own report — that would bypass
        // independent review and let one person self-certify their incident.
        // super_admin bypasses the check (governance can override).
        $isSelfResolving = in_array($newStatus, [ReportStatus::Resolved, ReportStatus::Closed], true)
            && (int) $report->reporter_id === (int) $actor->id
            && (int) $report->assigned_to === (int) $actor->id;
        if ($isSelfResolving && ! $actor->isSuperAdmin()) {
            abort(response()->json([
                'message' => __('ovr.api.self_resolution_forbidden'),
            ], 403));
        }

        $updates = ['status' => $newStatus];

        // Handle specific transition metadata
        if ($newStatus === ReportStatus::InProgress && $request->filled('assigned_to')) {
            $this->authorize('assign', $report);

            $assignee = User::findOrFail($request->integer('assigned_to'));

            if ($assignee->organization_id !== $report->organization_id) {
                return response()->json([
                    'message' => __('ovr.api.cross_org_assign_forbidden'),
                ], 403);
            }

            $updates['assigned_to'] = $assignee->id;
            $updates['assigned_at'] = now();
        }

        if ($newStatus === ReportStatus::Resolved) {
            $updates['resolved_at'] = now();
        }

        if ($newStatus === ReportStatus::Closed) {
            $updates['closed_at'] = now();
            $updates['closed_by'] = $actor->id;
            $updates['closure_reason'] = $request->input('closure_reason');
        }

        if ($newStatus === ReportStatus::Archived) {
            // Archived from closed
        }

        if ($newStatus === ReportStatus::UnderReview && $oldStatus === ReportStatus::Resolved) {
            // Reject resolution - clear resolved_at
            $updates['resolved_at'] = null;
        }

        // P0 #6 (Fix D): the archived -> closed transition is an un-archive, not
        // a true reopen. The previous code wrote reopened_at/by/reason here, which
        // poisoned the audit history with "user X reopened report Y" entries for
        // a routine un-archive. The columns stay on the model (in $fillable) so a
        // future real reopen path can still use them — only the writes are gone.
        // (ponytail shortcut: no dedicated reopen action exists yet; a future
        // PR will add one and re-introduce the writes here.)

        $wasAssigned = isset($updates['assigned_to']) && $updates['assigned_to'] !== $report->assigned_to;

        DB::transaction(function () use ($report, $updates, $oldStatus, $newStatus, $actor, $request) {
            // Race defense (P0 #5): re-load under SELECT FOR UPDATE so the
            // transition decision + recordStatusChange observe the source-of-
            // truth status, not the route-bound snapshot.
            $locked = IncidentReport::where('id', $report->id)->lockForUpdate()->first();
            if (! $locked) {
                abort(404);
            }

            // Post-lock re-check: another writer may have advanced the status
            // between the fast-path 422 above and the row lock here.
            if (! $locked->canTransitionTo($newStatus)) {
                throw new HttpResponseException(
                    response()->json([
                        'message' => __('ovr.api.status_transition_invalid', ['from' => $oldStatus->label(), 'to' => $newStatus->label()]),
                    ], 422)
                );
            }

            $locked->update($updates);
            $locked->recordStatusChange($oldStatus, $newStatus, $actor->id, $request->input('reason'));

            // Sync the in-memory $report with the locked row so the response and
            // the post-commit side effects read the just-updated state.
            $report->setRawAttributes($locked->getAttributes(), true);
        });

        // Notify the reporter of the status change.
        if ($report->reporter && $report->reporter_id !== $actor->id) {
            $report->reporter->notify(new StatusChangedNotification($report, $oldStatus, $newStatus));
        }

        // Notify the newly assigned handler and create a follow-up task.
        if ($wasAssigned && $newAssignee = User::find($report->assigned_to)) {
            $newAssignee->notify(new ReportAssignedNotification($report));
            $this->createHandlerTask($report, $newAssignee, $actor->id);
        }

        return response()->json([
            'message' => __('ovr.api.status_changed_to', ['status' => $newStatus->label()]),
            'data' => IncidentReportResource::detail($report->fresh(['reporter', 'incidentType', 'assignee'])),
        ]);
    }

    /**
     * Auto-create a follow-up task for the handler assigned to an incident.
     * A task failure must never block the status change, so this is best-effort.
     *
     * The task is DEPARTMENT-typed and carries the polymorphic source pointer
     * (IncidentReport::class + id) so the Tasks module can honor the report's
     * own visibility/confidentiality rules and re-derive the scope parent from
     * the source row. source_sensitivity is propagated so a confidential report
     * does not surface in the assignee's open-task list through a narrower
     * scope (P1 #C — cross-module audit).
     */
    protected function createHandlerTask(IncidentReport $report, User $assignee, int $createdBy): void
    {
        try {
            // Post Direction R: tasks.source_id is unsignedBigInteger (the
            // schema predates UUID sources). IncidentReport.id is a UUID and
            // cannot fit a bigint, so we do NOT stamp source_id on OVR
            // handler tasks — only source_type + source_sensitivity. The
            // title carries the report number and is unique per report, so
            // we dedup by title + report_number in the title match below
            // (the legacy "where source_id" path would either 22P02 on
            // insert or silently miss duplicates on the exists check).
            $taskTitle = "معالجة حادثة {$report->report_number}";
            $exists = Task::query()
                ->where('source_type', IncidentReport::class)
                ->where('title', $taskTitle)
                ->whereNotIn('status', [TaskStatus::COMPLETED, TaskStatus::CANCELLED])
                ->exists();

            if ($exists) {
                return;
            }

            Task::create([
                'type' => TaskType::DEPARTMENT,
                'title' => $taskTitle,
                'description' => Str::limit($report->incident_description, 500),
                'status' => TaskStatus::TODO,
                'priority' => $report->severity_level?->value === 'critical' ? TaskPriority::CRITICAL : TaskPriority::HIGH,
                'progress' => 0,
                'due_date' => $report->due_date,
                'assigned_to' => $assignee->id,
                'created_by' => $createdBy,
                'owner_id' => $assignee->id,
                'department_id' => $report->reporter_department_id,
                'source_type' => IncidentReport::class,
                // source_id intentionally omitted: OVR IncidentReport.id is
                // a UUID and the column is bigint. Source-tracking for OVR
                // handler tasks flows through source_type + title (which
                // embeds the report_number) — see the dedup query above.
                'source_sensitivity' => $report->is_confidential ? 'confidential' : 'normal',
            ]);
        } catch (\Throwable $e) {
            Log::warning('OVR handler task auto-creation failed', [
                'report_id' => $report->id,
                'assignee_id' => $assignee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Submit a draft report (change status from draft to new)
     */
    public function submit(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorize('update', $report);

        if ($report->status !== ReportStatus::Draft) {
            return response()->json([
                'message' => __('ovr.api.submit_only_from_draft'),
            ], 422);
        }

        $oldStatus = $report->status;
        $newStatus = ReportStatus::New;

        DB::transaction(function () use ($report, $oldStatus, $newStatus, $request) {
            $report->update(['status' => $newStatus]);
            $report->recordStatusChange($oldStatus, $newStatus, $request->user()->id, 'إرسال التقرير');
        });

        // Notify reviewers (quality/safety team) that a new report needs review.
        // Engine-driven lookup (P0 #7): the flat `permission('ovr.view_all')` Spatie
        // path is retired under the engine cutover — reviewers are now resolved by
        // the same AccessDecision walk that gates the UI, so positional department
        // grants and org-functional roles both reach the inbox without a parallel
        // flat-perm seeding.
        $reviewers = User::where('organization_id', $report->organization_id)
            ->get()
            ->filter(fn (User $u): bool => AccessDecision::can($u, Capability::OVR_VIEW, $report))
            ->reject(fn (User $u): bool => $u->id === $request->user()->id)
            ->values();

        if ($reviewers->isNotEmpty()) {
            Notification::send($reviewers, new ReportSubmittedNotification($report));
        }

        return response()->json([
            'message' => __('ovr.api.submitted'),
            'data' => IncidentReportResource::detail($report->fresh()),
        ]);
    }

    /**
     * Get statistics for dashboard
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewStatistics', IncidentReport::class);
        $reportsTable = (new IncidentReport)->getTable();

        $baseQuery = IncidentReport::query()
            ->where("{$reportsTable}.organization_id", $user->organization_id)
            ->visibleTo($user);

        // Optional date-range filtering: ?period=day|week|month|year or ?from=&to=
        [$from, $to] = $this->resolveDateRange($request);
        if ($from) {
            $baseQuery->where("{$reportsTable}.created_at", '>=', $from);
        }
        if ($to) {
            $baseQuery->where("{$reportsTable}.created_at", '<=', $to);
        }

        $this->applyStatsFilters($baseQuery, $request, $reportsTable);

        $total = (clone $baseQuery)->count();

        $byStatus = [];
        foreach (ReportStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $baseQuery)->where("{$reportsTable}.status", $status->value)->count();
        }

        $bySeverity = [];
        foreach (SeverityLevel::cases() as $severity) {
            $bySeverity[$severity->value] = (clone $baseQuery)->where("{$reportsTable}.severity_level", $severity->value)->count();
        }

        $patientRelated = (clone $baseQuery)->where("{$reportsTable}.is_patient_related", true)->count();
        $informedAuthority = (clone $baseQuery)->where("{$reportsTable}.informed_authority", true)->count();
        $immediateActionRequired = (clone $baseQuery)->where("{$reportsTable}.immediate_action_required", true)->count();
        $confidential = (clone $baseQuery)->where("{$reportsTable}.is_confidential", true)->count();

        $overdue = (clone $baseQuery)
            ->whereNotIn("{$reportsTable}.status", [ReportStatus::Closed->value, ReportStatus::Archived->value, ReportStatus::Resolved->value])
            ->where("{$reportsTable}.due_date", '<', now())
            ->count();

        $avgResult = (clone $baseQuery)
            ->whereNotNull("{$reportsTable}.resolved_at")
            ->selectRaw("AVG(EXTRACT(EPOCH FROM ({$reportsTable}.resolved_at - {$reportsTable}.created_at)) / 3600) as avg_hours")
            ->first();

        $avgResolutionTime = $avgResult?->avg_hours ?? 0;

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'patient_related' => $patientRelated,
            'informed_authority' => $informedAuthority,
            'overdue' => $overdue,
            'avg_resolution_hours' => round((float) $avgResolutionTime, 2),
            'immediate_action_required' => $immediateActionRequired,
            'confidential' => $confidential,
            'rates' => [
                'patient_related' => $this->percentage($patientRelated, $total),
                'informed_authority' => $this->percentage($informedAuthority, $total),
                'immediate_action_required' => $this->percentage($immediateActionRequired, $total),
                'confidential' => $this->percentage($confidential, $total),
            ],
            'breakdowns' => [
                'incident_type' => $this->namedBreakdown($baseQuery, 'incident_type_id', 'ovr_incident_types', true),
                'reportable_type' => $this->namedBreakdown($baseQuery, 'reportable_incident_type_id', 'ovr_reportable_types', true),
                'department' => $this->namedBreakdown($baseQuery, 'reporter_department_id', 'departments', false),
                'patient_gender' => $this->patientGenderBreakdown($baseQuery),
                'contributing_factor' => $this->contributingFactorBreakdown($baseQuery),
                'monthly_trend' => $this->monthlyTrendBreakdown($baseQuery),
            ],
            'period' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Phase CFA-09 — Cluster aggregate statistics (NEVER raw).
     *
     * Returns AGGREGATE counts / breakdowns across the actor's organization
     * AND its descendants when the actor holds BOTH
     * Capability::OVR_VIEW_STATISTICS + Capability::CLUSTER_TREE_VIEW on
     * actor.organization_id. Without both grants, the floor is strict
     * same-org (the actor's organization only).
     *
     * STRICT INVARIANTS — verified by ClusterTreeOvrConfidentialFloorInvariantTest:
     *   - AGGREGATE ONLY. No row-level incident data is returned. The response
     *     shape is {total, by_status, by_severity, ...per_org, period, scope}.
     *   - is_confidential floor preserved: confidential rows are excluded from
     *     the aggregate via UserOvrScope::applyToIncidentReportsForStats.
     *   - does NOT widen raw index/show/recent/export paths (those stay strict).
     *
     * Mirrors the per-org stats() shape, plus a `per_org` block listing each
     * visible descendant organization's counts.
     */
    public function clusterStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewStats', IncidentReport::class);

        $reportsTable = (new IncidentReport)->getTable();

        $baseQuery = IncidentReport::query();
        // Cluster widening (CFA-09): widens org floor to descendants when actor
        // holds BOTH grants. Filters out confidential reports unconditionally
        // (the cluster actor does not hold OVR_CONFIDENTIAL by construction).
        app(UserOvrScope::class)->applyToIncidentReportsForStats($baseQuery, $user);

        [$from, $to] = $this->resolveDateRange($request);
        if ($from) {
            $baseQuery->where("{$reportsTable}.created_at", '>=', $from);
        }
        if ($to) {
            $baseQuery->where("{$reportsTable}.created_at", '<=', $to);
        }

        $this->applyStatsFilters($baseQuery, $request, $reportsTable);

        // Aggregate counts (org-isolated by the scope above).
        $total = (clone $baseQuery)->count();

        $byStatus = [];
        foreach (ReportStatus::cases() as $status) {
            $byStatus[$status->value] = (clone $baseQuery)->where("{$reportsTable}.status", $status->value)->count();
        }

        $bySeverity = [];
        foreach (SeverityLevel::cases() as $severity) {
            $bySeverity[$severity->value] = (clone $baseQuery)->where("{$reportsTable}.severity_level", $severity->value)->count();
        }

        $patientRelated = (clone $baseQuery)->where("{$reportsTable}.is_patient_related", true)->count();
        $immediateActionRequired = (clone $baseQuery)->where("{$reportsTable}.immediate_action_required", true)->count();

        $overdue = (clone $baseQuery)
            ->whereNotIn("{$reportsTable}.status", [ReportStatus::Closed->value, ReportStatus::Archived->value, ReportStatus::Resolved->value])
            ->where("{$reportsTable}.due_date", '<', now())
            ->count();

        // Per-org breakdown (the cluster-specific surface). One row per visible
        // org — total, by_status, by_severity. NEVER row-level data.
        $perOrg = $this->clusterPerOrgBreakdown($baseQuery, $reportsTable);

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'patient_related' => $patientRelated,
            'immediate_action_required' => $immediateActionRequired,
            'overdue' => $overdue,
            'per_org' => $perOrg,
            'scope' => [
                'mode' => 'cluster_aggregate',
                'organizations_visible' => array_column($perOrg, 'organization_id'),
            ],
            'period' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Per-org aggregate breakdown for clusterStats(). Returns one row per visible
     * organization with counts only. Never row-level incident data.
     *
     * @return list<array{organization_id: int, organization_name: ?string, total: int, by_status: array<string, int>, by_severity: array<string, int>}>
     */
    protected function clusterPerOrgBreakdown($baseQuery, string $reportsTable): array
    {
        $orgIds = array_values(array_unique(array_map(
            fn ($row) => (int) $row->organization_id,
            (clone $baseQuery)->select("{$reportsTable}.organization_id")->distinct()->get()->all()
        )));

        if ($orgIds === []) {
            return [];
        }

        $orgs = Organization::query()
            ->whereIn('id', $orgIds)
            ->select('id', 'name')
            ->get()
            ->keyBy('id');

        $byStatus = [];
        foreach (ReportStatus::cases() as $status) {
            $rows = (clone $baseQuery)
                ->where("{$reportsTable}.status", $status->value)
                ->select("{$reportsTable}.organization_id")
                ->selectRaw('COUNT(*) as aggregate_count')
                ->groupBy("{$reportsTable}.organization_id")
                ->get();
            foreach ($rows as $row) {
                $byStatus[(int) $row->organization_id][$status->value] = (int) $row->aggregate_count;
            }
        }

        $bySeverity = [];
        foreach (SeverityLevel::cases() as $severity) {
            $rows = (clone $baseQuery)
                ->where("{$reportsTable}.severity_level", $severity->value)
                ->select("{$reportsTable}.organization_id")
                ->selectRaw('COUNT(*) as aggregate_count')
                ->groupBy("{$reportsTable}.organization_id")
                ->get();
            foreach ($rows as $row) {
                $bySeverity[(int) $row->organization_id][$severity->value] = (int) $row->aggregate_count;
            }
        }

        $totals = (clone $baseQuery)
            ->select("{$reportsTable}.organization_id")
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy("{$reportsTable}.organization_id")
            ->pluck('aggregate_count', "{$reportsTable}.organization_id");

        $out = [];
        foreach ($orgIds as $orgId) {
            $byStatusRow = $byStatus[$orgId] ?? [];
            $bySeverityRow = $bySeverity[$orgId] ?? [];
            $out[] = [
                'organization_id' => $orgId,
                'organization_name' => $orgs[$orgId]->name ?? null,
                'total' => (int) ($totals[$orgId] ?? 0),
                'by_status' => $byStatusRow,
                'by_severity' => $bySeverityRow,
            ];
        }

        return $out;
    }

    /**
     * Phase CFA-09 — Cluster aggregate export (NEVER raw).
     *
     * Writes AGGREGATE rows (one per descendant org) to CSV or PDF — never
     * raw incident rows. The CSV row format is intentionally structured so a
     * downstream consumer cannot reconstruct individual incident records
     * from the export: report_number / patient_name / patient_file_number /
     * incident_description / reporter_name are NEVER included.
     *
     * Gated by IncidentReportPolicy::exportsAggregates (OVR_EXPORT +
     * CLUSTER_TREE_EXPORT). The existing /incidents/export endpoint stays
     * strict same-org and unchanged.
     */
    public function clusterExport(Request $request): StreamedResponse|Response
    {
        $user = $request->user();
        $this->authorize('exportsAggregates', IncidentReport::class);

        $reportsTable = (new IncidentReport)->getTable();

        $baseQuery = IncidentReport::query();
        app(UserOvrScope::class)->applyToIncidentReportsForExport($baseQuery, $user);

        [$from, $to] = $this->resolveDateRange($request);
        if ($from) {
            $baseQuery->where("{$reportsTable}.created_at", '>=', $from);
        }
        if ($to) {
            $baseQuery->where("{$reportsTable}.created_at", '<=', $to);
        }

        $this->applyStatsFilters($baseQuery, $request, $reportsTable);

        $perOrg = $this->clusterPerOrgBreakdown($baseQuery, $reportsTable);

        $stamp = now()->format('Y-m-d');
        $format = $request->query('format');

        // CSV is aggregate-only (one row per visible org + count columns).
        if ($format !== 'pdf') {
            return $this->streamClusterAggregateCsv($perOrg, "ovr-cluster-aggregate-{$stamp}.csv");
        }

        // PDF path is also aggregate-only.
        return $this->downloadClusterAggregatePdf($perOrg, "ovr-cluster-aggregate-{$stamp}.pdf");
    }

    /**
     * Stream the cluster aggregate breakdown as CSV. One header row, then
     * one row per organization. NEVER includes row-level incident data.
     *
     * @param  list<array{organization_id: int, organization_name: ?string, total: int, by_status: array<string, int>, by_severity: array<string, int>}>  $perOrg
     */
    protected function streamClusterAggregateCsv(array $perOrg, string $filename): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($perOrg) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'organization_id',
                'organization_name',
                'total',
                'open',
                'in_progress',
                'resolved',
                'closed',
                'archived',
                'rejected',
                'low',
                'medium',
                'high',
                'critical',
            ]);

            foreach ($perOrg as $row) {
                fputcsv($out, [
                    $row['organization_id'],
                    $row['organization_name'],
                    $row['total'],
                    $row['by_status']['open'] ?? 0,
                    $row['by_status']['in_progress'] ?? 0,
                    $row['by_status']['resolved'] ?? 0,
                    $row['by_status']['closed'] ?? 0,
                    $row['by_status']['archived'] ?? 0,
                    $row['by_status']['rejected'] ?? 0,
                    $row['by_severity']['low'] ?? 0,
                    $row['by_severity']['medium'] ?? 0,
                    $row['by_severity']['high'] ?? 0,
                    $row['by_severity']['critical'] ?? 0,
                ]);
            }

            fclose($out);
        }, 200, $headers);
    }

    /**
     * Render the cluster aggregate breakdown as a PDF.
     *
     * @param  list<array{organization_id: int, organization_name: ?string, total: int, by_status: array<string, int>, by_severity: array<string, int>}>  $perOrg
     */
    protected function downloadClusterAggregatePdf(array $perOrg, string $filename): Response
    {
        $pdf = Pdf::loadView('ovr.export-cluster-pdf', [
            'perOrg' => $perOrg,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * Resolve a [from, to] date range from request params.
     * Accepts ?period=day|week|month|year for presets, or ?from=&to= for a custom range.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function resolveDateRange(Request $request): array
    {
        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
            $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

            return [$from, $to];
        }

        $period = $request->query('period');

        return match ($period) {
            'day' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [null, null],
        };
    }

    protected function applyStatsFilters($query, Request $request, string $reportsTable): void
    {
        $status = $this->enumQueryValue($request, 'status', array_map(fn (ReportStatus $status) => $status->value, ReportStatus::cases()));
        if ($status !== null) {
            $query->where("{$reportsTable}.status", $status);
        }

        $severity = $this->enumQueryValue($request, 'severity', array_map(fn (SeverityLevel $severity) => $severity->value, SeverityLevel::cases()));
        if ($severity !== null) {
            $query->where("{$reportsTable}.severity_level", $severity);
        }

        if ($request->filled('incident_type_id') && Str::isUuid((string) $request->query('incident_type_id'))) {
            $query->where("{$reportsTable}.incident_type_id", (string) $request->query('incident_type_id'));
        }

        if ($request->filled('reportable_incident_type_id') && Str::isUuid((string) $request->query('reportable_incident_type_id'))) {
            $query->where("{$reportsTable}.reportable_incident_type_id", (string) $request->query('reportable_incident_type_id'));
        }

        foreach ([
            'is_patient_related' => 'is_patient_related',
            'informed_authority' => 'informed_authority',
            'immediate_action_required' => 'immediate_action_required',
            'is_confidential' => 'is_confidential',
        ] as $parameter => $column) {
            $value = $this->booleanQueryValue($request, $parameter);
            if ($value !== null) {
                $query->where("{$reportsTable}.{$column}", $value);
            }
        }

        $patientGender = $this->enumQueryValue($request, 'patient_gender', ['male', 'female', 'unspecified']);
        if ($patientGender !== null) {
            $query->where("{$reportsTable}.patient_gender", $patientGender);
        }

        if ($request->filled('reporter_department_id') && ctype_digit((string) $request->query('reporter_department_id'))) {
            $query->where("{$reportsTable}.reporter_department_id", (int) $request->query('reporter_department_id'));
        }

        if ($request->filled('contributing_factor')) {
            $query->whereJsonContains("{$reportsTable}.contributing_factors", (string) $request->query('contributing_factor'));
        }
    }

    protected function enumQueryValue(Request $request, string $parameter, array $allowedValues): ?string
    {
        if (! $request->filled($parameter)) {
            return null;
        }

        $value = (string) $request->query($parameter);

        return in_array($value, $allowedValues, true) ? $value : null;
    }

    protected function booleanQueryValue(Request $request, string $parameter): ?bool
    {
        if (! $request->filled($parameter)) {
            return null;
        }

        return filter_var($request->query($parameter), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    protected function percentage(int $count, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($count / $total) * 100, 2);
    }

    protected function namedBreakdown($baseQuery, string $foreignKey, string $joinTable, bool $hasArabicName): array
    {
        $reportsTable = (new IncidentReport)->getTable();
        $select = [
            "{$reportsTable}.{$foreignKey} as id",
            "{$joinTable}.name",
            $hasArabicName ? "{$joinTable}.name_ar" : DB::raw('NULL as name_ar'),
        ];

        $query = (clone $baseQuery)
            ->leftJoin($joinTable, "{$reportsTable}.{$foreignKey}", '=', "{$joinTable}.id")
            ->whereNotNull("{$reportsTable}.{$foreignKey}")
            ->select($select)
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy("{$reportsTable}.{$foreignKey}", "{$joinTable}.name")
            ->orderByDesc('aggregate_count')
            ->orderBy("{$joinTable}.name");

        if ($hasArabicName) {
            $query->groupBy("{$joinTable}.name_ar");
        }

        return $query->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $row->name,
                'name_ar' => $row->name_ar,
                'count' => (int) $row->aggregate_count,
            ])
            ->all();
    }

    protected function patientGenderBreakdown($baseQuery): array
    {
        $reportsTable = (new IncidentReport)->getTable();

        return (clone $baseQuery)
            ->whereNotNull("{$reportsTable}.patient_gender")
            ->where("{$reportsTable}.patient_gender", '!=', '')
            ->select("{$reportsTable}.patient_gender as gender")
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy("{$reportsTable}.patient_gender")
            ->orderByDesc('aggregate_count')
            ->orderBy("{$reportsTable}.patient_gender")
            ->get()
            ->map(fn ($row) => [
                'id' => $row->gender,
                'gender' => $row->gender,
                'name' => $row->gender,
                'count' => (int) $row->aggregate_count,
            ])
            ->all();
    }

    protected function contributingFactorBreakdown($baseQuery): array
    {
        $reportsTable = (new IncidentReport)->getTable();

        // Wrap baseQuery as filtered subquery; LATERAL join calls jsonb_array_elements_text
        // on each row's contributing_factors. Inline WHERE keeps NULLs and non-array jsonb out.
        $subSql = $baseQuery->toSql();
        $bindings = $baseQuery->getBindings();
        $sql = "({$subSql}) AS filtered CROSS JOIN LATERAL jsonb_array_elements_text(filtered.contributing_factors::jsonb) AS factor";

        $rows = DB::query()
            ->selectRaw('factor')
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->fromRaw($sql, $bindings)
            ->whereNotNull('filtered.contributing_factors')
            ->whereRaw("jsonb_typeof(filtered.contributing_factors::jsonb) = 'array'")
            ->whereNotNull('factor')
            ->groupBy('factor')
            ->orderByDesc('aggregate_count')
            ->get();

        return $rows
            ->map(fn ($row) => [
                'id' => (string) $row->factor,
                'factor' => (string) $row->factor,
                'name' => (string) $row->factor,
                'count' => (int) $row->aggregate_count,
            ])
            ->all();
    }

    protected function monthlyTrendBreakdown($baseQuery): array
    {
        $reportsTable = (new IncidentReport)->getTable();

        return (clone $baseQuery)
            ->selectRaw("DATE_TRUNC('month', {$reportsTable}.created_at) as month")
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupByRaw("DATE_TRUNC('month', {$reportsTable}.created_at)")
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => Carbon::parse($row->month)->format('Y-m'),
                'count' => (int) $row->aggregate_count,
            ])
            ->all();
    }

    /**
     * Recent incidents for dashboard widget
     */
    public function recent(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $this->authorize('viewAny', IncidentReport::class);
        $limit = (int) $request->query('limit', 5);
        $limit = max(1, min(20, $limit));

        $reports = IncidentReport::query()
            ->forOrganization($user->organization_id)
            ->visibleTo($user)
            ->with(['reporter', 'incidentType'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return IncidentReportResource::collection(
            $reports->map(fn (IncidentReport $report) => IncidentReportResource::summary($report))
        );
    }

    /**
     * Export visible incident reports as CSV or PDF (respects the same filters as index).
     */
    public function export(Request $request, IncidentExportService $exporter): StreamedResponse|Response
    {
        $user = $request->user();
        $this->authorize('export', IncidentReport::class);

        $query = IncidentReport::query()
            ->forOrganization($user->organization_id)
            ->visibleTo($user)
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity_level', $severity);
        }
        if ($typeId = $request->query('incident_type_id')) {
            $query->where('incident_type_id', $typeId);
        }

        $stamp = now()->format('Y-m-d');

        if ($request->query('format') === 'pdf') {
            return $exporter->downloadPdf($query, "ovr-incidents-{$stamp}.pdf");
        }

        return $exporter->streamCsv($query, "ovr-incidents-{$stamp}.csv");
    }

    /**
     * Audit log for a report: status-change history plus activity-log entries.
     */
    public function auditLog(Request $request, IncidentReport $report): JsonResponse
    {
        $this->authorize('view', $report);

        $history = $report->statusHistory()->with('changer:id,name')->get()->map(fn ($h) => [
            'type' => 'status_change',
            'from_status' => $h->from_status,
            'to_status' => $h->to_status,
            'reason' => $h->reason,
            'actor' => $h->changer?->name,
            'at' => $h->created_at?->toIso8601String(),
        ]);

        // عزل المؤسسة عبر الفلتر الموحّد، ثم تنسيق كل سجل عبر ActivityLogResource
        // لمنع تسريب old_values/new_values/user_agent/ip_address/metadata الخام.
        // UserActivityLogScope::apply() takes an Eloquent\Builder (not a
        // MorphMany relation) — pull the relation through to its underlying
        // query first so the org-isolation filter can be appended. Re-apply
        // the eager-load + order + limit on the constrained builder.
        $activityBuilder = $report->activityLogs()->getQuery();
        $actor = $request->user();
        if ($actor instanceof User) {
            app(UserActivityLogScope::class)->apply($activityBuilder, $actor);
        }
        $activity = $activityBuilder
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $activityFormatted = $activity->map(function ($a) {
            $payload = (new ActivityLogResource($a))->resolve(request());

            return array_merge($payload, [
                'type' => 'activity',
                'actor' => $a->user?->name,
                'at' => $a->created_at?->toIso8601String(),
            ]);
        });

        $entries = $history->concat($activityFormatted)
            ->sortByDesc('at')
            ->values();

        return response()->json(['data' => $entries]);
    }

    /**
     * Public report tracking by per-report tracking_token — NO authentication.
     * Exposes only non-sensitive status fields. Never patient data or internal notes.
     *
     * Design intent: the endpoint keys on a 64-char random `tracking_token`
     * generated per report at creation time and shipped in the reporter's
     * notification email/SMS, NOT on the enumerable `report_number`
     * (e.g. OVR-2026-0001). Using a token removes the enumeration leak that
     * the old `report_number` path allowed — a reporter who guessed adjacent
     * numbers could otherwise peek at their status. Migration
     * `2026_07_07_000005_add_tracking_token_to_incident_reports` adds the
     * column with backfill; this controller switched from `report_number` to
     * `tracking_token` in Batch 2.x.
     *
     * Intentional exposure (P3-H audit decision, 2026-06-16):
     *  - `severity_level` is intentionally public. It is a non-sensitive
     *    categorization field (low/medium/high/critical) and the public
     *    reporter-tracking UI (PublicTrackReport.tsx) renders it as a
     *    color-coded badge so the reporter can see the severity of their
     *    own report. It is NOT PII, NOT patient data, and NOT an internal
     *    note. The token itself is the access credential; only the reporter
     *    (or anyone who has been given the token) can see the result, and
     *    the route is throttled to 30 req/min.
     */
    public function publicTrack(string $tracking_token): JsonResponse
    {
        $report = IncidentReport::query()
            ->where('tracking_token', $tracking_token)
            ->whereNotIn('status', [ReportStatus::Draft])
            ->with(['incidentType:id,name,name_ar', 'statusHistory'])
            ->first();

        if (! $report) {
            return ApiResponse::error(__('ovr.api.report_not_found'), [], 404);
        }

        return ApiResponse::success([
            'data' => [
                'report_number' => $report->report_number,
                'status' => $report->status->value,
                'status_label' => $report->status->label(),
                'severity_level' => $report->severity_level?->value,
                'incident_type' => $report->incidentType?->name_ar,
                'submitted_at' => $report->created_at?->toIso8601String(),
                'resolved_at' => $report->resolved_at?->toIso8601String(),
                'timeline' => $report->statusHistory->map(fn ($h) => [
                    'to_status' => $h->to_status,
                    'at' => $h->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }
}
