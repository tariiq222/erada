<?php

namespace App\Modules\Strategy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * PortfolioTreeResource
 *
 * Serializes a Portfolio with its programs (and projects per depth query param)
 * for the dashboard tree endpoint. Projects are NOT loaded as a relation on the
 * Portfolio model — the controller bulk-fetches them with UserProjectScope
 * filtering and injects the collection under each program's `projects` key.
 *
 * ponytail: lightweight DTO only — no accessors or computed fields are
 * re-evaluated; the controller pre-hydrates what the resource expects.
 */
class PortfolioTreeResource extends JsonResource
{
    /**
     * The grouped projects keyed by program_id, injected by the controller.
     *
     * @var Collection<int, Collection>
     */
    protected $projectsByProgram;

    /**
     * Whether the caller asked for `depth=programs` (skip projects payload).
     */
    protected bool $includeProjects = true;

    public function __construct($resource)
    {
        parent::__construct($resource);
        $this->projectsByProgram = collect();
    }

    /**
     * Inject the pre-grouped projects collection (program_id => Collection<Project>).
     */
    public function withProjectsByProgram($projectsByProgram): self
    {
        $this->projectsByProgram = $projectsByProgram;

        return $this;
    }

    /**
     * Toggle the projects payload (depth=programs skips it).
     */
    public function includeProjects(bool $include): self
    {
        $this->includeProjects = $include;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $portfolio = $this->resource;

        $programs = ($portfolio->programs ?? collect())->map(function ($program) {
            $progProjects = $this->includeProjects
                ? $this->projectsByProgram->get($program->id, collect())->values()->all()
                : [];

            return [
                'id' => $program->id,
                'code' => $program->code,
                'name' => $program->name,
                'description' => $program->description,
                'status' => $program->status,
                'status_label' => $program->status_label ?? null,
                'priority' => $program->priority,
                'priority_label' => $program->priority_label ?? null,
                'progress' => (float) ($program->progress ?? 0),
                'weight' => (float) ($program->weight ?? 0),
                'budget' => (float) ($program->budget ?? 0),
                'spent_amount' => (float) ($program->spent_amount ?? 0),
                'budget_utilization' => (float) ($program->budget_utilization ?? 0),
                'start_date' => $program->start_date?->format('Y-m-d'),
                'end_date' => $program->end_date?->format('Y-m-d'),
                'order' => (int) ($program->order ?? 0),
                'department' => $program->relationLoaded('department') && $program->department
                    ? ['id' => $program->department->id, 'name' => $program->department->name]
                    : null,
                'projects' => $progProjects,
                'stats' => [
                    'projects_count' => (int) ($program->projects_count ?? 0),
                    'open_blockers_count' => (int) ($program->open_blockers_count ?? 0),
                    'in_progress_projects_count' => (int) ($program->in_progress_projects_count ?? 0),
                    'completed_projects_count' => (int) ($program->completed_projects_count ?? 0),
                    'overdue_projects_count' => (int) ($program->overdue_projects_count ?? 0),
                ],
            ];
        })->values();

        return [
            'id' => $portfolio->id,
            'code' => $portfolio->code,
            'name' => $portfolio->name,
            'description' => $portfolio->description,
            'rationale' => $portfolio->rationale,
            'directive_source' => $portfolio->directive_source,
            'directive_source_label' => $portfolio->directive_source_label ?? null,
            'status' => $portfolio->status,
            'status_label' => $portfolio->status_label ?? null,
            'portfolio_status' => $portfolio->portfolio_status,
            'portfolio_status_label' => $portfolio->portfolio_status_label ?? null,
            'portfolio_progress' => (float) ($portfolio->portfolio_progress ?? 0),
            'progress' => (float) ($portfolio->progress ?? 0),
            'weight' => (float) ($portfolio->weight ?? 0),
            'priority_rank' => (int) ($portfolio->priority_rank ?? 0),
            'start_date' => $portfolio->start_date?->format('Y-m-d'),
            'end_date' => $portfolio->end_date?->format('Y-m-d'),
            'programs_count' => $programs->count(),
            'programs' => $programs,
        ];
    }
}
