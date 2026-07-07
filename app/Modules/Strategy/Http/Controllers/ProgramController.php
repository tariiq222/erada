<?php

namespace App\Modules\Strategy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Traits\HasOrganizationScope;
use App\Modules\Strategy\Http\Requests\DeleteProgramRequest;
use App\Modules\Strategy\Http\Requests\LinkProjectRequest;
use App\Modules\Strategy\Http\Requests\ListProgramsRequest;
use App\Modules\Strategy\Http\Requests\StoreProgramRequest;
use App\Modules\Strategy\Http\Requests\UpdateProgramRequest;
use App\Modules\Strategy\Http\Requests\ViewProgramRequest;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    use HasOrganizationScope;

    /**
     * التحقق من صلاحية الوصول للاستراتيجية
     *
     * Super-admin bypass is handled automatically by AccessDecision::can()
     * (engine short-circuit), so no manual isSuperAdmin() check is needed
     * here. ProgramPolicy::before() covers the routes that flow through
     * FormRequest authorize(); this helper covers the list/unlink routes
     * that don't have a dedicated FormRequest.
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
     * Display a listing of programs.
     */
    public function index(ListProgramsRequest $request): JsonResponse
    {
        // Authz (STRATEGY_VIEW) owned by ListProgramsRequest.

        $query = Program::query()
            ->with([
                'portfolio:id,code,name',
                'department:id,name',
            ])
            ->withCount([
                'projects',
                'blockers' => fn ($q) => $q->whereIn('status', ['open', 'in_progress']),
            ]);

        $user = auth()->user();
        if (! $user?->isSuperAdmin()) {
            if ($user?->organization_id === null) {
                abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
            }

            $query->where('organization_id', $user->organization_id);
        }

        // Filter by portfolio (الالتزام التنفيذي)
        if ($request->has('portfolio_id') && is_numeric($request->portfolio_id)) {
            $query->where('portfolio_id', (int) $request->portfolio_id);
        }

        // Filter by department
        if ($request->has('department_id') && is_numeric($request->department_id)) {
            $query->where('department_id', (int) $request->department_id);
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $programs = $query->orderBy('order')
            ->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->get('per_page', 15), 100));

        // Add computed fields
        $programs->getCollection()->transform(function ($program) {
            $program->status_label = $program->status_label;
            $program->priority_label = $program->priority_label;
            $program->budget_utilization = $program->budget_utilization;

            return $program;
        });

        return response()->json($programs);
    }

    /**
     * Store a newly created program.
     */
    public function store(StoreProgramRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $portfolio = Portfolio::findOrFail($validated['portfolio_id']);
        $this->assertSameOrganization($portfolio);

        if ($portfolio->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        $validated['created_by'] = auth()->id();
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['priority'] = $validated['priority'] ?? 'medium';
        $validated['weight'] = $validated['weight'] ?? 1;
        $validated['progress_calculation_method'] = $validated['progress_calculation_method'] ?? 'average';

        $program = Program::create($validated);
        $program->forceFill(['organization_id' => $portfolio->organization_id])->save();

        return response()->json([
            'message' => 'تم إنشاء المبادرة بنجاح',
            'program' => $program->load(['portfolio:id,code,name']),
        ], 201);
    }

    /**
     * Display the specified program.
     */
    public function show(ViewProgramRequest $request, Program $program): JsonResponse
    {
        // Authz (STRATEGY_VIEW on program) + org-isolation floor owned by
        // ViewProgramRequest.

        $program->load([
            'portfolio',
            'department:id,name',
            'projects' => fn ($q) => $q->orderBy('created_at', 'desc'),
            'blockers' => fn ($q) => $q->whereIn('status', ['open', 'in_progress', 'escalated']),
            'decisions' => fn ($q) => $q->latest('decision_date')->limit(10),
            'kpis' => fn ($q) => $q->with('owner:id,name'),
            'reviews' => fn ($q) => $q->latest('review_date')->limit(5),
        ]);

        $program->status_label = $program->status_label;
        $program->priority_label = $program->priority_label;
        $program->budget_utilization = $program->budget_utilization;
        $program->progress_method_label = $program->progress_method_label;

        return response()->json($program);
    }

    /**
     * Update the specified program.
     */
    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $this->assertSameOrganization($program);

        $validated = $request->validated();

        $portfolio = Portfolio::findOrFail($validated['portfolio_id']);
        $this->assertSameOrganization($portfolio);

        if ($portfolio->organization_id === null) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
        }

        $program->update($validated);
        $program->forceFill(['organization_id' => $portfolio->organization_id])->save();

        return response()->json([
            'message' => 'تم تحديث المبادرة بنجاح',
            'program' => $program->fresh()->load(['portfolio:id,code,name']),
        ]);
    }

    /**
     * Remove the specified program.
     */
    public function destroy(DeleteProgramRequest $request, Program $program): JsonResponse
    {
        // Authz (STRATEGY_DELETE on program) + org-isolation floor owned by
        // DeleteProgramRequest.

        // Unlink projects instead of preventing deletion
        Project::where('program_id', $program->id)->update(['program_id' => null]);

        $program->delete();

        return response()->json([
            'message' => 'تم حذف المبادرة بنجاح',
        ]);
    }

    /**
     * Get a simple list for dropdowns.
     */
    public function list(Request $request): JsonResponse
    {
        $this->authorizeStrategy('view');

        $query = Program::active()
            ->select('id', 'code', 'name', 'portfolio_id');

        $user = auth()->user();
        if (! $user?->isSuperAdmin()) {
            if ($user?->organization_id === null) {
                abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
            }

            $query->where('organization_id', $user->organization_id);
        }

        if ($request->has('portfolio_id') && is_numeric($request->portfolio_id)) {
            $query->where('portfolio_id', (int) $request->portfolio_id);
        }

        $programs = $query->orderBy('order')->get();

        return response()->json($programs);
    }

    /**
     * Link a project to this program.
     */
    public function linkProject(LinkProjectRequest $request, Program $program): JsonResponse
    {
        // Authz (STRATEGY_EDIT on program) + payload validation owned by
        // LinkProjectRequest. Cross-row checks (target project org,
        // already-linked) stay in the controller.
        $validated = $request->validated();

        $project = Project::findOrFail($validated['project_id']);
        $this->assertSameOrganization($project);

        if ($project->program_id) {
            return response()->json([
                'message' => 'المشروع مرتبط بمبادرة أخرى',
            ], 422);
        }

        $project->update(['program_id' => $program->id]);
        $program->updateProgress();

        return response()->json([
            'message' => 'تم ربط المشروع بالمبادرة بنجاح',
            'project' => $project,
        ]);
    }

    /**
     * Unlink a project from this program.
     */
    public function unlinkProject(Program $program, Project $project): JsonResponse
    {
        $this->authorizeStrategy('update');
        $this->assertSameOrganization($program);
        $this->assertSameOrganization($project);

        if ($project->program_id !== $program->id) {
            return response()->json([
                'message' => 'المشروع غير مرتبط بهذه المبادرة',
            ], 422);
        }

        $project->update(['program_id' => null]);
        $program->updateProgress();

        return response()->json([
            'message' => 'تم فك ربط المشروع بنجاح',
        ]);
    }

    /**
     * Get unlinked projects.
     */
    public function unlinkedProjects(Request $request): JsonResponse
    {
        $this->authorizeStrategy('view');

        $query = Project::whereNull('program_id')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with(['department:id,name']);

        $user = auth()->user();
        if (! $user?->isSuperAdmin()) {
            if ($user?->organization_id === null) {
                abort(403, 'ليس لديك صلاحية الوصول لهذا العنصر');
            }

            $query->where('organization_id', $user->organization_id);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $projects = $query->orderBy('created_at', 'desc')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json($projects);
    }
}
