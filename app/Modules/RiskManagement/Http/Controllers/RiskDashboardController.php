<?php

namespace App\Modules\RiskManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use App\Modules\RiskManagement\Services\RiskExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RiskDashboardController extends Controller
{
    public function __construct(
        protected RiskExportService $exporter
    ) {}

    private function authorizeReports(): void
    {
        $user = auth()->user();
        if (! AccessDecision::can($user, Capability::RISKS_VIEW_REPORTS)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    private function orgFilter(): callable
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            return fn ($q) => $q;
        }
        if ($user?->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        return fn ($q) => $q->where('organization_id', $user->organization_id);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeReports();

        $query = Risk::query();
        $this->orgFilter()($query);

        $byStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byLevel = (clone $query)
            ->selectRaw('current_level, COUNT(*) as count')
            ->groupBy('current_level')
            ->pluck('count', 'current_level');

        $overdueActionsCount = RiskAction::query()
            ->whereHas('risk', fn ($q) => $this->orgFilter()($q))
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

        $query = Risk::query()
            ->whereNotIn('status', [RiskStatus::Closed->value, RiskStatus::Accepted->value]);
        $this->orgFilter()($query);

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

        $query = Risk::query()->orderBy('code');
        $this->orgFilter()($query);
        $query->limit(10000); // حد أعلى معقول

        return $this->exporter->streamCsv($query, 'risks-'.now()->format('Ymd-His').'.csv');
    }

    public function exportPdf(Request $request): Response
    {
        $this->authorizeReports();

        $query = Risk::query()->orderBy('code');
        $this->orgFilter()($query);
        $query->limit(10000); // حد أعلى معقول

        return $this->exporter->downloadPdf($query, 'risks-'.now()->format('Ymd-His').'.pdf');
    }
}
