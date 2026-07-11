<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Http\Resources\ActivityLogResource;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Scopes\UserActivityLogScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAuditView();

        $query = ActivityLog::query()
            ->with(['user:id,name'])
            ->orderBy('created_at', 'desc');

        $this->applyOrgScope($query);

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($loggableType = $request->query('loggable_type')) {
            $query->where('loggable_type', $loggableType);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where('description', 'ilike', "%{$search}%");
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $logs = $query->paginate($perPage);

        $actor = $request->user();

        return ApiResponse::success([
            // Phase 1B — dual serializer: per-row cross-org widening
            // emits the minimal envelope; same-org / super_admin keeps
            // the full redacted shape. The same Membership rules
            // (UserActivityLogScope) already filtered which rows the
            // actor can see; the resource just decides what surface to
            // project for each one.
            'data' => $logs->getCollection()->map(fn (ActivityLog $log) => $this->resourceForActor($log, $actor)->resolve($request))->all(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function show(ActivityLog $activityLog): JsonResponse
    {
        $user = request()->user();
        // H-01: do not leak the existence of activity-log rows in another
        // organization. A super_admin can see any row; a regular user only
        // sees rows that pass the UserActivityLogScope (same org, or
        // cluster descendant for cluster_auditor — see Phase CFA-11). If
        // the row falls outside that scope we 404 before authorize() —
        // otherwise the policy's "different org" branch returns 403, which
        // leaks existence.
        if ($user !== null && ! $user->isSuperAdmin()) {
            $visible = app(UserActivityLogScope::class)
                ->applyForRead(ActivityLog::query()->whereKey($activityLog->id), $user)
                ->exists();
            if (! $visible) {
                abort(404);
            }
        }

        $this->authorize('view', $activityLog);

        $activityLog->load(['user:id,name']);

        return ApiResponse::success([
            // Phase 1B — same dual shape selection on show. The
            // controller decides once per request whether the row is
            // in the actor's org (full redacted detail) or a descendant
            // org reached via the cluster rescue branch (minimal
            // envelope).
            'data' => $this->resourceForActor($activityLog, $user)->resolve(request()),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAuditExport();

        $format = $request->query('format', 'csv');
        $logs = $this->buildExportQuery($request)->limit(10000)->get();

        if ($format === 'json') {
            return $this->exportJson($logs);
        }

        return $this->exportCsv($logs);
    }

    /**
     * Tenant-scope an ActivityLog query to the actor's organization (H-01).
     * يستخدم UserActivityLogScope (الفلتر الموحّد). super_admin يرى الكل
     * ويشمل السجلات بلا organization_id (events النظامية).
     *
     * Phase CFA-11 — cluster_auditor widening:
     *   UserActivityLogScope widens to descendant organization ids when the
     *   actor holds BOTH `Capability::AUDIT_VIEW` AND `Capability::CLUSTER_TREE_VIEW`
     *   on actor.organization_id. The widening is read-only — same strict
     *   contract as the engine's rescue branch (org-strict for everyone
     *   else; super_admin sees all).
     */
    private function applyOrgScope($query): void
    {
        $actor = request()->user();
        if ($actor === null) {
            return;
        }
        app(UserActivityLogScope::class)->applyForRead($query, $actor);
    }

    /**
     * Phase CFA-11 — admit the cluster_auditor read pair (AUDIT_VIEW +
     * CLUSTER_TREE_VIEW) on top of the legacy AUDIT_VIEW same-org path.
     *
     * Route AUDIT_VIEW through the unified engine — replaces the legacy
     * `$this->authorize('view_audit_logs')` Spatie fallback that never
     * consulted AccessDecision. There is no Policy method for audit access,
     * so the engine is the canonical gate.
     */
    private function authorizeAuditView(): void
    {
        $user = request()->user();
        if ($user === null) {
            abort(401);
        }

        if (! AccessDecision::can($user, Capability::AUDIT_VIEW)) {
            abort(403, 'غير مصرح لك بعرض سجل النشاطات');
        }

        // Cluster widening is OPT-IN: the same-org AUDIT_VIEW path above
        // admits any user who holds the audit capability on actor.org.
        // The cluster widening is honored by UserActivityLogScope (which
        // calls Organization::descendantIds()) — the controller gate
        // above does NOT need to change. The policy-level rescue in
        // ActivityLogPolicy::view() handles per-row cross-org access.
    }

    /**
     * Phase CFA-11 — admit the cluster_auditor export pair (AUDIT_EXPORT +
     * CLUSTER_TREE_EXPORT) on top of the legacy AUDIT_EXPORT same-org path.
     *
     * Route AUDIT_EXPORT through the unified engine. Separate capability so
     * a future "view but no export" role tier is policy-driven, not a string
     * convention.
     */
    private function authorizeAuditExport(): void
    {
        $user = request()->user();
        if ($user === null) {
            abort(401);
        }

        if (! AccessDecision::can($user, Capability::AUDIT_EXPORT)) {
            abort(403, 'غير مصرح لك بتصدير سجل النشاطات');
        }
    }

    private function exportCsv($logs): StreamedResponse
    {
        $filename = 'activity-log-'.now()->format('Ymd-His').'.csv';
        $actor = request()->user();
        $request = request();

        return response()->streamDownload(function () use ($logs, $actor, $request) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ', 'المستخدم', 'الإجراء', 'الوصف', 'الهدف']);

            foreach ($logs as $log) {
                // Phase 1C — CSV must use the same serializer as JSON
                // export (and as interactive reads). Resolving through
                // ActivityLogResource applies the dual-shape selection:
                // a cross-org cluster row returns the minimal envelope,
                // so its description is null and the CSV cell is empty.
                $row = $this->resourceForActor($log, $actor)->resolve($request);

                fputcsv($out, [
                    $log->created_at?->toDateTimeString(),
                    $row['user']['name'] ?? null,
                    $row['action'],
                    $row['description'] ?? '',
                    $log->loggable_type ? class_basename($log->loggable_type).'#'.$log->loggable_id : '',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportJson($logs): StreamedResponse
    {
        $filename = 'activity-log-'.now()->format('Ymd-His').'.json';
        $actor = request()->user();
        $request = request();

        return response()->streamDownload(function () use ($logs, $actor, $request) {
            echo json_encode([
                'success' => true,
                'exported_at' => now()->toIso8601String(),
                'count' => $logs->count(),
                // Phase 1B — dual shape parity with the show / index
                // endpoints. The JSON export picks the same per-row
                // resource variant.
                'logs' => $logs->map(fn (ActivityLog $log) => $this->resourceForActor($log, $actor)->resolve($request))->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    /**
     * Phase 1B — pick the right resource variant for a row.
     *
     * Same-org / super_admin → full redacted audit detail.
     * Cross-org cluster row → minimal envelope (no description / reason
     * / old_values / new_values / metadata). The resource itself
     * enforces the column suppression.
     */
    private function resourceForActor(ActivityLog $log, ?User $actor): ActivityLogResource
    {
        $isCrossOrg = $actor !== null
            && ! $actor->isSuperAdmin()
            && $log->organization_id !== null
            && $actor->organization_id !== null
            && (int) $log->organization_id !== (int) $actor->organization_id;

        return $isCrossOrg
            ? ActivityLogResource::forClusterCrossOrg($log)
            : new ActivityLogResource($log);
    }

    private function buildExportQuery(Request $request)
    {
        $query = ActivityLog::query()
            ->with(['user:id,name'])
            ->orderBy('created_at', 'desc');

        $actor = request()->user();
        if ($actor !== null) {
            app(UserActivityLogScope::class)->applyForExport($query, $actor);
        }

        foreach (['user_id', 'action', 'loggable_type'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }
        if ($search = $request->query('search')) {
            $query->where('description', 'ilike', "%{$search}%");
        }

        return $query;
    }
}
