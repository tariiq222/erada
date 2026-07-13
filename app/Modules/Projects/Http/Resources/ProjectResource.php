<?php

namespace App\Modules\Projects\Http\Resources;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Shared\Support\ElementAbilities;
use App\Modules\Tasks\Http\Resources\TaskResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Phase CFA-04 — Sanitized show for cluster actors.
     *
     * When the actor is reading a project cross-org via CLUSTER_TREE_VIEW
     * rescue (actor.org != project.organization_id AND actor holds
     * CLUSTER_TREE_VIEW), the heavy child surfaces are STRIPPED:
     *   - members (UserResource collection — exposes user PII cross-org)
     *   - stakeholders (email + phone + notes — PII)
     *   - tasks (cross-module task data — widened separately via CFA-08)
     *   - milestones (cross-org project scheduling)
     *   - risks (cross-org risk register — widened separately via CFA-05)
     *   - kpis (cross-org KPI baselines — widened separately via CFA-02)
     *   - human_resources / technical_resources / financial_resources (PII)
     *   - lessons_learned / outcome_summary / sustainability_plan (org-confidential)
     *   - business_case / manager_authority / approval_criteria (org-confidential)
     *   - department (limited to id + name only — no email / phone)
     *
     * Preserved for cluster actors:
     *   - id, code, name, type, status, priority, progress
     *   - start_date, end_date, actual_start_date, actual_end_date
     *   - description (high-level — no PII)
     *   - department_id, program_id (FK pointers — no leakage)
     *   - organization_id, created_by (cross-org read context — needed for the FE)
     *   - manager (id + name only — preserved; cluster rescue grants visibility)
     *   - abilities (per-record — needed for UI; engine-resolved)
     *
     * The sanitization is enforced at the Resource layer; the controller
     * does NOT pre-filter relations, so a cluster actor's `Project::find()`
     * still loads everything, but the response shape is sanitized. This is
     * the documented CFA-04 contract: cluster read = project-level
     * metadata only; child surfaces are accessed via the dedicated
     * cross-org controllers (each with its own cluster widening story:
     * CFA-05 risks, CFA-06 meetings, CFA-07 users, CFA-08 tasks, etc.).
     */
    public function toArray(Request $request): array
    {
        $user = $request?->user();
        $isClusterRead = $user !== null
            && ! $user->isSuperAdmin()
            && $this->resource->exists
            && $this->resource->organization_id !== null
            && (int) $user->organization_id !== (int) $this->resource->organization_id
            && AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW);

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
            'business_case' => $isClusterRead ? null : $this->business_case,
            'success_criteria' => $isClusterRead ? null : $this->success_criteria,
            'requirements' => $isClusterRead ? null : $this->requirements,
            'manager_authority' => $isClusterRead ? null : $this->manager_authority,
            'approval_criteria' => $isClusterRead ? null : $this->approval_criteria,
            'exit_criteria' => $isClusterRead ? null : $this->exit_criteria,

            // حقول المشروع التحسيني (FOCUS-PDCA)
            'problem_statement' => $isClusterRead ? null : $this->problem_statement,
            'target_process' => $isClusterRead ? null : $this->target_process,
            'root_cause' => $isClusterRead ? null : $this->root_cause,
            'expected_benefits' => $isClusterRead ? null : $this->expected_benefits,
            'current_pdca_phase' => $isClusterRead ? null : $this->current_pdca_phase,

            // حقول الإغلاق
            'lessons_learned' => $isClusterRead ? null : $this->lessons_learned,
            'outcome_summary' => $isClusterRead ? null : $this->outcome_summary,
            'sustainability_plan' => $isClusterRead ? null : $this->sustainability_plan,
            'achievement_percentage' => $isClusterRead ? null : $this->achievement_percentage,
            'achievement_status' => $isClusterRead ? null : $this->achievement_status,

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
            // مدير المشروع يُشتق من التفويض المعياري (accessor) — null إن لم يُعيَّن مدير
            // Prefer the eager-loaded roleAssignments collection (list endpoint) to avoid
            // the per-row N+1 query that getManagerAttribute() triggers.
            'manager' => $isClusterRead ? null : $this->resolveManagerPayload(),
            'manager_id' => $isClusterRead ? null : $this->resolveManagerId(),
            'members' => $isClusterRead ? null : UserResource::collection($this->whenLoaded('members')),
            'risks' => $isClusterRead ? null : $this->whenLoaded('risks', fn () => $this->risks->map(fn ($r) => [
                'id' => $r->id,
                'risk' => $r->risk,
                'probability' => $r->probability,
                'impact' => $r->impact,
                'response' => $r->response,
                'status' => $r->status,
                'risk_level' => $r->risk_level,
                'order' => $r->order,
            ])),
            'stakeholders' => $isClusterRead ? null : $this->whenLoaded('stakeholders', fn () => $this->stakeholders->map(fn ($s) => [
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
            'tasks' => $isClusterRead ? null : TaskResource::collection($this->whenLoaded('tasks')),
            'milestones' => $isClusterRead ? null : $this->whenLoaded('milestones', fn () => $this->milestones->map(fn ($m) => [
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
            'kpis' => $isClusterRead ? null : $this->whenLoaded('kpis', fn () => $this->kpis->map(fn ($k) => [
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
            'human_resources' => $isClusterRead ? null : $this->human_resources,
            'technical_resources' => $isClusterRead ? null : $this->technical_resources,
            'financial_resources' => $isClusterRead ? null : $this->financial_resources,

            // الإحصائيات
            'tasks_count' => $isClusterRead ? null : $this->whenCounted('tasks'),
            'milestones_count' => $isClusterRead ? null : $this->whenCounted('milestones'),
            'risks_count' => $isClusterRead ? null : $this->whenCounted('risks'),

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
     * Resolve the manager payload. Uses the eager-loaded roleAssignments relation
     * when present (list endpoint), otherwise falls back to the accessor which
     * runs a fresh query — acceptable for single-project endpoints that don't
     * pre-load roleAssignments.
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
     * Shared lookup: find the first canonical project-manager assignment on this
     * project and return its user model.
     */
    private function resolveManagerUser()
    {
        if ($this->resource->relationLoaded('roleAssignments')) {
            $managerRole = $this->roleAssignments->first(
                fn ($assignment) => $assignment->role?->name === 'project_manager'
            );

            return $managerRole?->user;
        }

        return $this->manager;
    }
}
