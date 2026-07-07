<?php

namespace App\Modules\Projects\Http\Resources;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Shared\Support\ElementAbilities;
use App\Modules\Tasks\Http\Resources\TaskResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'progress' => $this->progress,
            'budget' => $this->budget,
            'actual_cost' => $this->actual_cost,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
            'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),

            // حقول النطاق (كانت موجودة في الموديل لكن غائبة عن الـ Resource)
            'objectives' => $this->objectives,
            'in_scope' => $this->in_scope,
            'out_of_scope' => $this->out_of_scope,

            // حقول المنهجية المشتركة
            'type' => $this->type,
            'triage_answers' => $this->triage_answers,

            // حقول المشروع الجديد (PMBOK)
            'business_case' => $this->business_case,
            'success_criteria' => $this->success_criteria,
            'requirements' => $this->requirements,
            'manager_authority' => $this->manager_authority,
            'approval_criteria' => $this->approval_criteria,
            'exit_criteria' => $this->exit_criteria,

            // حقول المشروع التحسيني (FOCUS-PDCA)
            'problem_statement' => $this->problem_statement,
            'target_process' => $this->target_process,
            'root_cause' => $this->root_cause,
            'expected_benefits' => $this->expected_benefits,
            'current_pdca_phase' => $this->current_pdca_phase,

            // حقول الإغلاق
            'lessons_learned' => $this->lessons_learned,
            'outcome_summary' => $this->outcome_summary,
            'sustainability_plan' => $this->sustainability_plan,
            'achievement_percentage' => $this->achievement_percentage,
            'achievement_status' => $this->achievement_status,

            // العلاقات
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ]),
            'department_id' => $this->department_id,
            'program_id' => $this->program_id,
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => (int) $this->program->id,
                'code' => $this->program->code,
                'name' => $this->program->name,
            ] : null),
            // مدير المشروع يُشتق من دور scoped (accessor) — null إن لم يُعيَّن مدير
            // Prefer the eager-loaded scopedRoles collection (list endpoint) to avoid
            // the per-row N+1 query that getManagerAttribute() triggers.
            'manager' => $this->resolveManagerPayload(),
            'manager_id' => $this->resolveManagerId(),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'risks' => $this->whenLoaded('risks', fn () => $this->risks->map(fn ($r) => [
                'id' => $r->id,
                'risk' => $r->risk,
                'probability' => $r->probability,
                'impact' => $r->impact,
                'response' => $r->response,
                'status' => $r->status,
                'risk_level' => $r->risk_level,
                'order' => $r->order,
            ])),
            'stakeholders' => $this->whenLoaded('stakeholders', fn () => $this->stakeholders->map(fn ($s) => [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone,
                'organization' => $s->organization,
                'role' => $s->role,
                'influence' => $s->influence,
                'interest' => $s->interest,
                'notes' => $s->notes,
            ])),
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'milestones' => $this->whenLoaded('milestones', fn () => $this->milestones->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'description' => $m->description,
                'start_date' => $m->start_date?->format('Y-m-d'),
                'due_date' => $m->due_date?->format('Y-m-d'),
                'completed_date' => $m->completed_date?->format('Y-m-d'),
                'status' => $m->status,
                'progress' => $m->progress,
                'order' => $m->order,
                'deliverables' => $m->relationLoaded('deliverables') ? $m->deliverables->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'description' => $d->description,
                    'status' => $d->status,
                    'progress' => $d->progress,
                ])->all() : null,
            ])),
            'kpis' => $this->whenLoaded('kpis', fn () => $this->kpis->map(fn ($k) => [
                'id' => $k->id,
                'name' => $k->name,
                'indicator' => $k->name,
                'baseline' => $k->baseline,
                'target' => $k->target,
                'current_value' => $k->current_value,
                'unit' => $k->unit,
                'measurement_method' => $k->measurement_method,
                'frequency' => $k->frequency,
                'performance_link_id' => $k->pivot?->id,
                'achievement_percentage' => $k->achievement_percentage,
                'performance_status' => $k->performance_status,
            ])),

            // الموارد
            'human_resources' => $this->human_resources,
            'technical_resources' => $this->technical_resources,
            'financial_resources' => $this->financial_resources,

            // الإحصائيات
            'tasks_count' => $this->whenCounted('tasks'),
            'milestones_count' => $this->whenCounted('milestones'),
            'risks_count' => $this->whenCounted('risks'),

            // بيانات الملكية والصلاحيات
            // CAVEAT: organization_id + created_by are exposed unconditionally here.
            // A stricter build would gate these on $request->user()?->can('viewSensitive', $this->resource)
            // — defer until ProjectPolicy gains that ability. The controller already enforces
            // ProjectPolicy::view so cross-tenant reads are still blocked at the auth boundary.
            'created_by' => $this->created_by,
            'organization_id' => $this->organization_id,

            // Per-record abilities — resolved through AccessDecision so the
            // frontend never re-derives scope-chain logic. When toArray() is
            // invoked outside an HTTP context (e.g. ProjectController calls
            // ->resolve() directly in store/update), fall back to the active
            // request user so abilities still compute.
            'abilities' => ElementAbilities::resolve(
                $request?->user() ?? request()->user(),
                $this->resource,
                [
                    'view' => Capability::PROJECTS_VIEW,
                    'edit' => Capability::PROJECTS_EDIT,
                    'delete' => Capability::PROJECTS_DELETE,
                    'assign_roles' => Capability::PROJECTS_ASSIGN_ROLES,
                ]
            ),

            // التواريخ
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Resolve the manager payload. Uses the eager-loaded scopedRoles relation
     * when present (list endpoint), otherwise falls back to the accessor which
     * runs a fresh query — acceptable for single-project endpoints that don't
     * pre-load scopedRoles.
     */
    private function resolveManagerPayload(): ?array
    {
        $user = $this->resolveManagerUser();

        return $user ? ['id' => (int) $user->id, 'name' => $user->name] : null;
    }

    /**
     * Resolve just the manager id (kept for API consumers that depend on the
     * existing manager_id field).
     */
    private function resolveManagerId(): ?int
    {
        $user = $this->resolveManagerUser();

        return $user ? (int) $user->id : null;
    }

    /**
     * Shared lookup: find the first ScopedRole with role=manager on this
     * project and return its user model.
     */
    private function resolveManagerUser()
    {
        if ($this->resource->relationLoaded('scopedRoles')) {
            $managerRole = $this->scopedRoles->firstWhere('role', ScopedRole::PROJECT_MANAGER);

            return $managerRole?->user;
        }

        return $this->manager;
    }
}
