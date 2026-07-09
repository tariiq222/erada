<?php

namespace App\Modules\Strategy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Strategy\Http\Requests\DeletePortfolioRequest;
use App\Modules\Strategy\Http\Requests\ListPortfoliosDropdownRequest;
use App\Modules\Strategy\Http\Requests\ListPortfoliosRequest;
use App\Modules\Strategy\Http\Requests\PortfolioSummaryRequest;
use App\Modules\Strategy\Http\Requests\StorePortfolioRequest;
use App\Modules\Strategy\Http\Requests\UpdatePortfolioPriorityRequest;
use App\Modules\Strategy\Http\Requests\UpdatePortfolioRequest;
use App\Modules\Strategy\Http\Requests\UpdatePortfolioStrategicStatusRequest;
use App\Modules\Strategy\Http\Requests\ViewPortfolioRequest;
use App\Modules\Strategy\Http\Resources\PortfolioResource;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Scopes\UserStrategyScope;
use App\Modules\Strategy\Services\PortfolioDecisionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PortfolioController — Strategic commitment ("الالتزام التنفيذي") controller.
 *
 * Note: the API uses the term "Portfolio" internally but the Frontend surfaces
 * "الالتزام التنفيذي" (strategic commitment) to end users.
 */
class PortfolioController extends Controller
{
    use HasOrganizationScope;

    /**
     * Handle unexpected exceptions and return a sanitized JSON envelope.
     */
    private function handleException(\Throwable $e, string $context): JsonResponse
    {
        if ($e instanceof AuthorizationException
            || $e instanceof AuthenticationException
            || $e instanceof ValidationException
            || $e instanceof ModelNotFoundException
            || $e instanceof HttpException
            || $e instanceof NotFoundHttpException
            || $e instanceof MethodNotAllowedHttpException) {
            throw $e;
        }

        $errorId = uniqid('port_err_', true);
        Log::error("PortfolioController error: {$context}", [
            'error_id' => $errorId,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
            'error_id' => $errorId,
        ], 500);
    }

    public function __construct(
        protected PortfolioDecisionService $decisionService
    ) {}

    /**
     * Verify the actor's strategy authorization.
     *
     * Super-admin bypass is handled automatically by AccessDecision::can()
     * (engine short-circuit), so no manual isSuperAdmin() check is needed
     * here. PortfolioPolicy::before() covers the routes that flow through
     * FormRequest authorize(); this helper is kept for parity with
     * ProgramController and for any future non-FormRequest route.
     */
    protected function authorizeStrategy(string $ability = 'view'): void
    {
        $user = auth()->user();

        $capability = match ($ability) {
            'create' => Capability::STRATEGY_CREATE,
            'update' => Capability::STRATEGY_EDIT,
            'delete' => Capability::STRATEGY_DELETE,
            default => Capability::STRATEGY_VIEW,
        };

        if (! $user || ! AccessDecision::can($user, $capability)) {
            abort(403, 'ليس لديك صلاحية الوصول');
        }
    }

