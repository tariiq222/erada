<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
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

        return ApiResponse::success([
            'data' => ActivityLogResource::collection($logs->getCollection())->resolve($request),
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
        $this->authorize('view', $activityLog);

        $activityLog->load(['user:id,name']);

        return ApiResponse::success([
            'data' => (new ActivityLogResource($activityLog))->resolve(request()),
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
     * Route AUDIT_VIEW through the unified engine — replaces the legacy
     * `$this->authorize('view_audit_logs')` Spatie fallback that never
     * consulted AccessDecision. There is no Policy method for audit access,
     * so the engine is the canonical gate.
     */
    /**
     * Tenant-scope an ActivityLog query to the actor's organization (H-01).
     * يستخدم UserActivityLogScope (الفلتر الموحّد). super_admin يرى الكل
     * ويشمل السجلات بلا organization_id (events النظامية).
     */
    private function applyOrgScope($query): void
    {
        $actor = request()->user();
        if ($actor === null) {
            return;
        }
        app(UserActivityLogScope::class)->apply($query, $actor);
    }

    private function authorizeAuditView(): void
    {
        $user = request()->user();
        if ($user === null) {
            abort(401);
        }

        if (! AccessDecision::can($user, Capability::AUDIT_VIEW)) {
            abort(403, 'غير مصرح لك بعرض سجل النشاطات');
        }
    }

    /**
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

        return response()->streamDownload(function () use ($logs) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ', 'المستخدم', 'الإجراء', 'الوصف', 'الهدف']);

            foreach ($logs as $log) {
                fputcsv($out, [
                    $log->created_at?->toDateTimeString(),
                    $log->user?->name,
                    $log->action,
                    $log->description,
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

        return response()->streamDownload(function () use ($logs) {
            echo json_encode([
                'success' => true,
                'exported_at' => now()->toIso8601String(),
                'count' => $logs->count(),
                'logs' => ActivityLogResource::collection($logs)->resolve(request()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function buildExportQuery(Request $request)
    {
        $query = ActivityLog::query()
            ->with(['user:id,name'])
            ->orderBy('created_at', 'desc');

        $this->applyOrgScope($query);

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

        return $query;
    }
}
