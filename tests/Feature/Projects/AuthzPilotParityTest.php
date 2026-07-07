<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\AuthorizationRuntimeMode;
use App\Modules\Core\Authorization\AuthzShadowMismatchException;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRecordRule;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AuthzPilotParityTest — Phase 1 Task 1.2.2 (Projects pilot, shadow parity proof).
 *
 * Proves that, for a CURATED Projects subset of decisions, the legacy engine and
 * the new authorization_role_permissions + authorization_record_rules path can
 * run side-by-side under AuthorizationRuntimeMode::enableShadow() WITHOUT drift
 * when the new tables are seeded to mirror the legacy grant.
 *
 * Mirrors the subset covered by ProjectPolicyOracleTest's decision matrix
 * (viewer/member/manager × view/update/delete/manageMembers/assignRoles +
 * cross-org deny + unsupported-scope narrowing) but expressed against the
 * SHADOW branch, not against the engine itself. If the engine drifts, both
 * this suite AND ProjectPolicyOracleTest will surface the regression; if
 * only the SHADOW branch drifts, this suite fails first.
 *
 * SKIPPED BY DESIGN (documented in test names + per-test comments):
 *  - viewAny / create target=null: the SHADOW branch only runs target-bound
 *    decisions (AccessDecision::can step before the SHADOW guard). Test
 *    cases with $target=null exercise the legacy path only.
 *  - super_admin: short-circuit before the SHADOW branch. super_admin's
 *    decisions are pinned by ProjectPolicyOracleTest's super_admin rows.
 *  - unsupported scopes (department/team/cluster/hospital/own): the limited
 *    SHADOW slice grants these as intentional mismatches (legacy grants via
 *    a department-scoped scoped_role, new path denies because
 *    assignmentScopeApplies() rejects them). These are covered by the
 *    "shadow_throws_when_unsupported_scope_narrows_new_path" test below.
 *  - owner-floor / creator lifecycle drift: the owner_floor layer is
 *    legacy-only (not modeled in the new tables). Creator rows in
 *    ProjectPolicyOracleTest cover that surface; we deliberately do not
 *    mirror it here.
 *
 * NEGATIVE INVARIANT (pinned across the curated subset):
 *  No case is allowed where legacy DENIES but new-path ALLOWS. If an
 *  unsupported-scope test asserts a mismatch, it MUST throw with
 *  newPathDecision=false. Every "new-path allow" is backed by an explicit
 *  authorization_record_rule so a bare permission row + 'all' assignment
 *  cannot widen access (recordRulesAdmitTarget() only short-circuits to
 *  true when the rule set is empty AND a record rule was intentionally
 *  omitted by the seeder).
 *
 * POSTGRESQL-ONLY: the SHADOW branch and the new authorization_* tables
 * both depend on PostgreSQL features (CHECK constraints, JSONB). Tests
 * skip on SQLite (LR-008 / AGENTS.md: SQLite is intentionally disallowed).
 */
class AuthzPilotParityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authz pilot parity test is PostgreSQL-only.');
        }

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->otherOrg = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        // Project scope type + manager/member/viewer definitions, mirroring
        // ProjectPolicyOracleTest::seedProjectScopeDefinitions() so the legacy
        // engine path and the new-path mirrors resolve against identical
        // role definitions. The engine reads `role_key` + `permissions[]`;
        // the new path reads `authorization_role_permissions` directly.
        $this->seedProjectScopeDefinitions();

        // Each test starts in a known runtime-mode state.
        AuthorizationRuntimeMode::reset();

        // Belt-and-braces cache flush (the trait handles most of this, but
        // the SHADOW branch also reads from authorization_* tables whose
        // cache hooks live on their models).
        Cache::flush();
        AccessDecision::flushCache();
        ScopedRoleDefinition::clearCache();
        ScopeType::clearCache();
    }

    protected function tearDown(): void
    {
        AuthorizationRuntimeMode::reset();
        AccessDecision::flushCache();
        Cache::flush();
        ScopedRoleDefinition::clearCache();
        ScopeType::clearCache();

        parent::tearDown();
    }

    // =====================================================================
    // 1. PARITY: project_viewer legacy grant mirrored to new path
    //    Expected: view=true, update/delete/manageMembers/assignRoles=false
    //    Legacy grant via ScopedRole(project, viewer) on the project.
    //    New-path mirror: role + view permission + 'all' assignment +
    //    authorization_record_rules.eq(id=project.id) for view.
    // =====================================================================

    #[Test]
    public function shadow_passes_for_project_viewer_grant_mirroring_view_only(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();
        $user = $this->makePlainUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_VIEWER);

        // Mirror: role with projects.view + 'all' assignment + eq record rule
        // narrowing to this specific project. The record rule is REQUIRED:
        // without it, an 'all' assignment would widen access to every project
        // and trigger the negative invariant (legacy-deny + new-allow).
        $this->mirrorProjectScopedGrant(
            user: $user,
            roleKey: 'pilot_proj_viewer_mirror',
            action: 'view',
            projectId: (int) $project->id,
        );

        // view — both allow (legacy via project-scoped scoped_role, new via
        // role permission + 'all' assignment + eq record rule admitting target).
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'project_viewer must be allowed to view the scoped project.'
        );

        // update, delete, manageMembers, assignRoles — both deny (no permission
        // row for these actions on the mirror role). No exception expected.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_EDIT, $project),
            'project_viewer must NOT be allowed to edit the scoped project.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_DELETE, $project),
            'project_viewer must NOT be allowed to delete the scoped project.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_MANAGE_MEMBERS, $project),
            'project_viewer must NOT be allowed to manage members.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_ASSIGN_ROLES, $project),
            'project_viewer must NOT be allowed to assign project roles.'
        );
    }

    // =====================================================================
    // 2. PARITY: project_member legacy grant mirrored to new path
    //    Expected: view=true, all writes=false (same as viewer per the
    //    seeded permissions[]).
    // =====================================================================

    #[Test]
    public function shadow_passes_for_project_member_grant_mirroring_view_only(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();
        $user = $this->makePlainUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MEMBER);

        $this->mirrorProjectScopedGrant(
            user: $user,
            roleKey: 'pilot_proj_member_mirror',
            action: 'view',
            projectId: (int) $project->id,
        );

        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'project_member must be allowed to view the scoped project.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_EDIT, $project),
            'project_member must NOT be allowed to edit the scoped project.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_DELETE, $project),
            'project_member must NOT be allowed to delete the scoped project.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_MANAGE_MEMBERS, $project),
            'project_member must NOT be allowed to manage members.'
        );
    }

    // =====================================================================
    // 3. PARITY: project_manager legacy grant mirrored across multiple
    //    capabilities. Each capability needs its own (permission, record rule)
    //    row because the new-path action suffix is the action string itself
    //    (no capability-bundle concept in this slice).
    // =====================================================================

    #[Test]
    public function shadow_passes_for_project_manager_view_edit_manage_members(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();
        $user = $this->makePlainUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);

        // Mirror each capability the engine grants the manager separately.
        // project_manager in this codebase grants: view, edit, manage_members,
        // assign_roles (per ProjectPolicyOracleTest's pm: rows + the seeded
        // scoped_role_definition). project_manager does NOT grant delete.
        $this->mirrorProjectScopedGrant(
            user: $user,
            roleKey: 'pilot_proj_manager_view_mirror',
            action: 'view',
            projectId: (int) $project->id,
        );
        $this->mirrorProjectScopedGrant(
            user: $user,
            roleKey: 'pilot_proj_manager_edit_mirror',
            action: 'edit',
            projectId: (int) $project->id,
        );
        $this->mirrorProjectScopedGrant(
            user: $user,
            roleKey: 'pilot_proj_manager_manage_members_mirror',
            action: 'manage_members',
            projectId: (int) $project->id,
        );
        $this->mirrorProjectScopedGrant(
            user: $user,
            roleKey: 'pilot_proj_manager_assign_roles_mirror',
            action: 'assign_roles',
            projectId: (int) $project->id,
        );

        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'project_manager must be allowed to view the scoped project.'
        );
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_EDIT, $project),
            'project_manager must be allowed to edit the scoped project.'
        );
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_MANAGE_MEMBERS, $project),
            'project_manager must be allowed to manage members.'
        );
        $this->assertTrue(
            AccessDecision::can($user, Capability::PROJECTS_ASSIGN_ROLES, $project),
            'project_manager must be allowed to assign project roles.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_DELETE, $project),
            'project_manager must NOT be allowed to delete the scoped project '
            .'(engine spec: pm:delete:deny in ProjectPolicyOracleTest).'
        );
    }

    // =====================================================================
    // 4. PARITY: cross-org user. Both legacy (org_isolation_denied) and new
    //    path (assignment scope mismatch / record rule excludes target) deny.
    //    Shadow must NOT throw on a deny/deny pair (negative invariant pin).
    // =====================================================================

    #[Test]
    public function shadow_silent_when_legacy_and_new_path_both_deny_cross_org(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();
        // User in OTHER org: legacy fails org_isolation_denied; new path
        // cannot resolve a matching role_permission unless we seed one, but
        // even with a seeded role_permission, an 'all' assignment would let
        // the new path through -- so we deliberately seed NO new-path rows.
        $user = User::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'department_id' => Department::factory()->create([
                'organization_id' => $this->otherOrg->id,
            ])->id,
        ]);

        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'Cross-org user must be denied; no AuthzShadowMismatchException expected.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_EDIT, $project),
            'Cross-org user must be denied on edit; no shadow throw expected.'
        );
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_DELETE, $project),
            'Cross-org user must be denied on delete; no shadow throw expected.'
        );
    }

    // =====================================================================
    // 5. PARITY: cross-org user WITH a seeded role_permission + 'all'
    //    assignment on the foreign target. Legacy denies (org_isolation);
    //    new path would ALSO deny on the assignmentScopeApplies() org check
    //    IF scoped as 'organization' (org mismatch), so we use 'all' scope
    //    to isolate the behavior: legacy denies, new path grants on the
    //    record rule. This is the documented negative-invariant risk --
    //    'all' + no record rule would widen access. With an eq rule that
    //    DOES NOT admit the target's id, the new path also denies. Both
    //    deny -> silent (no throw).
    //
    //    We deliberately do NOT test the "legacy-deny + new-allow" branch
    //    as a passing case -- it would violate the negative invariant. The
    //    pattern is documented here so a future maintainer sees the
    //    trap and never seeds an 'all' assignment without a matching
    //    record rule on the target.
    // =====================================================================

    #[Test]
    public function shadow_silent_when_record_rule_excludes_target_for_cross_org_probe(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();
        $user = User::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'department_id' => Department::factory()->create([
                'organization_id' => $this->otherOrg->id,
            ])->id,
        ]);

        // Seed: role permission for (Project, 'view') + 'all' assignment on
        // the foreign user + eq record rule that points at a DIFFERENT
        // (non-existent) project id. The rule excludes our actual target,
        // so the new path denies via recordRulesAdmitTarget().
        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('pilot_cross_org_view_rule_excludes');
        $this->attachPermission($role, $resource, 'view');

        // 'all' is CHECK-constrained to scope_id IS NULL.
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
        ]);

        AuthorizationRecordRule::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'view',
            'domain_json' => [
                'operator' => 'eq',
                'column' => 'id',
                // 0 is never a real project id -> rule always excludes.
                'value' => 0,
            ],
            'enabled' => true,
            'priority' => 10,
        ]);

        // Legacy: cross-org -> false. New path: 'all' assignment applies,
        // but the record rule eq(id=0) excludes this target -> false.
        // Both deny -> shadow silent.
        $this->assertFalse(
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project),
            'Cross-org + excluding record rule must be silent deny/deny.'
        );
    }

    // =====================================================================
    // 6. INTENTIONAL MISMATCH (documented): unsupported scope.
    //    Legacy grants via a project-scoped scoped_role; new-path mirror
    //    uses a DEPARTMENT-scope assignment (unsupported in this slice).
    //    The SHADOW branch must throw with newPathDecision=false (legacy
    //    allows, new denies). This pins the negative invariant: when new
    //    denies, it must NEVER have allowed first.
    // =====================================================================

    #[Test]
    public function shadow_throws_with_new_path_decision_false_when_assignment_scope_is_unsupported(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();
        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $user = $this->makePlainUser();
        $user->assignProjectRole($project, ScopedRole::PROJECT_VIEWER);

        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('pilot_proj_viewer_dept_scope');
        $this->attachPermission($role, $resource, 'view');

        // Unsupported scope: department. New path's assignmentScopeApplies()
        // returns false for department/team/cluster/hospital/own, so the new
        // path will deny even though the role_permission row exists. Legacy
        // grants via the project-scoped scoped_role above -> mismatch.
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => $department->id,
            'organization_id' => $this->org->id,
        ]);

        try {
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
            $this->fail(
                'Expected AuthzShadowMismatchException: legacy allows via the '
                .'project-scoped scoped_role, new path denies because the '
                .'department scope is unsupported in this slice.'
            );
        } catch (AuthzShadowMismatchException $e) {
            $this->assertTrue(
                $e->legacyDecision,
                'Legacy must grant via the project-scoped scoped_role.'
            );
            $this->assertFalse(
                $e->newPathDecision,
                'New path MUST deny on unsupported scope (negative invariant).'
            );
            $this->assertSame(Capability::PROJECTS_VIEW, $e->capability);
        }
    }

    // =====================================================================
    // 6.5 INVERSE MISMATCH (legacy-deny + new-allow): explicitly tests the
    //     OTHER side of the SHADOW comparator. A same-org plain user holds NO
    //     legacy scoped_role on the project AND NO functional org role, so the
    //     engine denies via the 'none' layer (no role grants). The new path is
    //     seeded with a role_permission + 'all' assignment + eq(id=project.id)
    //     record rule, so computeNewPathDecision() returns true. SHADOW must
    //     throw AuthzShadowMismatchException with legacyDecision=false and
    //     newPathDecision=true.
    //
    //     This pins the SHADOW branch's symmetry: the comparator must surface
    //     BOTH directions of drift, not only legacy-allow + new-deny. If a
    //     future change narrows the throw condition to one direction only,
    //     this test fails first.
    //
    //     NOTE: this is the inverse of test 5's documented "trap" pattern --
    //     it deliberately violates the negative invariant (it is itself the
    //     proof that the invariant is enforced via a thrown exception, not via
    //     a silent allow). Without the throw, the new path would widen access
    //     beyond what the legacy grant allowed.
    // =====================================================================

    #[Test]
    public function shadow_throws_with_legacy_decision_false_when_new_path_grants_without_legacy_grant(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();

        // Same-org plain user: no ScopedRole project-scoped grant, no Spatie
        // functional org role, no scoped org-role assignment. Legacy denies
        // via the 'none' layer inside whyCan() (no role grants this capability).
        $user = $this->makePlainUser();

        // Seed ONLY the new path: role_permission for (Project, 'view'),
        // an 'all' assignment on the user, and a record rule whose domain_json
        // eq(id=project.id) admits the target. Engine sees none of these;
        // new path sees role_permission -> 'all' applies ->
        // recordRulesAdmitTarget() -> eq rule matches -> true.
        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole('pilot_inverse_mismatch_no_legacy_grant');
        $this->attachPermission($role, $resource, 'view');

        // 'all' is CHECK-constrained to scope_id IS NULL.
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
        ]);

        AuthorizationRecordRule::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => 'view',
            'domain_json' => [
                'operator' => 'eq',
                'column' => 'id',
                'value' => (int) $project->id,
            ],
            'enabled' => true,
            'priority' => 10,
        ]);

        try {
            AccessDecision::can($user, Capability::PROJECTS_VIEW, $project);
            $this->fail(
                'Expected AuthzShadowMismatchException: legacy denies (no scoped '
                .'role and no functional org role), new path allows via '
                .'role_permission + \'all\' assignment + eq(id=project.id) record rule.'
            );
        } catch (AuthzShadowMismatchException $e) {
            $this->assertFalse(
                $e->legacyDecision,
                'Legacy MUST deny: no scoped role on the project and no '
                .'functional org role grant projects.view.'
            );
            $this->assertTrue(
                $e->newPathDecision,
                'New path MUST allow: role_permission for (Project, view) + '
                .'\'all\' assignment + eq(id=project.id) record rule admit the target.'
            );
            $this->assertSame(Capability::PROJECTS_VIEW, $e->capability);
        }
    }

    // =====================================================================
    // 7. POLICY SURFACE: each curated (persona, ability) pair in the
    //    Projects subset MUST agree between the legacy engine path and the
    //    SHADOW branch when the new tables mirror the legacy grant. This
    //    walks the same decisions ProjectPolicyOracleTest pins, but routes
    //    through AccessDecision::can under shadow, exercising BOTH the
    //    'allow' and 'deny' rows of the curated subset.
    // =====================================================================

    #[Test]
    public function shadow_agrees_with_engine_on_curated_projects_subset(): void
    {
        AuthorizationRuntimeMode::enableShadow();

        $project = $this->makeProject();

        // ---- project_viewer on the project ----
        $viewer = $this->makePlainUser();
        $viewer->assignProjectRole($project, ScopedRole::PROJECT_VIEWER);
        $this->mirrorProjectScopedGrant(
            user: $viewer,
            roleKey: 'pilot_subset_viewer_view',
            action: 'view',
            projectId: (int) $project->id,
        );

        $this->assertSame(
            (new ProjectPolicy)->view($viewer, $project),
            AccessDecision::can($viewer, Capability::PROJECTS_VIEW, $project),
            'Viewer view parity.'
        );
        $this->assertSame(
            (new ProjectPolicy)->update($viewer, $project),
            AccessDecision::can($viewer, Capability::PROJECTS_EDIT, $project),
            'Viewer update parity.'
        );
        $this->assertSame(
            (new ProjectPolicy)->delete($viewer, $project),
            AccessDecision::can($viewer, Capability::PROJECTS_DELETE, $project),
            'Viewer delete parity.'
        );
        $this->assertSame(
            (new ProjectPolicy)->manageMembers($viewer, $project),
            AccessDecision::can($viewer, Capability::PROJECTS_MANAGE_MEMBERS, $project),
            'Viewer manageMembers parity.'
        );

        // ---- project_manager on the project ----
        $manager = $this->makePlainUser();
        $manager->assignProjectRole($project, ScopedRole::PROJECT_MANAGER);
        $this->mirrorProjectScopedGrant(
            user: $manager,
            roleKey: 'pilot_subset_manager_view',
            action: 'view',
            projectId: (int) $project->id,
        );
        $this->mirrorProjectScopedGrant(
            user: $manager,
            roleKey: 'pilot_subset_manager_edit',
            action: 'edit',
            projectId: (int) $project->id,
        );
        $this->mirrorProjectScopedGrant(
            user: $manager,
            roleKey: 'pilot_subset_manager_manage_members',
            action: 'manage_members',
            projectId: (int) $project->id,
        );

        $this->assertSame(
            (new ProjectPolicy)->view($manager, $project),
            AccessDecision::can($manager, Capability::PROJECTS_VIEW, $project),
            'Manager view parity.'
        );
        $this->assertSame(
            (new ProjectPolicy)->update($manager, $project),
            AccessDecision::can($manager, Capability::PROJECTS_EDIT, $project),
            'Manager update parity.'
        );
        $this->assertSame(
            (new ProjectPolicy)->manageMembers($manager, $project),
            AccessDecision::can($manager, Capability::PROJECTS_MANAGE_MEMBERS, $project),
            'Manager manageMembers parity.'
        );
        $this->assertSame(
            (new ProjectPolicy)->delete($manager, $project),
            AccessDecision::can($manager, Capability::PROJECTS_DELETE, $project),
            'Manager delete parity (engine spec: pm:delete:deny).'
        );

        // ---- cross-org user on the same project ----
        $foreign = User::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'department_id' => Department::factory()->create([
                'organization_id' => $this->otherOrg->id,
            ])->id,
        ]);

        $this->assertSame(
            (new ProjectPolicy)->view($foreign, $project),
            AccessDecision::can($foreign, Capability::PROJECTS_VIEW, $project),
            'Cross-org view parity (deny/deny).'
        );
        $this->assertSame(
            (new ProjectPolicy)->update($foreign, $project),
            AccessDecision::can($foreign, Capability::PROJECTS_EDIT, $project),
            'Cross-org update parity (deny/deny).'
        );
        $this->assertSame(
            (new ProjectPolicy)->delete($foreign, $project),
            AccessDecision::can($foreign, Capability::PROJECTS_DELETE, $project),
            'Cross-org delete parity (deny/deny).'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Make a project in the test's primary org + dept.
     */
    private function makeProject(): Project
    {
        return Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
        ]);
    }

    /**
     * Make a plain (no roles) user in the test's primary org + dept.
     */
    private function makePlainUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
        ]);
    }

    /**
     * Mirror a project-scoped legacy grant on the new path. This is the
     * canonical pattern the plan documents for Phase 2 backfill: a
     * role + role_permission (resource=Project, action=$action) +
     * assignment scope='all' + an authorization_record_rules row whose
     * domain_json is `eq` on `id = $projectId`. Without the record rule
     * the new path would widen access to every project, so this helper
     * enforces that narrowing is always present.
     */
    private function mirrorProjectScopedGrant(
        User $user,
        string $roleKey,
        string $action,
        int $projectId,
    ): void {
        $resource = $this->makeResource(Project::class);
        $role = $this->makeRole($roleKey);

        $this->attachPermission($role, $resource, $action);

        // 'all' is CHECK-constrained to scope_id IS NULL.
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
        ]);

        AuthorizationRecordRule::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => $action,
            'domain_json' => [
                'operator' => 'eq',
                'column' => 'id',
                'value' => $projectId,
            ],
            'enabled' => true,
            'priority' => 10,
        ]);

        // The model `saved` hooks flush AccessDecision's cache, but we also
        // flush explicit caches here in case a future refactor skips the hook.
        AccessDecision::flushCache();
    }

    private function makeResource(string $fqcn): AuthorizationResource
    {
        return AuthorizationResource::firstOrCreate(
            ['key' => $fqcn],
            ['label' => class_basename($fqcn)],
        );
    }

    private function makeRole(string $name): AuthorizationRole
    {
        return AuthorizationRole::firstOrCreate(
            ['name' => $name],
            ['label' => $name],
        );
    }

    private function attachPermission(
        AuthorizationRole $role,
        AuthorizationResource $resource,
        string $action,
    ): void {
        AuthorizationRolePermission::firstOrCreate([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => $action,
        ]);
    }

    /**
     * Mirror ProjectPolicyOracleTest::seedProjectScopeDefinitions(): seed
     * the project scope type + manager/member/viewer scoped_role_definitions
     * + the org-scope admin/viewer definitions used by grantedViaOrgFunctionalRole.
     * Force-fill via DB::table because of LR-103's legacy NOT NULL columns.
     */
    private function seedProjectScopeDefinitions(): void
    {
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_PROJECT],
            [
                'label_ar' => 'project',
                'label_en' => 'Project',
                'model_class' => Project::class,
                'supports_hierarchy' => true,
                'supports_expiry' => false,
                'is_active' => true,
                'sort_order' => 10,
            ]
        );

        $now = now();
        $definitions = [
            [
                'name' => 'project_manager',
                'display_name' => 'Project Manager',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MANAGER,
                'label_ar' => 'Project Manager',
                'label_en' => 'Project Manager',
                'is_admin_role' => false,
                'permissions' => json_encode([
                    Capability::PROJECTS_VIEW,
                    Capability::PROJECTS_EDIT,
                    Capability::PROJECTS_MANAGE_MEMBERS,
                    Capability::PROJECTS_ASSIGN_ROLES,
                ]),
                'is_active' => true,
                'sort_order' => 1,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'name' => 'project_member',
                'display_name' => 'Project Member',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_MEMBER,
                'label_ar' => 'Member',
                'label_en' => 'Member',
                'is_admin_role' => false,
                'permissions' => json_encode([Capability::PROJECTS_VIEW]),
                'is_active' => true,
                'sort_order' => 2,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            [
                'name' => 'project_viewer',
                'display_name' => 'Project Viewer',
                'scope_type' => ScopedRole::SCOPE_PROJECT,
                'scope_type_id' => $scopeType->id,
                'role_key' => ScopedRole::PROJECT_VIEWER,
                'label_ar' => 'Viewer',
                'label_en' => 'Viewer',
                'is_admin_role' => false,
                'permissions' => json_encode([Capability::PROJECTS_VIEW]),
                'is_active' => true,
                'sort_order' => 3,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        ];

        foreach ($definitions as $def) {
            DB::table('scoped_role_definitions')->updateOrInsert(
                ['scope_type_id' => $def['scope_type_id'], 'role_key' => $def['role_key']],
                $def,
            );
        }
    }
}
