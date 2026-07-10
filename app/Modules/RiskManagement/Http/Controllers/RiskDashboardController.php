<?php

namespace App\Modules\RiskManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Services\RiskExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RiskDashboardController extends Controller
{
    public function __construct(
        protected RiskExportService $exporter
    ) {}

    /**
     * Phase CFA-05 — Reports gate widens via cluster_tree.
     *
     * Decision paths:
     *  1) RISKS_VIEW_REPORTS on actor (engine same-org): the prior CFA-00
     *     behavior — grants access to actor.org's risk reports/dashboards.
     *  2) RISKS_VIEW_REPORTS + CLUSTER_TREE_EXPORT on actor (engine rescue
     *     branch): grants access to risk reports/dashboards across
     *     descendant organizations. The two-path is matched on the dashboard
     *     and export endpoints here; the underlying queries use
     *     clusterExportOrgIds() to widen the org filter.
     */
    private function authorizeReports(): void
    {
        $user = auth()->user();

        // Path 1: same-org via RISKS_VIEW_REPORTS.
        if (AccessDecision::can($user, Capability::RISKS_VIEW_REPORTS)) {
            return;
        }

        // Path 2: cross-org via RISKS_VIEW_REPORTS + CLUSTER_TREE_EXPORT rescue.
        // We do not need the third-path engine rescue here because the
        // org-filter widening below (clusterExportOrgIds) is the primary
        // cross-org mechanism for the dashboard / export endpoints. The
        // CLUSTER_TREE_EXPORT gate below pairs the two grants; if either is
        // missing the actor stays same-org only.
        $hasReports = AccessDecision::can($user, Capability::RISKS_VIEW_REPORTS);
        $hasClusterTreeExport = AccessDecision::can($user, Capability::CLUSTER_TREE_EXPORT);

        if (! $hasReports || ! $hasClusterTreeExport) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    /**
     * Phase CFA-05 — Org floor for the dashboard/export endpoints.
     *
     * Returns the list of organization ids the actor may query for the
     * dashboard/aggregate/export surfaces under CFA-05's cluster_export
     * widening.
     *
     *   - Default: [actor.organization_id] only (strict same-org) when
     *     EITHER RISKS_VIEW_REPORTS or CLUSTER_TREE_EXPORT is missing on
     *     actor.org. Preserves the pre-CFA-05 same-org behavior for users
     *     who do not hold both grants.
     *
     *   - Widening: when the actor holds BOTH RISKS_VIEW_REPORTS +
     *     CLUSTER_TREE_EXPORT on actor.organization_id, descendant
     *     organizations (via parent_id BFS) are added to the list.
     *
     * super_admin is short-circuited to a no-op filter; null-org actors
     * fail closed at the empty list. The dashboard / export endpoints
     * use this helper to widen the org filter, mirroring the CFA-04
     * UserProjectScope::clusterVisibleOrgIds pattern.
     *
     * @return array<int, callable(Builder): void>
     */
    private function clusterExportOrgIds(User $user, ?int $overrideOrgId = null): array
    {
        if ($user->isSuperAdmin()) {
            return [fn ($q) => $q];
        }

        $orgId = $overrideOrgId ?? $user->organization_id;

        if ($orgId === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        $visible = [(int) $orgId];

        $hasReports = AccessDecision::can($user, Capability::RISKS_VIEW_REPORTS);
        $hasClusterTreeExport = AccessDecision::can($user, Capability::CLUSTER_TREE_EXPORT);

        if ($hasReports && $hasClusterTreeExport) {
            $org = Organization::query()->find($orgId);
            if ($org instanceof Organization) {
                $visible = array_values(array_unique(array_merge($visible, $org->descendantIds())));
            }
        }

        return [fn ($q) => $q->whereIn('organization_id', $visible)];
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeReports();

        $user = $request->user();
        [$filter] = $this->clusterExportOrgIds($user);

        $query = Risk::query();
        $filter($query);

        $byStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byLevel = (clone $query)
            ->selectRaw('current_level, COUNT(*) as count')
            ->groupBy('current_level')
            ->pluck('count', 'current_level');

        $overdueActionsCount = RiskAction::query()
            ->whereHas('risk', fn ($q) => $filter($q))
            ->whereNotIn('status', [
                RiskActionStatus::Completed->value,
                RiskActionStatus::Cancelled->value,
            ])
            ->where('due_date', '<', now()->toDateString())
            ->count();

        $highRisks = (clone $query)
            ->whereIn('current_level', ['high', 'critical'])
            ->whereNotIn('status', [RiskStatus::Closed->value, RiskStatus::Accepted->value])
            ->orderByDesc('current_score')
            ->limit(10)
            ->with(['department:id,name', 'owner:id,name'])
            ->get();

        return response()->json([
            'totals' => [
                'all' => (clone $query)->count(),
                'open' => (clone $query)->whereNotIn('status', [
                    RiskStatus::Closed->value,
                    RiskStatus::Accepted->value,
                ])->count(),
                'overdue_actions' => $overdueActionsCount,
            ],
            'by_status' => $byStatus,
            'by_level' => $byLevel,
            'top_risks' => $highRisks,
        ]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $this->authorizeReports();

        $user = $request->user();
        [$filter] = $this->clusterExportOrgIds($user);

        $query = Risk::query()
            ->whereNotIn('status', [RiskStatus::Closed->value, RiskStatus::Accepted->value]);
        $filter($query);

        $rows = $query
            ->selectRaw('current_likelihood, current_impact, COUNT(*) as count')
            ->groupBy('current_likelihood', 'current_impact')
            ->get();

        $matrix = [];
        for ($l = 1; $l <= 5; $l++) {
            for ($i = 1; $i <= 5; $i++) {
                $matrix["{$l}_{$i}"] = [
                    'likelihood' => $l,
                    'impact' => $i,
                    'count' => 0,
                ];
            }
        }
        foreach ($rows as $r) {
            $key = "{$r->current_likelihood}_{$r->current_impact}";
            $matrix[$key]['count'] = (int) $r->count;
        }

        return response()->json([
            'matrix' => array_values($matrix),
            'legend' => [
                'low' => 'منخفض',
                'medium' => 'متوسط',
                'high' => 'عالٍ',
                'critical' => 'حرج',
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeReports();

        $user = $request->user();
        [$filter] = $this->clusterExportOrgIds($user);

        $query = Risk::query()->orderBy('code');
        $filter($query);
        $query->limit(10000); // حد أعلى معقول

        return $this->exporter->streamCsv($query, 'risks-'.now()->format('Ymd-His').'.csv');
    }

    public function exportPdf(Request $request): Response
    {
        $this->authorizeReports();

        $user = $request->user();
        [$filter] = $this->clusterExportOrgIds($user);

        $query = Risk::query()->orderBy('code');
        $filter($query);
        $query->limit(10000); // حد أعلى معقول

        return $this->exporter->downloadPdf($query, 'risks-'.now()->format('Ymd-His').'.pdf');
    }
}
