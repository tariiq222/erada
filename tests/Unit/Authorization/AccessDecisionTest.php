<?php

namespace Tests\Unit\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\SensitivelyScoped;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AccessDecisionTest — اختبارات الوحدة لمحرّك AuthZ الموحّد
 *
 * تغطي:
 * 1. super_admin bypass
 * 2. منح قدرة عبر دور على مستوى أعلى في السلسلة (positional / upward)
 * 3. دور العنصر inline على target نفسه
 * 4. منع cross-org (D-04)
 * 5. منع null-org للمستخدم (D-02)
 * 6. سلسلة بعلاقات null (مهمة شخصية، مشروع بلا قسم) لا ترمي
 * 7. الرفض عند غياب أي دور
 *
 * ملاحظة: يعتمد على DB/factories حقيقية (RefreshDatabase على postgres-test).
 * ScopeType/ScopedRoleDefinition تُنشأ مباشرة في الاختبارات — لا يوجد seeder مخصص لها.
 * model_class مطلوب NOT NULL في scope_types — نمرّر اسم نموذج وهمي.
 */
class AccessDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
    }

    // =========================================================
    // 1. super_admin bypass
    // =========================================================

    #[Test]
    public function super_admin_can_do_anything_regardless_of_target(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        $this->assertTrue(AccessDecision::can($superAdmin, Capability::PROJECTS_DELETE, $project));
        $this->assertTrue(AccessDecision::can($superAdmin, Capability::PROJECTS_VIEW, $project));
        $this->assertTrue(AccessDecision::can($superAdmin, Capability::ROLES_ASSIGN, null));
    }

    #[Test]
    public function super_admin_can_act_even_on_cross_org_target(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');

        $foreignProject = Project::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->assertTrue(AccessDecision::can($superAdmin, Capability::PROJECTS_EDIT, $foreignProject));
    }

    // =========================================================
    // 2. منح قدرة عبر دور على مستوى أعلى في السلسلة
    // =========================================================

    #[Test]
    public function positional_role_on_parent_grants_child_capability(): void
    {
        // مستخدم له دور إداري على القسم → يجب أن يملك PROJECTS_EDIT على المشروع
        [$user, $department, $project, $roleDefinition] = $this->createOrgWithAdminDeptRole();

        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'department',
            scopeId: $department->id,
            inheritToChildren: true
        );

        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_EDIT, $project));
    }

    #[Test]
    public function positional_role_on_org_grants_project_capability(): void
    {
        [$user, $orgRoleDefinition] = $this->createOrgWithOrgAdminRole();

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        $user->assignScopedRole(
            role: $orgRoleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: $this->org->id
        );

        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_VIEW, $project));
    }

    // =========================================================
    // Phase 4 (ADR-UNIFIED-ROLE-ACCESS): engine decoupled from Spatie.
    // An org functional role granted ONLY via model_has_scoped_roles
    // (no Spatie role row) is recognized by grantedViaOrgFunctionalRole.
    // =========================================================

    #[Test]
    public function scoped_only_org_role_grants_via_org_functional_layer_without_spatie(): void
    {
        // A functional org role defined as scoped-only (NO Spatie Role row) that
        // carries an explicit capability. The user is assigned it purely through
        // model_has_scoped_roles.
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: ScopedRole::SCOPE_ORGANIZATION,
            roleKey: 'pmo_manager',
            isAdminRole: false,
            canViewAll: true,
        );

        // Sanity: the user has NO Spatie roles at all — the coupling being broken.
        $this->assertSame([], $user->getRoleNames()->all());

        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: $this->org->id,
        );

        // The decision is granted...
        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_VIEW, null));

        // ...and specifically via the org-functional layer (the decoupled path),
        // proving grantedViaOrgFunctionalRole resolved the role from the scoped
        // assignment rather than from a Spatie role name.
        $trace = AccessDecision::whyCan($user, Capability::PROJECTS_VIEW, null);
        $this->assertTrue($trace['granted']);
        $this->assertSame('org_functional_role', $trace['layer']);

        // The list-filter helper that mirrors this decision must agree too.
        $this->assertTrue(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
    }

    #[Test]
    public function positional_role_without_permission_is_denied(): void
    {
        // دور يملك can_view_all=true لكن can_edit=false, can_delete=false
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'department',
            roleKey: 'viewer_role',
            isAdminRole: false,
            canEdit: false,
            canDelete: false,
            canViewAll: true,
            canManageMembers: false
        );

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'department',
            scopeId: $department->id
        );

        // يملك VIEW لكن ليس DELETE
        $this->assertTrue(AccessDecision::can($user, Capability::DEPARTMENTS_VIEW, $department));
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_DELETE, $project));
    }

    // =========================================================
    // 3. دور العنصر inline على target نفسه
    // =========================================================

    #[Test]
    public function inline_role_on_target_grants_capability(): void
    {
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'project',
            roleKey: 'project_manager',
            isAdminRole: true
        );

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'project',
            scopeId: $project->id
        );

        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_EDIT, $project));
        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_DELETE, $project));
    }

    #[Test]
    public function inline_role_on_target_does_not_grant_access_to_sibling_project(): void
    {
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'project',
            roleKey: 'project_member',
            isAdminRole: false,
            canViewAll: true
        );

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project1 = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);
        $project2 = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        // دور فقط على project1
        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'project',
            scopeId: $project1->id
        );

        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_VIEW, $project1));
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_VIEW, $project2));
    }

    // =========================================================
    // 4. منع cross-org (D-04)
    // =========================================================

    #[Test]
    public function cross_org_access_is_denied_even_with_valid_role(): void
    {
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'project',
            roleKey: 'project_admin',
            isAdminRole: true
        );

        $foreignDept = Department::factory()->create(['organization_id' => $this->otherOrg->id]);
        $foreignProject = Project::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'department_id' => $foreignDept->id,
        ]);

        // دور على مشروع في مؤسسة أخرى
        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'project',
            scopeId: $foreignProject->id
        );

        // المستخدم في $this->org, الهدف في otherOrg → false
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_VIEW, $foreignProject));
    }

    #[Test]
    public function same_org_access_is_permitted_with_valid_role(): void
    {
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'project',
            roleKey: 'proj_viewer',
            isAdminRole: false,
            canViewAll: true
        );

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        $user->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'project',
            scopeId: $project->id
        );

        $this->assertTrue(AccessDecision::can($user, Capability::PROJECTS_VIEW, $project));
    }

    // =========================================================
    // 5. منع null-org للمستخدم (D-02)
    // =========================================================

    #[Test]
    public function user_without_org_is_denied_org_scoped_target(): void
    {
        $userWithoutOrg = User::factory()->create(['organization_id' => null]);

        [$dummy, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'project',
            roleKey: 'p_admin',
            isAdminRole: true,
            forUser: $userWithoutOrg
        );

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        $userWithoutOrg->assignScopedRole(
            role: $roleDefinition->role_key,
            scopeType: 'project',
            scopeId: $project->id
        );

        // المستخدم بلا مؤسسة لا يستطيع الوصول للهدف المؤسّسي
        $this->assertFalse(AccessDecision::can($userWithoutOrg, Capability::PROJECTS_VIEW, $project));
    }

    // =========================================================
    // 6. سلسلة null آمنة (مهمة شخصية / مشروع بلا قسم)
    // =========================================================

    #[Test]
    public function personal_task_with_no_parent_does_not_throw(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        // مهمة شخصية: project_id=null, department_id=null
        $task = Task::factory()->create([
            'owner_id' => $user->id,
            'project_id' => null,
            'department_id' => null,
            'type' => 'personal',
        ]);

        // يجب ألا يرمي — فقط يُعيد false لعدم وجود دور
        $result = AccessDecision::can($user, Capability::TASKS_EDIT, $task);
        $this->assertFalse($result);
    }

    #[Test]
    public function project_without_department_chain_does_not_throw(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => null, // بلا قسم
        ]);

        // يجب ألا يرمي
        $result = AccessDecision::can($user, Capability::PROJECTS_EDIT, $project);
        $this->assertFalse($result);
    }

    #[Test]
    public function null_target_checks_org_level_only(): void
    {
        [$user, $orgRoleDefinition] = $this->createOrgWithOrgAdminRole();

        $user->assignScopedRole(
            role: $orgRoleDefinition->role_key,
            scopeType: ScopedRole::SCOPE_ORGANIZATION,
            scopeId: $this->org->id
        );

        // target=null → فحص على مستوى المؤسسة فقط
        $this->assertTrue(AccessDecision::can($user, Capability::ROLES_VIEW, null));
    }

    #[Test]
    public function null_target_returns_false_without_org_role(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->assertFalse(AccessDecision::can($user, Capability::ROLES_VIEW, null));
    }

    // =========================================================
    // 7. انعدام أي دور → false
    // =========================================================

    #[Test]
    public function regular_user_with_no_roles_is_denied(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_VIEW, $project));
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_EDIT, $project));
        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_DELETE, $project));
    }

    #[Test]
    public function expired_role_does_not_grant_capability(): void
    {
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'project',
            roleKey: 'expired_role',
            isAdminRole: true
        );

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        // دور منتهي الصلاحية — أنشئ مباشرة لتجنّب revoke في assignScopedRole
        ScopedRole::create([
            'user_id' => $user->id,
            'role' => $roleDefinition->role_key,
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse(AccessDecision::can($user, Capability::PROJECTS_EDIT, $project));
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * إنشاء ScopeType + ScopedRoleDefinition + User في $this->org
     *
     * @return array{0: User, 1: ScopeType, 2: ScopedRoleDefinition}
     */
    private function createScopeTypeAndRoleDefinition(
        string $scopeKey,
        string $roleKey,
        bool $isAdminRole = false,
        bool $canEdit = false,
        bool $canDelete = false,
        bool $canViewAll = false,
        bool $canManageMembers = false,
        ?User $forUser = null
    ): array {
        // model_class مطلوب NOT NULL في الجدول — نمرّر placeholder آمن
        $modelClassMap = [
            'organization' => Organization::class,
            'department' => Department::class,
            'project' => Project::class,
            'task' => Task::class,
            'program' => Program::class,
            'portfolio' => Portfolio::class,
            'risk' => Risk::class,
            'incident' => IncidentReport::class,
        ];

        $modelClass = $modelClassMap[$scopeKey] ?? Model::class;

        $scopeType = ScopeType::firstOrCreate(
            ['key' => $scopeKey],
            [
                'label_ar' => $scopeKey,
                'label_en' => $scopeKey,
                'model_class' => $modelClass,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        // Direct DB to pass legacy NOT NULL columns (name/display_name/scope_type)
        // not exposed in the model $fillable. Match on the (name, scope_type)
        // unique constraint so a definition already seeded by the backfill
        // migrations is reused instead of triggering a duplicate-key error.
        // Phase 3 (ADR-UNIFIED-ROLE-ACCESS): granular flags are retired columns.
        // Express their grants as explicit permissions[] using the exact action-suffix
        // expansion the engine used to derive from the flags. is_admin_role stays a column.
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $capability) use ($actions) {
                $action = str_contains($capability, '.')
                    ? substr($capability, strrpos($capability, '.') + 1)
                    : $capability;

                return in_array($action, $actions, true);
            }
        ));

        $permissions = [];
        if ($canEdit) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if ($canDelete) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if ($canViewAll) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if ($canManageMembers) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        $permissions = array_values(array_unique($permissions));

        $attributes = [
            'scope_type_id' => $scopeType->id,
            'role_key' => $roleKey,
            'display_name' => $roleKey,
            'label_ar' => $roleKey,
            'label_en' => $roleKey,
            'is_admin_role' => $isAdminRole,
            'permissions' => json_encode($permissions),
            'is_active' => true,
            'sort_order' => 0,
            'updated_at' => now(),
        ];

        $existingId = DB::table('scoped_role_definitions')
            ->where('name', $roleKey)
            ->where('scope_type', $scopeKey)
            ->value('id');

        if ($existingId) {
            DB::table('scoped_role_definitions')->where('id', $existingId)->update($attributes);
        } else {
            $existingId = DB::table('scoped_role_definitions')->insertGetId($attributes + [
                'name' => $roleKey,
                'scope_type' => $scopeKey,
                'created_at' => now(),
            ]);
        }

        $roleDefinition = ScopedRoleDefinition::find($existingId);

        $user = $forUser ?? User::factory()->create(['organization_id' => $this->org->id]);

        Cache::flush();

        return [$user, $scopeType, $roleDefinition];
    }

    /**
     * إنشاء مستخدم + دور إداري على مستوى القسم
     *
     * @return array{0: User, 1: Department, 2: Project, 3: ScopedRoleDefinition}
     */
    private function createOrgWithAdminDeptRole(): array
    {
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: 'department',
            roleKey: 'dept_admin',
            isAdminRole: true
        );

        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $department->id,
        ]);

        return [$user, $department, $project, $roleDefinition];
    }

    /**
     * إنشاء مستخدم + دور إداري على مستوى المؤسسة
     *
     * @return array{0: User, 1: ScopedRoleDefinition}
     */
    private function createOrgWithOrgAdminRole(): array
    {
        [$user, $scopeType, $roleDefinition] = $this->createScopeTypeAndRoleDefinition(
            scopeKey: ScopedRole::SCOPE_ORGANIZATION,
            roleKey: 'org_admin',
            isAdminRole: true,
            canViewAll: true
        );

        return [$user, $roleDefinition];
    }

    // =========================================================
    // Phase 9-D-B — Minimal cluster_tree engine primitive
    // =========================================================
    //
    // NOTE: هذه الاختبارات تستخدم Capability::CLUSTER_TREE_VIEW فقط.
    // لا تختبر users.view / projects.view / meetings.view — لأن Phase 9-D-B
    // لا يُفعّل رؤية بيانات الموديولات. الـ primitive يثبت فقط أن rescue branch
    // يسمح لـ user في parent cluster (مع grant صريح) باجتياز org_isolation_denied
    // عند استخدام CLUSTER_TREE_VIEW على target في child org.

    /**
     * Helper: إنشاء ScopedRoleDefinition مع permissions مخصّصة، ثم منحها
     * للمستخدم على organization. السبب: assignScopedRole لا يقبل permissions
     * ولا يضبط role_definition_id — فلو استعملناه، الـ engine لا يجد الـ
     * definition عبر الـ relation ولا عبر findByKey (في بيئة الاختبار).
     */
    private function grantClusterTreeRole(User $user, Organization $org): ScopedRoleDefinition
    {
        $roleKey = 'cluster_tree_viewer';

        $scopeType = ScopeType::firstOrCreate(
            ['key' => 'organization'],
            [
                'label_ar' => 'organization',
                'label_en' => 'organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => true,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        $defId = DB::table('scoped_role_definitions')->insertGetId([
            'name' => $roleKey,
            'scope_type' => 'organization',
            'scope_type_id' => $scopeType->id,
            'display_name' => $roleKey,
            'description' => 'Phase 9-D-B test role: grants only CLUSTER_TREE_VIEW',
            'role_key' => $roleKey,
            'permissions' => json_encode([Capability::CLUSTER_TREE_VIEW]),
            'reach' => json_encode(['core' => 'all']),
            'is_admin_role' => false,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // أنشئ ScopedRole مباشرة في DB (نمرّ عبر fillable لتجنّب role_definition_id
        // غير الموجود في model_has_scoped_roles). هذا يضمن أن الـ engine
        // يستعمل findByKey() في الـ fallback path.
        DB::table('model_has_scoped_roles')->insert([
            'user_id' => $user->id,
            'role' => $roleKey,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'inherit_to_children' => true,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // امسح الـ cache — findByKey() يقرأ من cache tags
        Cache::flush();

        return ScopedRoleDefinition::findOrFail($defId);
    }

    #[Test]
    public function cluster_user_with_grant_can_see_child_org_target(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = Project::factory()->create([
            'organization_id' => $child->id,
            'department_id' => Department::factory()->create(['organization_id' => $child->id])->id,
        ]);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterTreeRole($user, $cluster);

        $this->assertTrue(
            AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $childProject),
            'cluster user with CLUSTER_TREE_VIEW grant should see child org target'
        );
    }

    #[Test]
    public function cluster_user_without_grant_cannot_see_child_org_target(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = Project::factory()->create([
            'organization_id' => $child->id,
            'department_id' => Department::factory()->create(['organization_id' => $child->id])->id,
        ]);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        // بدون grant — لا role على cluster

        $this->assertFalse(
            AccessDecision::can($user, Capability::CLUSTER_TREE_VIEW, $childProject),
            'cluster user without grant must NOT see child org target via cluster_tree'
        );

        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $childProject),
            'cross-org access without grant must remain denied'
        );
    }

    #[Test]
    public function cluster_user_cannot_see_sibling_cluster_target(): void
    {
        $clusterA = Organization::factory()->cluster()->create();
        $clusterB = Organization::factory()->cluster()->create();
        $childB = Organization::factory()->hospital()->childOf($clusterB)->create();
        $childBProject = Project::factory()->create([
            'organization_id' => $childB->id,
            'department_id' => Department::factory()->create(['organization_id' => $childB->id])->id,
        ]);

        $userA = User::factory()->create(['organization_id' => $clusterA->id]);
        $this->grantClusterTreeRole($userA, $clusterA);

        $this->assertFalse(
            AccessDecision::can($userA, Capability::CLUSTER_TREE_VIEW, $childBProject),
            'cluster_tree is one-directional: clusterA cannot see clusterB subtree'
        );
    }

    #[Test]
    public function child_user_cannot_see_parent_cluster_data_via_cluster_tree(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $clusterProject = Project::factory()->create([
            'organization_id' => $cluster->id,
            'department_id' => Department::factory()->create(['organization_id' => $cluster->id])->id,
        ]);

        $childUser = User::factory()->create(['organization_id' => $child->id]);
        $this->grantClusterTreeRole($childUser, $child);

        $this->assertFalse(
            AccessDecision::can($childUser, Capability::CLUSTER_TREE_VIEW, $clusterProject),
            'child user must NOT see parent cluster data via cluster_tree (no reverse visibility)'
        );
    }

    #[Test]
    public function cluster_tree_rescue_only_fires_when_strict_equality_would_deny(): void
    {
        $org = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => Department::factory()->create(['organization_id' => $org->id])->id,
        ]);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantClusterTreeRole($user, $org);

        // target في نفس org — rescue branch لا يجب أن يطلق (same-org handled by strict eq)
        $trace = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_VIEW, $project);
        $this->assertTrue($trace['granted']);
        $this->assertNotSame(
            'cluster_tree_rescue',
            $trace['layer'],
            'cluster_tree_rescue must not fire for same-org target (strict eq handles it)'
        );
    }

    #[Test]
    public function cluster_tree_rescue_does_not_bypass_sensitive_floor_for_ovr(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterTreeRole($user, $cluster);

        // نموذج stub يطبّق SensitivelyScoped لتجنّب أعمدة OVR NOT NULL العديدة.
        // المهم: هو Model + SensitivelyScoped + organization_id.
        $sensitiveTarget = new Phase9DbSensitiveStubTarget;
        $sensitiveTarget->organization_id = $child->id;
        $sensitiveTarget->exists = true;

        $trace = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_VIEW, $sensitiveTarget);
        $this->assertFalse(
            $trace['granted'],
            'CRITICAL: cluster_tree must NOT rescue sensitive (SensitivelyScoped) targets'
        );
        $this->assertNotSame('cluster_tree_rescue', $trace['layer']);
    }

    #[Test]
    public function cluster_tree_grant_is_auditable_in_why_can_layer(): void
    {
        $cluster = Organization::factory()->cluster()->create();
        $child = Organization::factory()->hospital()->childOf($cluster)->create();
        $childProject = Project::factory()->create([
            'organization_id' => $child->id,
            'department_id' => Department::factory()->create(['organization_id' => $child->id])->id,
        ]);

        $user = User::factory()->create(['organization_id' => $cluster->id]);
        $this->grantClusterTreeRole($user, $cluster);

        $trace = AccessDecision::whyCan($user, Capability::CLUSTER_TREE_VIEW, $childProject);
        $this->assertTrue($trace['granted']);
        $this->assertSame('cluster_tree_rescue', $trace['layer']);
        $this->assertStringContainsString('cluster', $trace['reason']);
    }
}

/**
 * Phase9DbSensitiveStubTarget — stub model لـ Sensitive test فقط.
 * ينفّذ SensitivelyScoped ويعيد true من isSensitive() لإثبات أن rescue branch
 * لا يتجاوز sensitive floor. organization_id property تكفي لـ extractOrganizationId.
 *
 * السبب: استخدام IncidentReport يتطلب أعمدة NOT NULL كثيرة (reporter_id,
 * reporter_name, incident_datetime) — هذا stub يتجنّب ذلك.
 */
class Phase9DbSensitiveStubTarget extends Model implements SensitivelyScoped
{
    protected $table = 'projects';

    protected $guarded = [];

    public $timestamps = false;

    public function isSensitive(): bool
    {
        return true;
    }

    public function mayAccessSensitive(User $user): bool
    {
        return false;
    }
}
