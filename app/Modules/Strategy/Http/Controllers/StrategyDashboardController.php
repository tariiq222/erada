<?php

namespace App\Modules\Strategy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use App\Modules\Strategy\Http\Requests\ViewPortfolioTreeRequest;
use App\Modules\Strategy\Http\Resources\PortfolioTreeResource;
use App\Modules\Strategy\Models\Blocker;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StrategyDashboardController extends Controller
{
    /**
     * التحقق من صلاحية الوصول للاستراتيجية
     */
    protected function authorizeStrategy(string $ability = 'view'): void
    {
        $user = auth()->user();

        if ($user?->isSuperAdmin()) {
            return;
        }

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
     * Get dashboard summary statistics.
     * PMI Standard: Portfolio -> Program -> Project
     */
    public function summary(): JsonResponse
    {
        $this->authorizeStrategy('view');

        $user = auth()->user();
        $applyOrgFilter = function ($query) use ($user) {
            if (! $user->isSuperAdmin()) {
                if ($user->organization_id === null) {
                    abort(403, 'غير مصرح لك بالوصول لهذا العنصر');
                }

                $query->where('organization_id', $user->organization_id);
            }

            return $query;
        };

        $portfolios = $applyOrgFilter(Portfolio::active())->get();
        $programs = $applyOrgFilter(Program::active())->get();

        $totalProjects = $applyOrgFilter(Project::query())->count();
        $unlinkedProjects = $applyOrgFilter(Project::whereNull('program_id'))
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        $openBlockers = $applyOrgFilter(Blocker::open())->count();
        // Direction B: pending ruling-style recommendations stand in for the
        // legacy `Decision::pending()` counter that lived on the dropped
        // `decisions` table.
        $pendingDecisions = $applyOrgFilter(Recommendation::pendingRulings())->count();

        // Calculate average progress
        $portfolioIds = $portfolios->pluck('id');
        $avgPortfolioProgress = $portfolioIds->isEmpty()
            ? 0
            : (float) $applyOrgFilter(
                DB::table('portfolios')->whereIn('id', $portfolioIds)
            )->avg('portfolio_progress');
        $avgProgramProgress = $programs->isEmpty()
            ? 0
            : (float) $applyOrgFilter(
                DB::table('programs')->whereIn('id', $programs->pluck('id'))
            )->avg('progress');

        return response()->json([
            'portfolios' => [
                'total' => $applyOrgFilter(Portfolio::query())->count(),
                'active' => $portfolios->count(),
                'avg_progress' => round($avgPortfolioProgress, 2),
            ],
            'programs' => [
                'total' => $applyOrgFilter(Program::query())->count(),
                'active' => $programs->count(),
                'avg_progress' => round($avgProgramProgress, 2),
            ],
            'projects' => [
                'total' => $totalProjects,
                'unlinked' => $unlinkedProjects,
            ],
            'blockers' => [
                'open' => $openBlockers,
                'critical' => $applyOrgFilter(Blocker::open()->critical())->count(),
                'overdue' => $applyOrgFilter(Blocker::overdue())->count(),
            ],
            'decisions' => [
                'pending' => $pendingDecisions,
            ],
        ]);
    }

    /**
     * Get the golden chain for a specific entity.
     * PMI Standard: Portfolio -> Program -> Project
     */
    public function goldenChain(string $type, int $id): JsonResponse
    {
        $this->authorizeStrategy('view');

        $user = auth()->user();
        $applyOrgFilter = function ($query) use ($user) {
            if (! $user->isSuperAdmin()) {
                if ($user->organization_id === null) {
                    abort(403, 'غير مصرح لك بالوصول لهذا العنصر');
                }

                $query->where('organization_id', $user->organization_id);
            }

            return $query;
        };

        $chain = [
            'portfolio' => null,
            'program' => null,
            'project' => null,
        ];

        switch ($type) {
            case 'portfolio':
                $portfolio = $applyOrgFilter(Portfolio::query())->find($id);
                if ($portfolio) {
                    $chain['portfolio'] = [
                        'id' => $portfolio->id,
                        'code' => $portfolio->code,
                        'name' => $portfolio->name,
                        'status' => $portfolio->status,
                    ];
                }
                break;

            case 'program':
                $program = $applyOrgFilter(Program::with('portfolio'))->find($id);
                if ($program) {
                    $chain['portfolio'] = $program->portfolio ? [
                        'id' => $program->portfolio->id,
                        'code' => $program->portfolio->code,
                        'name' => $program->portfolio->name,
                        'status' => $program->portfolio->status,
                    ] : null;
                    $chain['program'] = [
                        'id' => $program->id,
                        'code' => $program->code,
                        'name' => $program->name,
                        'status' => $program->status,
                    ];
                }
                break;

            case 'project':
                $project = $applyOrgFilter(Project::with('program.portfolio'))->find($id);
                if ($project) {
                    $chain['portfolio'] = $project->program?->portfolio ? [
                        'id' => $project->program->portfolio->id,
                        'code' => $project->program->portfolio->code,
                        'name' => $project->program->portfolio->name,
                        'status' => $project->program->portfolio->status,
                    ] : null;
                    $chain['program'] = $project->program ? [
                        'id' => $project->program->id,
                        'code' => $project->program->code,
                        'name' => $project->program->name,
                        'status' => $project->program->status,
                    ] : null;
                    $chain['project'] = [
                        'id' => $project->id,
                        'code' => $project->code,
                        'name' => $project->name,
                        'status' => $project->status,
                    ];
                }
                break;

                // Backward compatibility aliases
            case 'direction':
                return $this->goldenChain('portfolio', $id);

            case 'initiative':
                return $this->goldenChain('program', $id);
        }

        return response()->json($chain);
    }

    /**
     * Get the full Portfolio -> Programs -> Projects tree for a single portfolio.
     *
     * Phase 7.2 — single round-trip endpoint replacing the N+1 cascade callers
     * used to build by hand. Implementation notes (per Agent 5 research brief):
     *   - Feature-flagged behind config('strategy.tree_endpoint_enabled').
     *   - Authorization owned by ViewPortfolioTreeRequest (engine-only STRATEGY_VIEW).
     *   - Programs are eager-loaded with counter aggregates (no per-row queries).
     *   - Projects are bulk-fetched with whereIn('program_id', ...) and passed
     *     through UserProjectScope::apply() so visibility matches the Projects
     *     list endpoint for the same user.
     *   - Query budget target: ~6 queries regardless of portfolio size.
     */
    public function tree(ViewPortfolioTreeRequest $request, int $portfolio): JsonResponse
    {
        if (! config('strategy.tree_endpoint_enabled', false)) {
            abort(404);
        }

        $user = $request->user();
        $includeStatus = $request->input('include_status', 'active');
        $depth = $request->input('depth', 'full');
        $hideEmpty = $request->boolean('hide_empty_programs');

        $portfolioModel = Portfolio::query()
            ->with([
                'programs' => function ($q) {
                    $q->orderBy('order')
                        ->with(['department:id,name'])
                        ->withCount([
                            'projects',
                            'blockers as open_blockers_count' => fn ($q) => $q->whereIn(
                                'status',
                                [Blocker::STATUS_OPEN, Blocker::STATUS_IN_PROGRESS, Blocker::STATUS_ESCALATED]
                            ),
                            'projects as in_progress_projects_count' => fn ($q) => $q->where(
                                'status',
                                'in_progress'
                            ),
                            'projects as completed_projects_count' => fn ($q) => $q->where(
                                'status',
                                'completed'
                            ),
                            'projects as overdue_projects_count' => fn ($q) => $q->whereNotIn(
                                'status',
                                ['completed', 'cancelled']
                            )->where('end_date', '<', now()),
                        ]);
                },
            ])
            ->findOrFail($portfolio);

        // Bulk-fetch projects across all programs (Pattern A) so we avoid the
        // N+1 from a nested projects() eager-load per program.
        $programIds = $portfolioModel->programs->pluck('id');
        $projectsByProgram = collect();
        $allProjects = collect();

        if ($depth === 'full' && $programIds->isNotEmpty()) {
            $projectsQuery = Project::query()
                ->with(['department:id,name'])
                ->whereIn('program_id', $programIds)
                ->whereNull('deleted_at');

            // Visibility filter — UserProjectScope::apply() handles super_admin
            // bypass, org isolation, and direct/dept/project/engine grants.
            $projectsQuery = app(UserProjectScope::class)->apply($projectsQuery, $user);

            $allProjects = $projectsQuery
                ->orderBy('program_id')
                ->orderBy('name')
                ->get([
                    'id',
                    'code',
                    'name',
                    'status',
                    'priority',
                    'progress',
                    'budget',
                    'start_date',
                    'end_date',
                    'program_id',
                    'department_id',
                ]);

            $projectsByProgram = $allProjects->groupBy('program_id');
        }

        // Optionally filter programs by include_status.
        if ($includeStatus === 'active') {
            $portfolioModel->setRelation(
                'programs',
                $portfolioModel->programs->whereIn('status', [
                    Program::STATUS_PLANNING,
                    Program::STATUS_IN_PROGRESS,
                ])->values()
            );
        }

        // Compute stats across the (post-filter) program set + visible projects.
        $programs = $portfolioModel->programs;
        $stats = [
            'programs_total' => $programs->count(),
            'projects_total' => $allProjects->count(),
            'projects_in_progress' => $allProjects->where('status', 'in_progress')->count(),
            'projects_completed' => $allProjects->where('status', 'completed')->count(),
            'projects_overdue' => $allProjects->filter(function ($p) {
                $ended = $p->end_date ?? null;

                return $ended !== null
                    && $p->status !== 'completed'
                    && $p->status !== 'cancelled'
                    && $ended->lt(now());
            })->count(),
            'open_blockers_total' => (int) $programs->sum('open_blockers_count'),
        ];

        $resource = (new PortfolioTreeResource($portfolioModel))
            ->withProjectsByProgram($projectsByProgram)
            ->includeProjects($depth === 'full');

        $payload = $resource->toArray($request);

        // hide_empty_programs removes programs that have zero visible projects.
        if ($hideEmpty && $depth === 'full') {
            $payload['programs'] = array_values(array_filter(
                $payload['programs'],
                fn ($p) => ! empty($p['projects'])
            ));
            $payload['programs_count'] = count($payload['programs']);
            $stats['programs_total'] = $payload['programs_count'];
        }

        return response()->json([
            'data' => [
                'portfolio' => $payload,
                'programs' => $payload['programs'],
                'stats' => $stats,
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    'cached' => false,
                    'cache_key' => "portfolio_tree_{$portfolioModel->id}_v1",
                    'depth' => $depth,
                    'include_status' => $includeStatus,
                    'hide_empty_programs' => $hideEmpty,
                ],
            ],
        ]);
    }
}
