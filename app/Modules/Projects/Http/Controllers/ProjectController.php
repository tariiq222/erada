<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Services\AuthorizationAssignmentService;
use App\Modules\Core\Http\Resources\UserResource;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Http\Requests\DeleteProjectRequest;
use App\Modules\Projects\Http\Requests\RemoveProjectMemberRequest;
use App\Modules\Projects\Http\Requests\StoreProjectMemberRequest;
use App\Modules\Projects\Http\Requests\StoreProjectRequest;
use App\Modules\Projects\Http\Requests\StoreProjectRiskRequest;
use App\Modules\Projects\Http\Requests\StoreProjectStakeholderRequest;
use App\Modules\Projects\Http\Requests\UpdateGoverningDepartmentsRequest;
use App\Modules\Projects\Http\Requests\UpdatePdcaPhaseRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectMemberRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRiskRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectSettingsRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectStakeholderRequest;
use App\Modules\Projects\Http\Resources\ProjectResource;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectSetting;
use App\Modules\Projects\Services\ProjectActivityService;
use App\Modules\Projects\Services\ProjectAuthorizationService;
use App\Modules\Projects\Services\ProjectCrudService;
use App\Modules\Projects\Services\ProjectPhaseService;
use App\Modules\Projects\Services\ProjectQueryService;
use App\Modules\Projects\Services\ProjectSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectSettingsService $settingsService,
        protected ProjectQueryService $queryService,
        protected ProjectCrudService $crudService,
        protected ProjectActivityService $activityService,
        protected ProjectPhaseService $phaseService,
        protected AuthorizationAssignmentService $assignmentService,
    ) {}

    /**
     * عرض قائمة المشاريع
     */
    public function index(Request $request): JsonResponse
    {
        $projects = $this->queryService->getPaginatedList($request, $request->user());

        return response()->json($projects);
    }

    /**
     * إنشاء مشروع جديد
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $project = $this->crudService->createProject($validated, $request->user());

            $project->load([
                'department', 'creator',
                'milestones.deliverables', 'tasks.assignee', 'risks', 'stakeholders', 'members',
                'roleAssignments.user', // ProjectResource::resolveManagerUser(): assignments must be eager-loaded (N+1 fallback avoid)
            ]);

            return response()->json([
                'message' => 'تم إنشاء المشروع بنجاح',
                'project' => (new ProjectResource($project))->resolve(),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $errorId = uniqid('project_create_err_');
            \Log::error('Project creation error', [
                'error_id' => $errorId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'فشل في إنشاء المشروع',
                'error_id' => $errorId,
            ], 500);
        }
    }

    /**
     * Departments the current user may target when creating a project of a given
     * type. Powers the create-form department picker so a department creator only
     * sees departments they are actually allowed to create in (the backend still
     * enforces the same scope on store). Same response shape as the HR hierarchy
     * endpoint so the form can consume it interchangeably.
     */
    public function creatableDepartments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && $user->organization_id === null) {
            return response()->json(['message' => 'المستخدم لا ينتمي لمؤسسة'], 403);
        }

        $type = $request->query('type');
        $allowedIds = app(ProjectAuthorizationService::class)
            ->creatableDepartmentIds($user, is_string($type) ? $type : null);

        $query = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'parent_id', 'level')
            ->orderBy('level')
            ->orderBy('name');

        // null => unrestricted; an explicit (possibly empty) list restricts the set.
        if ($allowedIds !== null) {
            $query->whereIn('id', $allowedIds === [] ? [-1] : $allowedIds);
        }

        $departments = $query->get()->map(fn ($dept) => [
            'id' => $dept->id,
            'name' => $dept->name,
            'code' => $dept->code,
            'parent_id' => $dept->parent_id,
            'level' => $dept->level,
            'level_name' => $dept->getLevelNameAttribute(),
        ]);

        return response()->json([
            'all' => $departments,
            'departments' => $departments->filter(fn ($d) => $d['level'] <= 3)->values(),
            'sections' => $departments->filter(fn ($d) => $d['level'] == 4)->values(),
            'units' => $departments->filter(fn ($d) => $d['level'] >= 5)->values(),
        ]);
    }

    /**
     * Users the current caller may assign as project manager for a given type.
     *
     * Lists active users who are BOTH (1) in the department scope the caller may
     * create for (ProjectAuthorizationService::creatableDepartmentIds — null means
     * any department in the caller's organization, the governing-department case)
     * AND (2) eligible to manage projects (canCreateAny === true, mirroring the
     * flat create_projects gate the SPA uses). Org isolation is always enforced.
     *
     * If the caller cannot create the given type at all, an empty list is returned
     * (no information leak) rather than a 403. Response shape mirrors
     * UserController::list so the create form can consume it interchangeably.
     */
    public function assignableManagers(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:development,improvement',
        ]);

        $user = $request->user();
        $type = (string) $request->query('type');

        $authService = app(ProjectAuthorizationService::class);

        // If the caller cannot create this type at all, leak nothing.
        if (! $authService->canCreate($user, $type)) {
            return response()->json(['data' => []]);
        }

        $allowedDepartmentIds = $authService->creatableDepartmentIds($user, $type);

        $query = User::query()
            ->select('id', 'name', 'email', 'job_title', 'department_id')
            ->where('is_active', true);

        // Org isolation: non-super_admin callers only ever see their own org.
        if (! $user->isSuperAdmin()) {
            if ($user->organization_id === null) {
                return response()->json(['data' => []]);
            }
            $query->where('organization_id', $user->organization_id);
        }

        // null => unrestricted (any department in the org); an explicit (possibly
        // empty) list restricts the candidate set to those departments.
        if ($allowedDepartmentIds !== null) {
            $query->whereIn('department_id', $allowedDepartmentIds === [] ? [-1] : $allowedDepartmentIds);
        }

        $candidates = $query->orderBy('name')->get();

        // Keep only users eligible to manage projects. The candidate list is
        // department-scoped and small, so an in-process filter after the DB query
        // is acceptable (mirrors the flat create_projects gate in AuthController).
        $managers = $candidates
            ->filter(fn (User $candidate) => $authService->canCreateAny($candidate))
            ->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                'email' => $candidate->email,
                'job_title' => $candidate->job_title,
                'department_id' => $candidate->department_id,
            ])
            ->values();

        return response()->json(['data' => $managers]);
    }

    /**
     * عرض مشروع محدد
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $project = $this->queryService->getProjectWithRelations($id, $request->user());
        $this->authorize('view', $project);

        return response()->json((new ProjectResource($project))->resolve());
    }

    /**
     * تحديث مشروع
     */
    public function update(UpdateProjectRequest $request, string $id): JsonResponse
    {
        try {
            $project = $request->getProject() ?? Project::findOrFail($id);
            $validated = $request->validated();
            $project = $this->crudService->updateProject($project, $validated, $request->user());

            $project->load([
                'department', 'creator',
                'tasks.assignee', 'risks', 'stakeholders', 'members',
                'roleAssignments.user', // ProjectResource::resolveManagerUser(): assignments must be eager-loaded (N+1 fallback avoid)
            ]);

            return response()->json([
                'message' => 'تم تحديث المشروع بنجاح',
                'project' => (new ProjectResource($project))->resolve(),
            ]);
        } catch (\Exception $e) {
            $errorId = uniqid('project_update_err_');
            \Log::error('Project update error', [
                'error_id' => $errorId,
                'project_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'فشل في تحديث المشروع',
                'error_id' => $errorId,
            ], 500);
        }
    }

    /**
     * تحديث مرحلة PDCA لمشروع تحسيني (انتقال تسلسلي، مدير المشروع فقط)
     */
    public function updatePdcaPhase(UpdatePdcaPhaseRequest $request, string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $project = $this->phaseService->advance($project, $request->validated(), $request->user()->id);

        return response()->json([
            'message' => 'PDCA phase updated successfully',
            'project' => $project,
        ]);
    }

    /**
     * حذف مشروع
     */
    public function destroy(DeleteProjectRequest $request, string $id): JsonResponse
    {
        // Authz against the project's `delete` ability already enforced by the
        // FormRequest. Resolve the fully-related project for the cascade delete.
        $project = $this->queryService->getProjectWithRelations($id, $request->user());
        $deleted = $this->crudService->deleteProject($project);

        return response()->json([
            'message' => 'Project deleted successfully',
            'deleted' => $deleted,
        ]);
    }

    /**
     * إحصائيات المشروع
     */
    public function stats(Request $request, string $id): JsonResponse
    {
        $project = $this->queryService->getProjectStats($id, $request->user());
        $this->authorize('view', $project);

        return response()->json([
            'total_tasks' => $project->tasks_count,
            'completed_tasks' => $project->completed_tasks_count,
            'overdue_tasks' => $project->overdue_tasks_count,
            'members_count' => $project->members_count,
            'milestones_count' => $project->milestones_count,
            'progress' => $project->progress,
            'budget' => $project->budget,
            'actual_cost' => $project->actual_cost,
        ]);
    }

    /**
     * Project status values allowed by the projects module. Single source of
     * truth for both the validation rule on the create/update request classes
     * and the settings write endpoint. Mirrored from
     * StoreProjectRequest::projectRules() / UpdateProjectRequest::projectRules()
     * — if that set changes, update this constant too.
     */
    private const PROJECT_STATUS_VALUES = 'draft,planning,in_progress,on_hold,completed,cancelled';

    /**
     * Safe attachment file-type whitelist. Union of the comment-attachment
     * whitelist (pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt — see AGENTS.md and
     * StoreCommentRequest) and the project's existing default list (gif —
     * see ProjectSettingsService::$defaultProjectSettings).
     */
    private const ALLOWED_ATTACHMENT_TYPES = 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt,gif';

    /**
     * Build the nested settings payload exposed to the front-end. Shared
     * between getSettings() and updateSettings() so both endpoints return
     * exactly the same shape.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSettingsPayload(): array
    {
        return [
            'project' => [
                'default_status' => $this->settingsService->getDefaultProjectStatus(),
            ],
            'attachments' => [
                'max_size_mb' => $this->settingsService->getMaxAttachmentSizeMB(),
                'allowed_types' => $this->settingsService->getAllowedFileTypes(),
            ],
        ];
    }

    /**
     * Map a validated nested payload (the shape accepted by updateSettings())
     * to the flat storage keys that ProjectSettingsService::updateProjectSettings()
     * expects. Only groups/fields that were actually present in the request
     * contribute to the result.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapValidatedToFlatStorage(array $validated): array
    {
        $flat = [];

        if (isset($validated['project']) && is_array($validated['project'])) {
            $project = $validated['project'];
            if (array_key_exists('default_status', $project)) {
                $flat['default_project_status'] = $project['default_status'];
            }
        }

        if (isset($validated['attachments']) && is_array($validated['attachments'])) {
            $attachments = $validated['attachments'];
            if (array_key_exists('max_size_mb', $attachments)) {
                $flat['max_attachments_size'] = (int) $attachments['max_size_mb'];
            }
            if (array_key_exists('allowed_types', $attachments) && is_array($attachments['allowed_types'])) {
                // Storage is a CSV string (the getter explodes it on read).
                $flat['allowed_file_types'] = implode(',', $attachments['allowed_types']);
            }
        }

        return $flat;
    }

    /**
     * الحصول على إعدادات المشاريع للواجهة
     */
    public function getSettings(): JsonResponse
    {
        return response()->json($this->buildSettingsPayload());
    }

    /**
     * Saveable counterpart of getSettings(). Authorizes against edit_settings
     * (or super_admin), validates the partial nested payload, merges it into
     * the existing stored settings (so unspecified fields keep their value),
     * and keeps the service-level cache in sync.
     */
    public function updateSettings(UpdateProjectSettingsRequest $request): JsonResponse
    {
        // Authz against SETTINGS_EDIT (engine) + payload validation are now
        // owned by UpdateProjectSettingsRequest. The controller merges the
        // validated nested shape into the flat storage keys and persists.
        $validated = $request->validated();

        $flat = $this->mapValidatedToFlatStorage($validated);
        $this->settingsService->updateProjectSettings($flat);

        return response()->json($this->buildSettingsPayload());
    }

    /**
     * سجل نشاطات المشروع
     */
    public function activityLog(Request $request, string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

        $logs = $this->activityService->getActivityLog($project, $request);

        return response()->json($logs);
    }

    /**
     * جلب أعضاء المشروع
     */
    public function getMembers(Request $request, string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

        return response()->json(UserResource::collection($project->members()->get()));
    }

    /**
     * إضافة عضو للمشروع
     */
    public function addMember(StoreProjectMemberRequest $request, string $id): JsonResponse
    {
        $project = Project::findOrFail($id);

        // عزل المؤسسة (D-06): مشروع يتيم (organization_id = null) يُرفض لغير super
        if (! $request->user()->isSuperAdmin()) {
            if ($project->organization_id === null) {
                return response()->json(['message' => 'المشروع لا يملك مؤسسة مرتبطة'], 403);
            }
        }

        $this->authorize('update', $project);

        $validated = $request->validated();

        $member = User::find($validated['user_id']);
        if (! $member) {
            return response()->json([
                'message' => 'المستخدم غير موجود',
            ], 404);
        }

        // عزل المؤسسة (D-06): العضو يجب أن ينتمي لنفس مؤسسة المشروع
        if (! $request->user()->isSuperAdmin()
            && $project->organization_id !== null
            && $member->organization_id !== $project->organization_id) {
            return response()->json(['message' => 'العضو والمشروع يجب أن ينتميا لنفس المؤسسة'], 403);
        }

        $role = AuthorizationRole::query()->findOrFail($validated['role_id']);
        $scope = new AssignmentScope('project', (int) $project->id);

        if ($this->projectAssignments($member, $project)->isNotEmpty()) {
            return response()->json(['message' => 'This user already has a project assignment'], 409);
        }

        try {
            $assignment = $this->assignmentService->assign(
                $request->user(),
                $member,
                $role,
                new AssignmentWrite(
                    $scope,
                    isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at']) : null,
                ),
            );
        } catch (AuthorizationAssignmentDenied $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $this->activityService->logMemberAdded($project, $member, $request->user(), $role->name, $request->ip());

        return response()->json([
            'message' => 'تم إضافة العضو بنجاح',
            'data' => $this->canonicalAssignmentPayload($assignment, $role, $project),
        ], 201);
    }

    /**
     * حذف عضو من المشروع
     */
    public function removeMember(RemoveProjectMemberRequest $request, string $id, string $userId): JsonResponse
    {
        $project = Project::findOrFail($id);

        // عزل المؤسسة (D-06): مشروع يتيم (organization_id = null) يُرفض لغير super
        if (! $request->user()->isSuperAdmin()) {
            if ($project->organization_id === null) {
                return response()->json(['message' => 'المشروع لا يملك مؤسسة مرتبطة'], 403);
            }
        }

        $this->authorize('update', $project);

        $member = User::find($userId);
        if (! $member) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $memberName = $member->name;

        // عزل المؤسسة (D-06): إعادة فحص الـ org-floor حتى لو العضو مضاف مسبقاً
        if (! $request->user()->isSuperAdmin()
            && $project->organization_id !== null
            && $member?->organization_id !== $project->organization_id) {
            return response()->json(['message' => 'العضو والمشروع يجب أن ينتميا لنفس المؤسسة'], 403);
        }

        $role = AuthorizationRole::query()->findOrFail($request->validated('role_id'));
        $assignment = $this->exactManualProjectAssignment($member, $project, $role);
        if (! $assignment) {
            return response()->json(['message' => 'Manual project assignment not found'], 409);
        }

        try {
            $this->assignmentService->revoke(
                $request->user(),
                $member,
                $role,
                new AssignmentScope('project', (int) $project->id),
            );
        } catch (AuthorizationAssignmentDenied $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $this->activityService->logMemberRemoved($project, $memberName, $userId, $request->user(), $request->ip());

        return response()->json([
            'message' => 'تم حذف العضو بنجاح',
        ]);
    }

    /**
     * Update a project member's role (manager, member, viewer).
     *
     * Single source of truth for the "manage team" capability:
     * ProjectPolicy::assignProjectRoles. Mirrors Core\AuthorizationRoleAssignmentController
     * (`/projects/{id}/roles/{user}` alias) so the two route families are
     * gated identically — no split-brain between `update` and `assignProjectRoles`.
     *
     * The two writes (revoke + assign) are wrapped in DB::transaction so a
     * mid-step crash does not leave the user with no scoped role on the
     * project (silent permission loss). The engine decision cache is flushed
     * for the affected user after the transaction commits per LR-104.
     */
    public function updateMemberRole(UpdateProjectMemberRequest $request, string $id, string $userId): JsonResponse
    {
        $project = Project::findOrFail($id);

        if (! $request->user()->isSuperAdmin()) {
            if ($project->organization_id === null) {
                return response()->json(['message' => 'Project has no associated organization'], 403);
            }
        }

        // Unify with the /roles/* route family (AuthorizationRoleAssignmentController uses the
        // same ProjectPolicy method). Super_admin still bypasses via before().
        $this->authorize('assignProjectRoles', $project);

        $validated = $request->validated();

        $member = User::find($userId);
        if (! $member) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (! $request->user()->isSuperAdmin()
            && $project->organization_id !== null
            && $member->organization_id !== $project->organization_id) {
            return response()->json(['message' => 'Member and project must belong to same organization'], 403);
        }

        $current = $this->manualProjectAssignment($member, $project);
        if (! $current) {
            return response()->json(['message' => 'Manual project assignment not found'], 404);
        }

        $role = AuthorizationRole::query()->findOrFail($validated['role_id']);

        try {
            $assignment = DB::transaction(function () use ($request, $member, $project, $current, $role, $validated) {
                if ((int) $current->authorization_role_id !== (int) $role->id) {
                    $oldRole = $current->role;
                    if (! $oldRole) {
                        abort(409, 'Current canonical role is missing');
                    }
                    $this->assignmentService->revoke(
                        $request->user(),
                        $member,
                        $oldRole,
                        new AssignmentScope('project', (int) $project->id),
                    );
                }

                return $this->assignmentService->assign(
                    $request->user(),
                    $member,
                    $role,
                    new AssignmentWrite(
                        new AssignmentScope('project', (int) $project->id),
                        isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at']) : null,
                    ),
                );
            });
        } catch (AuthorizationAssignmentDenied $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $this->activityService->logMemberUpdated($project, $member, $request->user(), $role->name, $request->ip());

        return response()->json([
            'message' => 'Member role updated successfully',
            'data' => $this->canonicalAssignmentPayload($assignment, $role, $project),
        ]);
    }

    /**
     * إضافة صاحب مصلحة للمشروع
     */
    public function addStakeholder(StoreProjectStakeholderRequest $request, string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $validated = $request->validated();

        $stakeholder = $project->stakeholders()->create($validated);
        $this->activityService->logStakeholderAdded($project, $stakeholder, $request->user(), $request->ip());

        return response()->json([
            'message' => 'تم إضافة صاحب المصلحة بنجاح',
            'stakeholder' => $stakeholder,
        ], 201);
    }

    /**
     * جلب أصحاب المصلحة للمشروع
     */
    public function getStakeholders(Request $request, string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

        return response()->json($project->stakeholders()->get());
    }

    /**
     * جلب صاحب مصلحة واحد
     */
    public function getStakeholder(Request $request, string $id, string $stakeholderId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('view', $project);

        $stakeholder = $project->stakeholders()->where('id', $stakeholderId)->firstOrFail();

        return response()->json($stakeholder);
    }

    /**
     * تحديث صاحب مصلحة
     */
    public function updateStakeholder(UpdateProjectStakeholderRequest $request, string $id, string $stakeholderId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $validated = $request->validated();

        $stakeholder = $project->stakeholders()->where('id', $stakeholderId)->firstOrFail();
        $stakeholder->update($validated);

        return response()->json([
            'message' => 'تم تحديث صاحب المصلحة بنجاح',
            'stakeholder' => $stakeholder->fresh(),
        ]);
    }

    /**
     * حذف صاحب مصلحة
     */
    public function removeStakeholder(Request $request, string $id, string $stakeholderId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $stakeholder = $project->stakeholders()->where('id', $stakeholderId)->first();
        $stakeholderName = $stakeholder?->name ?? 'صاحب مصلحة محذوف';
        $stakeholderRole = $stakeholder?->role;

        $project->stakeholders()->where('id', $stakeholderId)->delete();
        $this->activityService->logStakeholderDeleted($project, $stakeholderName, $stakeholderRole, $stakeholderId, $request->user(), $request->ip());

        return response()->json([
            'message' => 'تم حذف صاحب المصلحة بنجاح',
        ]);
    }

    /**
     * إضافة خطر للمشروع
     */
    public function addRisk(StoreProjectRiskRequest $request, string $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $validated = $request->validated();

        // توحيد اسم الحقل
        if (! isset($validated['risk']) && isset($validated['description'])) {
            $validated['risk'] = $validated['description'];
        }
        unset($validated['description']);

        if (! isset($validated['status'])) {
            $validated['status'] = 'open';
        }

        $maxOrder = $project->risks()->max('order') ?? 0;
        $validated['order'] = $maxOrder + 1;

        $risk = $project->risks()->create($validated);
        $this->activityService->logRiskAdded($project, $risk, $request->user(), $request->ip());

        return response()->json([
            'message' => 'تم إضافة الخطر بنجاح',
            'risk' => $risk,
        ], 201);
    }

    /**
     * تحديث خطر
     */
    public function updateRisk(UpdateProjectRiskRequest $request, string $id, string $riskId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $validated = $request->validated();

        $risk = $project->risks()->where('id', $riskId)->firstOrFail();
        $oldValues = $risk->toArray();
        $risk->update($validated);

        $this->activityService->logRiskUpdated($project, $risk, $oldValues, $request->user(), $request->ip());

        return response()->json([
            'message' => 'تم تحديث الخطر بنجاح',
            'risk' => $risk,
        ]);
    }

    /**
     * حذف خطر
     */
    public function removeRisk(Request $request, string $id, string $riskId): JsonResponse
    {
        $project = Project::findOrFail($id);
        $this->authorize('update', $project);

        $risk = $project->risks()->where('id', $riskId)->first();
        $riskDescription = $risk ? mb_substr($risk->risk, 0, 50).(mb_strlen($risk->risk) > 50 ? '...' : '') : 'خطر محذوف';
        $riskData = $risk?->toArray();

        $project->risks()->where('id', $riskId)->delete();
        $this->activityService->logRiskDeleted($project, $riskDescription, $riskData, $request->user(), $request->ip());

        return response()->json([
            'message' => 'تم حذف الخطر بنجاح',
        ]);
    }

    /**
     * Project types whose creation/visibility can be delegated to a governing
     * department. Kept in one place so the admin screen and validation agree.
     *
     * @return array<string, string> type key => Arabic label
     */
    private function governableProjectTypes(): array
    {
        return [
            'development' => 'مشروع تطويري (PMBOK)',
            'improvement' => 'مشروع تحسين (FOCUS-PDCA)',
        ];
    }

    /**
     * Read the "governing department per project type" mapping plus the list of
     * departments to choose from. Admin-gated (manage_organization / super_admin).
     */
    public function getGoverningDepartments(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! AccessDecision::can($user, Capability::SETTINGS_MANAGE)) {
            abort(403, 'ليس لديك صلاحية عرض هذه الإعدادات');
        }

        $types = $this->governableProjectTypes();
        $mapping = ProjectSetting::getGoverningDepartments();

        $departments = Department::query()
            ->active()
            ->forOrganization($user->isSuperAdmin() ? null : $user->organization_id)
            ->select('id', 'name', 'code', 'level')
            ->orderBy('level')
            ->orderBy('name')
            ->get()
            ->map(fn ($dept) => [
                'id' => $dept->id,
                'name' => $dept->name,
                'code' => $dept->code,
                'level' => $dept->level,
                'level_name' => $dept->getLevelNameAttribute(),
            ]);

        return response()->json([
            'types' => collect($types)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'department_id' => $mapping[$key] ?? null,
            ])->values(),
            'mapping' => (object) $mapping,
            'departments' => $departments,
        ]);
    }

    /**
     * Update the governing-department-per-type mapping. Admin-gated. A null/absent
     * department for a type clears its governor (only the own-department + org
     * paths remain for that type).
     */
    public function updateGoverningDepartments(UpdateGoverningDepartmentsRequest $request): JsonResponse
    {
        // Authz against SETTINGS_MANAGE (engine) + payload validation are now
        // owned by UpdateGoverningDepartmentsRequest. The per-type org-isolation
        // cross-field check (the department must belong to the caller's org)
        // stays in the controller — it needs the loaded user + per-dept lookup.
        $user = $request->user();
        $allowedTypes = array_keys($this->governableProjectTypes());

        $validated = $request->validated();

        // Org isolation: a non-super admin may only point a type at a department
        // inside their own organization.
        $orgDeptIds = $user->isSuperAdmin()
            ? null
            : Department::query()
                ->forOrganization($user->organization_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        $map = [];
        foreach ($validated['mapping'] as $type => $departmentId) {
            if (! in_array($type, $allowedTypes, true)) {
                continue; // ignore unknown types
            }
            if ($departmentId === null || $departmentId === '') {
                continue; // unset governor for this type
            }
            $departmentId = (int) $departmentId;
            if ($orgDeptIds !== null && ! in_array($departmentId, $orgDeptIds, true)) {
                abort(403, 'القسم المحدد لا ينتمي لمؤسستك');
            }
            $map[$type] = $departmentId;
        }

        ProjectSetting::setGoverningDepartments($map);

        return response()->json([
            'message' => 'تم تحديث الأقسام المُشرِفة بنجاح',
            'mapping' => (object) ProjectSetting::getGoverningDepartments(),
        ]);
    }

    private function projectAssignments(User $user, Project $project)
    {
        return AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->get();
    }

    private function manualProjectAssignment(User $user, Project $project): ?AuthorizationRoleAssignment
    {
        $assignments = AuthorizationRoleAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->where('source', 'manual')
            ->get();

        abort_if($assignments->count() > 1, 409, 'Multiple manual project assignments require reconciliation');

        return $assignments->first();
    }

    private function exactManualProjectAssignment(
        User $user,
        Project $project,
        AuthorizationRole $role,
    ): ?AuthorizationRoleAssignment {
        return AuthorizationRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('authorization_role_id', $role->id)
            ->where('scope_type', 'project')
            ->where('scope_id', $project->id)
            ->where('source', 'manual')
            ->first();
    }

    /** @return array<string, mixed> */
    private function canonicalAssignmentPayload(
        AuthorizationRoleAssignment $assignment,
        AuthorizationRole $role,
        Project $project,
    ): array {
        return [
            'id' => (int) $assignment->id,
            'user_id' => (int) $assignment->user_id,
            'role_id' => (int) $assignment->authorization_role_id,
            'role_name' => $role->name,
            'role_display' => $role->label,
            'project_id' => (int) $project->id,
            'scope_type' => 'project',
            'scope_id' => (int) $project->id,
            'source' => $assignment->source,
            'expires_at' => $assignment->expires_at,
        ];
    }
}
