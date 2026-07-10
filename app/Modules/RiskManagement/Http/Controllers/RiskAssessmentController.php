<?php

namespace App\Modules\RiskManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\RiskManagement\Http\Requests\StoreRiskAssessmentRequest;
use App\Modules\RiskManagement\Http\Resources\RiskAssessmentResource;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Services\RiskLifecycleService;
use App\Modules\Shared\Traits\HasOrganizationScope;
use Illuminate\Http\JsonResponse;

class RiskAssessmentController extends Controller
{
    use HasOrganizationScope;

    public function __construct(
        protected RiskLifecycleService $lifecycle
    ) {}

    /**
     * Engine-based gate for assessment operations. reassess uses
     * RISKS_REASSESS, everything else is RISKS_VIEW.
     */
    private function authorizeRisk(string $ability): void
    {
        $user = auth()->user();
        $capability = match ($ability) {
            'reassess' => Capability::RISKS_REASSESS,
            default => Capability::RISKS_VIEW,
        };

        if (! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    public function index(Risk $risk): JsonResponse
    {
        $this->authorizeRisk('view');
        $this->assertSameOrganization($risk);

        return response()->json($risk->assessments()->with('assessor:id,name')->get());
    }

    public function store(StoreRiskAssessmentRequest $request, Risk $risk): JsonResponse
    {
        // Phase CFA-05 — Policy-driven two-path reassess (cluster rescue via
        // RISKS_REASSESS + CLUSTER_TREE_MANAGE). Replacing the prior
        // authorizeRisk('reassess') (flat) + assertSameOrganization pair so
        // the cluster widening takes effect; the policy already enforces
        // same-org via the engine when no cluster grants are held.
        $this->authorize('reassess', $risk);

        $data = $request->validated();

        $assessment = $this->lifecycle->recordAssessment(
            $risk,
            $request->user(),
            (int) $data['likelihood'],
            (int) $data['impact'],
            isset($data['residual_likelihood']) ? (int) $data['residual_likelihood'] : null,
            isset($data['residual_impact']) ? (int) $data['residual_impact'] : null,
            $data['notes'] ?? null,
            $data['next_review_at'] ?? null,
        );

        return response()->json([
            'message' => 'تم تسجيل التقييم بنجاح',
            'data' => new RiskAssessmentResource($assessment->load('assessor:id,name')),
        ], 201);
    }
}
