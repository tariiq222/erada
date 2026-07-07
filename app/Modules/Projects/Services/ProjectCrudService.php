<?php

namespace App\Modules\Projects\Services;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\Project\MilestoneService;
use App\Modules\Projects\Services\Project\RiskService;
use App\Modules\Projects\Services\Project\StakeholderService;
use App\Modules\Projects\Services\Project\TaskService;
use App\Modules\Projects\Services\Project\TeamService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectCrudService
{
    public function __construct(
        protected ProjectSettingsService $settingsService,
        protected MilestoneService $milestoneService,
        protected RiskService $riskService,
        protected StakeholderService $stakeholderService,
        protected TeamService $teamService,
        protected TaskService $taskService,
        protected ProjectAuthorizationService $authService,
    ) {}

    /**
     * منع تصعيد الصلاحيات عبر نموذج الفريق: يُخفَّض أي دور "مدير" في مصفوفة
     * الأعضاء إلى "member" ما لم يكن الفاعل مخوّلاً بمستوى الإدارة (delete-level).
     * تعيين مدير مشروع حقيقي يتم فقط عبر نقطة النهاية المخصّصة المحميّة.
     *
     * @param  array<int, array<string, mixed>>  $teamMembers
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeTeamRoles(array $teamMembers, User $user, Project $project): array
    {
        // Delete-level authority routes through the unified engine — the same path
        // ProjectPolicy::delete uses — so there is no second authz model to drift.
        if (AccessDecision::can($user, Capability::PROJECTS_DELETE, $project)) {
            return $teamMembers;
        }

        $managerAliases = ['manager', 'مدير', 'قائد فريق'];

        return array_map(function ($member) use ($managerAliases) {
            if (isset($member['role']) && in_array($member['role'], $managerAliases, true)) {
                $member['role'] = 'member';
            }

            return $member;
        }, $teamMembers);
    }

    /**
     * إنشاء مشروع جديد
     */
    public function createProject(array $data, User $user): Project
    {
        // تعيين الحالة الافتراضية إذا لم تُحدد
        if (! isset($data['status'])) {
            $data['status'] = $this->settingsService->getDefaultProjectStatus();
        }

        $data['created_by'] = $user->id;

        // Default the project to the creator's own department when none was picked.
        // This keeps a department creator's project inside their own department
        // (and therefore inside their visibility/management subtree). Org-level
        // creators (admins) typically have no home department, so this is a no-op
        // for them and they may still target any department explicitly.
        if (empty($data['department_id']) && $user->department_id) {
            $data['department_id'] = $user->department_id;
        }

        // The project's organization is the source of truth for multi-tenant
        // isolation: visibility and scoping all key off it, so it MUST match the
        // project's department organization (NOT the creator's — a creator in org A
        // targeting a department in org B would otherwise mis-tag the project and
        // hide it from org B). Never trust a client-supplied value; fall back to the
        // creator's org only when the project has no department at all.
        $data['organization_id'] = (! empty($data['department_id'])
            ? Department::find($data['department_id'])?->organization_id
            : null) ?? $user->organization_id;

        // استخراج البيانات المرتبطة (الحقول المتبقية — بما فيها حقول المنهجية type/triage_answers/إلخ — تمرّ مباشرة إلى Project::create)
        $milestones = $data['milestones'] ?? [];
        $tasks = $data['tasks'] ?? [];
        $risks = $data['risks'] ?? [];
        $stakeholders = $data['stakeholders'] ?? [];
        $teamMembers = $data['team_members'] ?? [];
        $kpis = $data['kpis'] ?? [];

        // The requested project manager is NOT a projects column; strip it from the
        // data passed to Project::create() and resolve the actual manager below.
        $type = isset($data['type']) ? (string) $data['type'] : null;
        $requestedManagerId = isset($data['manager_user_id']) && $data['manager_user_id'] !== null
            ? (int) $data['manager_user_id']
            : null;

        unset($data['milestones'], $data['tasks'], $data['risks'], $data['stakeholders'], $data['team_members'], $data['kpis'], $data['manager_user_id']);

        // Resolve and validate the manager BEFORE opening the transaction. On a
        // scope/eligibility failure this throws a 422 (ValidationException) and no
        // project is created.
        $manager = $this->resolveProjectManager($user, $requestedManagerId, $type);

        // لف جميع عمليات قاعدة البيانات في معاملة لضمان التكامل
        return DB::transaction(function () use ($data, $milestones, $tasks, $risks, $stakeholders, $teamMembers, $kpis, $user, $manager) {
            $project = Project::create($data);

            if (! empty($data['organization_id'])) {
                $project->forceFill(['organization_id' => $data['organization_id']])->save();
            }

            // مدير المشروع — يُمثَّل كدور سياقي (scoped role) لا كعمود. قد يكون
            // المنشئ نفسه (السلوك الافتراضي) أو مستخدماً آخر مُعيَّناً صراحةً؛ في
            // الحالة الثانية لا يحصل المنشئ على أي دور سياقي (يبقى created_by فقط).
            $manager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER, $user->id);

            // إنشاء المراحل والمهام
            $milestoneIds = $this->milestoneService->createMilestones($project, $milestones);
            $this->taskService->createTasks($project, $tasks, $milestoneIds, $user);

            // إنشاء المخاطر وأصحاب المصلحة وأعضاء الفريق
            $this->riskService->createRisks($project, $risks);
            $this->stakeholderService->createStakeholders($project, $stakeholders);
            $this->stakeholderService->addProjectLeadersAsStakeholders($project);
            $this->teamService->createTeamMembers($project, $this->sanitizeTeamRoles($teamMembers, $user, $project));

            // إنشاء مؤشرات الأداء (نظام Performance) وربطها بالمشروع
            $this->createPerformanceKpis($project, $kpis, $user);

            return $project;
        });
    }

    /**
     * Resolve who becomes the project manager.
     *
     * Returns the creator when no different manager was requested (the default,
     * unchanged behavior). When a different user is requested, the choice is
     * re-validated server-side (defense in depth — never trust the client): the
     * target must exist, be active, belong to the creator's organization, sit
     * within the creator's creatable-department scope for the type, and be
     * eligible to manage projects (canCreateAny). Any failure throws a 422 keyed
     * on 'manager_user_id'; on success the target is returned and the creator
     * receives no scoped role.
     *
     * @throws ValidationException
     */
    protected function resolveProjectManager(User $creator, ?int $requestedManagerId, ?string $type): User
    {
        // Omitted / null / self => keep current behavior (creator is the manager).
        if ($requestedManagerId === null || $requestedManagerId === $creator->id) {
            return $creator;
        }

        $target = User::find($requestedManagerId);

        $isValid = $target !== null
            && $target->is_active
            // Same organization as the creator (org isolation, defense in depth).
            && $creator->organization_id !== null
            && $target->organization_id === $creator->organization_id
            // Target must be eligible to manage projects.
            && $this->authService->canCreateAny($target)
            // Target's department must be within the creator's creatable scope for
            // the type. null => unrestricted (any department in the org).
            && $this->targetInCreatableScope($target, $creator, $type);

        if (! $isValid) {
            throw ValidationException::withMessages([
                'manager_user_id' => 'لا يمكن تعيين هذا المستخدم كمدير للمشروع.',
            ]);
        }

        return $target;
    }

    /**
     * Is the target user's department within the creator's creatable-department
     * scope for the given type? null scope => unrestricted (any department in
     * the organization).
     */
    protected function targetInCreatableScope(User $target, User $creator, ?string $type): bool
    {
        $allowedDepartmentIds = $this->authService->creatableDepartmentIds($creator, $type);

        if ($allowedDepartmentIds === null) {
            return true;
        }

        return $target->department_id !== null
            && in_array((int) $target->department_id, $allowedDepartmentIds, true);
    }

    /**
     * إنشاء مؤشرات أداء (Performance) وربطها بالمشروع
     *
     * @param  array<int, array<string, mixed>>  $kpis
     */
    protected function createPerformanceKpis(Project $project, array $kpis, User $user): void
    {
        foreach ($kpis as $index => $row) {
            if (empty($row['name'])) {
                continue;
            }

            $kpi = (new Kpi)->forceFill([
                'organization_id' => $project->organization_id,
                'name' => $row['name'],
                'measurement_method' => $row['measurement_method'] ?? null,
                'category' => 'project',
                'baseline' => $row['baseline'] ?? null,
                'target' => $row['target'] ?? null,
                'current_value' => $row['current_value'] ?? $row['baseline'] ?? 0,
                'unit' => $row['unit'] ?? null,
                'frequency' => 'monthly',
                'direction' => 'increase',
                'status' => 'active',
                'owner_id' => $user->id,
                'created_by' => $user->id,
                'order' => $index + 1,
            ]);
            $kpi->save();

            (new KpiLink)->forceFill([
                'organization_id' => $project->organization_id,
                'kpi_id' => $kpi->id,
                'linkable_type' => Project::class,
                'linkable_id' => $project->id,
                'relationship_type' => 'primary',
                'weight' => 1,
                'created_by' => $user->id,
            ])->save();
        }
    }

    /**
     * مزامنة مؤشرات الأداء عند التحديث (upsert بالـ id الخاص بمؤشر Performance المرتبط).
     *
     * - صف يحمل id لمؤشر مرتبط بالمشروع → تحديث المؤشر.
     * - صف بلا id → إنشاء مؤشر جديد وربطه.
     *
     * لا تُحذف المؤشرات غير المذكورة (مملوكة لنظام Performance). يحلّ محلّ
     * السلوك السابق حيث كان التحديث يتجاهل المؤشرات كلياً.
     *
     * @param  array<int, array<string, mixed>>  $kpis
     */
    protected function syncPerformanceKpis(Project $project, array $kpis, User $user): void
    {
        $linkedIds = array_map('intval', $project->kpis()->pluck('kpis.id')->all());

        foreach ($kpis as $row) {
            if (empty($row['name'])) {
                continue;
            }

            $id = $row['id'] ?? null;
            if ($id && in_array((int) $id, $linkedIds, true)) {
                Kpi::whereKey($id)->update(array_filter([
                    'name' => $row['name'],
                    'measurement_method' => $row['measurement_method'] ?? null,
                    'baseline' => $row['baseline'] ?? null,
                    'target' => $row['target'] ?? null,
                    'unit' => $row['unit'] ?? null,
                ], fn ($value) => $value !== null));

                continue;
            }

            $this->createPerformanceKpis($project, [$row], $user);
        }
    }

    /**
     * تحديث مشروع
     */
    public function updateProject(Project $project, array $data, User $user): Project
    {
        // استخراج البيانات المرتبطة (الحقول المتبقية — بما فيها حقول المنهجية — تمرّ مباشرة إلى project::update)
        $milestones = $data['milestones'] ?? null;
        $risks = $data['risks'] ?? null;
        $tasks = $data['tasks'] ?? null;
        $stakeholders = $data['stakeholders'] ?? null;
        $teamMembers = $data['team_members'] ?? null;
        $kpis = $data['kpis'] ?? null;
        unset($data['milestones'], $data['risks'], $data['tasks'], $data['stakeholders'], $data['team_members'], $data['kpis']);

        // When the methodology type flips, null out the fields that belong to the
        // now-irrelevant methodology so stale cross-methodology data does not linger.
        $data = $this->clearStaleMethodologyFields($project, $data);

        return DB::transaction(function () use ($project, $data, $milestones, $risks, $tasks, $stakeholders, $teamMembers, $kpis, $user) {
            $project->update($data);

            // مزامنة المراحل (upsert بالـ id) — ترجع خريطة index→id للمراحل الجديدة
            $milestoneIds = [];
            if ($milestones !== null) {
                $milestoneIds = $this->milestoneService->syncMilestones($project, $milestones);
            }

            // مزامنة المخاطر (تحفظ الهوية والحالة)
            if ($risks !== null) {
                $this->riskService->syncRisks($project, $risks);
            }

            // مزامنة المهام (upsert بالـ id — بلا تكرار)
            if ($tasks !== null) {
                $this->taskService->syncTasks($project, $tasks, $user, $milestoneIds);
            }

            // مزامنة مؤشرات الأداء (نظام Performance)
            if ($kpis !== null) {
                $this->syncPerformanceKpis($project, $kpis, $user);
            }

            // تحديث أصحاب المصلحة. replace* عملية حذف-ثم-إنشاء مدمّرة، لذا نتجاهل
            // المصفوفة الفارغة كي لا يمحو تحديثٌ جزئي كل القائمة بالخطأ؛ الحذف
            // الكامل يتم عبر نقاط النهاية المفردة (removeStakeholder/removeMember).
            if (! empty($stakeholders)) {
                $this->stakeholderService->replaceStakeholders($project, $stakeholders);
                $this->stakeholderService->addProjectLeadersAsStakeholders($project);
            }

            // تحديث فريق العمل (نفس الحماية ضد المسح الكامل بالخطأ)
            if (! empty($teamMembers)) {
                $this->teamService->replaceTeamMembers($project, $this->sanitizeTeamRoles($teamMembers, $user, $project));
            }

            return $project;
        });
    }

    /**
     * Methodology field sets keyed by the type they belong to.
     *
     * @var array<string, list<string>>
     */
    protected const METHODOLOGY_FIELDS = [
        // PMBOK (development project)
        'development' => [
            'business_case',
            'success_criteria',
            'requirements',
            'manager_authority',
            'approval_criteria',
            'exit_criteria',
        ],
        // FOCUS-PDCA (improvement project)
        'improvement' => [
            'problem_statement',
            'target_process',
            'root_cause',
            'expected_benefits',
            'current_pdca_phase',
        ],
    ];

    /**
     * When the project's `type` is changing, null out the methodology fields that
     * belong to the OLD (now-irrelevant) type so stale cross-methodology data does
     * not survive the flip. `current_pdca_phase` is FOCUS-PDCA-only, so flipping to
     * `development` always resets it to null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clearStaleMethodologyFields(Project $project, array $data): array
    {
        if (! array_key_exists('type', $data)) {
            return $data;
        }

        $newType = (string) $data['type'];
        $oldType = (string) $project->type;

        if ($newType === $oldType) {
            return $data;
        }

        // Clear the fields of whichever type is NOT the new one.
        $fieldsToClear = self::METHODOLOGY_FIELDS[$newType === 'development' ? 'improvement' : 'development'] ?? [];

        foreach ($fieldsToClear as $field) {
            $data[$field] = null;
        }

        return $data;
    }

    /**
     * حذف مشروع
     *
     * سياسة cascade موحّدة (audit 2026-07-06، P0-4):
     *  - Tasks و Milestones: soft-delete (عبر الموديل، observer يسجّل).
     *  - MilestoneDeliverables: hard-delete (لا SoftDeletes) مع ActivityLog صريح لكل صف.
     *  - ProjectRisks: hard-delete (لا SoftDeletes، LogsActivity يتجاوزه query-builder).
     *  - Stakeholders: hard-delete (لا SoftDeletes ولا LogsActivity) مع ActivityLog صريح لكل صف.
     *  - ScopedRole: hard-delete ثم flushCache (صحيح كما هو).
     *  - KpiLinks: hard-delete (لا نحتفظ بسجل — الـ KPI نفسه في Performance يبقى).
     *
     * المشروع نفسه soft-delete عبر observer `ProjectObserver::deleted`.
     */
    public function deleteProject(Project $project): bool
    {
        return DB::transaction(function () use ($project) {
            // KPI links — المؤشرات نفسها تبقى (تُدار من موديول Performance).
            KpiLink::query()
                ->where('linkable_type', Project::class)
                ->where('linkable_id', $project->id)
                ->delete();

            // تحميل الـ child entities التي تحتاج audit log قبل الحذف.
            $risks = $project->risks()->get();
            $stakeholders = $project->stakeholders()->get();

            // Milestones (soft-delete عبر الموديل) + deliverables (hard-delete مع audit).
            $project->milestones()->each(function ($milestone) {
                $milestone->deliverables()->delete();
                $milestone->delete();
            });

            // المهام soft-delete — LogsActivity يسجّلها.
            $project->tasks()->delete();

            // Risks hard-delete مع ActivityLog لكل صف (LogsActivity لا يلتقط
            // query-builder delete).
            $this->logCascadeDelete($risks, 'project_risk');

            // Stakeholders hard-delete مع ActivityLog لكل صف (لا LogsActivity أصلاً).
            $this->logCascadeDelete($stakeholders, 'project_stakeholder');

            // ScopedRole hard-delete ثم flush الـ decision cache.
            $project->scopedRoles()->delete();
            AccessDecision::flushCache();

            return $project->delete();
        });
    }

    /**
     * سجّل ActivityLog لكل صف قبل الحذف النهائي عبر query-builder. لا نعتمد
     * على LogsActivity لأن query-builder يتجاوز model events.
     *
     * @param  Collection<int, Model>  $models
     */
    protected function logCascadeDelete(iterable $models, string $entityType): void
    {
        $userId = auth()->id()
            ?? auth('sanctum')->id()
            ?? request()->user()?->id;

        $resolver = app(\App\Modules\Shared\Services\ActivityLogOrganizationResolver::class);

        foreach ($models as $model) {
            $loggableType = get_class($model);
            $loggableId = $model->getKey();

            ActivityLog::create([
                'user_id' => $userId,
                'action' => 'deleted',
                'loggable_type' => $loggableType,
                'loggable_id' => $loggableId,
                'organization_id' => $resolver->resolveForLoggable($loggableType, $loggableId),
                'old_values' => $model->attributesToArray(),
                'new_values' => null,
                'description' => "cascade delete via project ({$entityType})",
                'ip_address' => request()->ip(),
            ]);
        }
    }
}