    /**
     * Display a listing of portfolios.
     */
    public function index(ListPortfoliosRequest $request): JsonResponse
    {
        try {
            // Authz (STRATEGY_VIEW) owned by ListPortfoliosRequest.
            // Phase 9-D-D1b: cluster_tree read widening via UserStrategyScope —
            // super_admin bypass, null-org fail-closed, descendant widening when
            // STRATEGY_VIEW + CLUSTER_TREE_VIEW are both granted on actor.org.

            $query = Portfolio::query()
                ->with(['creator:id,name'])
                ->withCount('programs');

            $user = auth()->user();
            if (! $user) {
                abort(401);
            }

            app(UserStrategyScope::class)->applyToPortfolios($query, $user);

            // Filter by operational status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Filter by portfolio strategic status
            if ($request->has('portfolio_status') && $request->portfolio_status) {
                $query->where('portfolio_status', $request->portfolio_status);
            }

            // Search
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $portfolios = $query->ordered()
                ->paginate(min((int) $request->get('per_page', 15), 100));

            // Add computed fields
            $portfolios->getCollection()->transform(function ($portfolio) {
                $portfolio->progress = $portfolio->calculateProgress();
                $portfolio->status_label = $portfolio->status_label;
                $portfolio->portfolio_status_label = $portfolio->portfolio_status_label;
                $portfolio->directive_source_label = $portfolio->directive_source_label;

                return $portfolio;
            });

            return response()->json($portfolios);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'index');
        }
    }

    /**
     * Store a newly created portfolio.
     */
    public function store(StorePortfolioRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $validated = $request->validated();

            if (! $user?->isSuperAdmin() && $user?->organization_id === null) {
                abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
            }

            $portfolioOrgId = $user?->isSuperAdmin()
                ? ($validated['organization_id'] ?? $user?->organization_id)
                : $user?->organization_id;

            // Org-isolation floor: a strategy record with a null organization_id
            // becomes invisible to every org-scoped query, so we refuse to save
            // it instead of creating an orphan. Validation-style 422 (the user
            // is authorized, the data is incomplete).
            if ($portfolioOrgId === null) {
                return response()->json([
                    'message' => 'لا يمكن إنشاء الالتزام التنفيذي بدون مؤسسة مرتبطة واضحة',
                    'errors' => ['organization_id' => ['يجب تحديد المؤسسة أو تسجيل الدخول بحساب مرتبط بمؤسسة']],
                ], 422);
            }

            unset($validated['organization_id']);

            $validated['created_by'] = auth()->id();
            $validated['status'] = $validated['status'] ?? 'draft';
            $validated['portfolio_status'] = $validated['portfolio_status'] ?? 'active';

            $portfolio = Portfolio::create($validated);
            $portfolio->forceFill(['organization_id' => $portfolioOrgId])->save();

            return response()->json([
                'message' => 'تم إنشاء الالتزام التنفيذي بنجاح',
                'data' => new PortfolioResource($portfolio->load(['creator:id,name'])),
            ], 201);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'store');
        }
    }

    /**
     * Display the specified portfolio.
     */
    public function show(ViewPortfolioRequest $request, Portfolio $portfolio): JsonResponse
    {
        try {
            // Authz (STRATEGY_VIEW on portfolio) + org-isolation floor owned by
            // ViewPortfolioRequest.

            $portfolio->load([
                'creator:id,name',
                'programs' => fn ($q) => $q->orderBy('order'),
            ]);

            $portfolio->progress = $portfolio->calculateProgress();
            $portfolio->status_label = $portfolio->status_label;
            $portfolio->portfolio_status_label = $portfolio->portfolio_status_label;
            $portfolio->directive_source_label = $portfolio->directive_source_label;

            return response()->json(new PortfolioResource($portfolio));
        } catch (\Throwable $e) {
            return $this->handleException($e, 'show');
        }
    }

    /**
     * Update the specified portfolio.
     */
    public function update(UpdatePortfolioRequest $request, Portfolio $portfolio): JsonResponse
    {
        try {
            $this->assertSameOrganization($portfolio);

            $validated = $request->validated();

            // Strip priority/weight silently when the user lacks the privilege,
            // matching the original controller behavior. The FormRequest does
            // not enforce a 403 here because the update path silently dropped.
            if (! $this->canManagePortfolioPriority($portfolio)) {
                unset($validated['priority_rank'], $validated['weight']);
            }

            // Strategic close guard
            if (($validated['portfolio_status'] ?? null) === Portfolio::PORTFOLIO_STATUS_CLOSED) {
                if (! $portfolio->canBeClosedStrategically()) {
                    if (! $this->canForceClosePortfolio($portfolio)) {
                        return response()->json([
                            'message' => 'لا يمكن إغلاق المحفظة استراتيجياً وهناك برامج نشطة',
                            'errors' => ['portfolio_status' => ['يوجد برامج نشطة مرتبطة بهذه المحفظة']],
                        ], 422);
                    }
                    // Log the force-close decision
                    $this->decisionService->logForceCloseDecision(
                        $portfolio,
                        auth()->user(),
                        $request->input('decision_note')
                    );
                }
            }

            $portfolio->update($validated);

            return response()->json([
                'message' => 'تم تحديث الالتزام التنفيذي بنجاح',
                'data' => new PortfolioResource($portfolio->fresh()->load(['creator:id,name'])),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'update');
        }
    }

    /**
     * Remove the specified portfolio.
     */
    public function destroy(DeletePortfolioRequest $request, Portfolio $portfolio): JsonResponse
    {
        try {
            // Authz (STRATEGY_DELETE on portfolio) + org-isolation floor owned by
            // DeletePortfolioRequest.

            // Check if has programs
            if ($portfolio->programs()->exists()) {
                return response()->json([
                    'message' => 'لا يمكن حذف التزام تنفيذي يحتوي على مبادرات',
                ], 422);
            }

            $portfolio->delete();

            return response()->json([
                'message' => 'تم حذف الالتزام التنفيذي بنجاح',
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'destroy');
        }
    }

    /**
     * Get a simple list for dropdowns.
     * Return strategically-active portfolios (regardless of operational status).
     */
    public function list(ListPortfoliosDropdownRequest $request): JsonResponse
    {
        try {
            // Authz (STRATEGY_VIEW) owned by ListPortfoliosDropdownRequest.
            // Phase 9-D-D1b: cluster_tree read widening via UserStrategyScope.

            $query = Portfolio::strategicallyActive()
                ->whereIn('status', ['draft', 'active'])
                ->select('id', 'code', 'name')
                ->orderBy('priority_rank', 'desc')
                ->orderBy('order');

            $user = auth()->user();
            if (! $user) {
                abort(401);
            }

            app(UserStrategyScope::class)->applyToPortfolios($query, $user);

            $portfolios = $query->get();

            return response()->json($portfolios);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'list');
        }
    }

    /**
     * Update portfolio priority and weight (PMO/Admin only).
     */
    public function updatePriority(UpdatePortfolioPriorityRequest $request, Portfolio $portfolio): JsonResponse
    {
        try {
            $this->assertSameOrganization($portfolio);

            $validated = $request->validated();

            $portfolio->update($validated);

            return response()->json([
                'message' => 'تم تحديث أولوية المحفظة بنجاح',
                'data' => new PortfolioResource($portfolio->fresh()),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'updatePriority');
        }
    }

    /**
     * Update portfolio strategic status.
     */
    public function updateStrategicStatus(UpdatePortfolioStrategicStatusRequest $request, Portfolio $portfolio): JsonResponse
    {
        try {
            $this->assertSameOrganization($portfolio);

            $validated = $request->validated();

            $oldStatus = $portfolio->portfolio_status;
            $isForceClose = false;

            // Strategic close guard
            if ($validated['portfolio_status'] === Portfolio::PORTFOLIO_STATUS_CLOSED) {
                if (! $portfolio->canBeClosedStrategically()) {
                    if (! $this->canForceClosePortfolio($portfolio)) {
                        return response()->json([
                            'message' => 'لا يمكن إغلاق المحفظة استراتيجياً وهناك برامج نشطة',
                        ], 422);
                    }
                    $isForceClose = true;
                }
            }

            $portfolio->update(['portfolio_status' => $validated['portfolio_status']]);

            // Log the decision
            if ($isForceClose) {
                $this->decisionService->logForceCloseDecision(
                    $portfolio,
                    auth()->user(),
                    $validated['decision_note'] ?? null
                );
            } elseif ($oldStatus !== $validated['portfolio_status']) {
                $this->decisionService->logStrategicStatusChange(
                    $portfolio,
                    auth()->user(),
                    $oldStatus,
                    $validated['portfolio_status'],
                    $validated['decision_note'] ?? null
                );
            }

            return response()->json([
                'message' => 'تم تحديث حالة المحفظة الاستراتيجية بنجاح',
                'data' => new PortfolioResource($portfolio->fresh()),
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'updateStrategicStatus');
        }
    }

    /**
     * Get portfolio statistics/summary.
     */
    public function summary(PortfolioSummaryRequest $request): JsonResponse
    {
        try {
            // Authz (STRATEGY_VIEW) owned by PortfolioSummaryRequest.
            // Phase 9-D-D1b: cluster_tree read widening via UserStrategyScope.

            $user = auth()->user();
            if (! $user) {
                abort(401);
            }

            $scopePortfolioQuery = function ($query) use ($user) {
                app(UserStrategyScope::class)->applyToPortfolios($query, $user);

                return $query;
            };

            $newPortfolioQuery = fn () => $scopePortfolioQuery(Portfolio::query());
            $newActivePortfolioQuery = fn () => $scopePortfolioQuery(Portfolio::active());

            $stats = [
                'total' => $newPortfolioQuery()->count(),
                'active' => $newActivePortfolioQuery()->count(),
                'by_portfolio_status' => [
                    'active' => $newPortfolioQuery()->where('portfolio_status', 'active')->count(),
                    'rebalancing' => $newPortfolioQuery()->where('portfolio_status', 'rebalancing')->count(),
                    'frozen' => $newPortfolioQuery()->where('portfolio_status', 'frozen')->count(),
                    'closed' => $newPortfolioQuery()->where('portfolio_status', 'closed_strategically')->count(),
                ],
                'average_progress' => round($newActivePortfolioQuery()->avg('portfolio_progress') ?? 0, 2),
                'total_weight' => $newActivePortfolioQuery()->sum('weight'),
            ];

            return response()->json($stats);
        } catch (\Throwable $e) {
            return $this->handleException($e, 'summary');
        }
    }

    /**
     * Whether the current user may set portfolio priority/weight, resolved
     * through the unified engine (strategy.manage_priority). On create there is
     * no target yet, so the organization-level capability is checked.
     */
    private function canManagePortfolioPriority(?Portfolio $portfolio = null): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_MANAGE_PRIORITY, $portfolio);
    }

    /**
     * Whether the current user may force-close a portfolio with active
     * programs, resolved through the unified engine (strategy.change_status).
     */
    private function canForceClosePortfolio(?Portfolio $portfolio = null): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessDecision::can($user, Capability::STRATEGY_CHANGE_STATUS, $portfolio);
    }
}
