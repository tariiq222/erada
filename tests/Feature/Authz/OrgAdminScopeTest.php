<?php

namespace Tests\Feature\Authz;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\UserPolicy;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Policies\DepartmentPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CanonicalAuthorizationFixtures;
use Tests\TestCase;

/**
 * OrgAdminScopeTest — CSD-CA23078-CORE-007 / Task 6.
 *
 * Regression contract for the curated organization-scoped `admin` role.
 *
 * The baseline (`09bd459`) behavior this test pins down, verified
 * empirically:
 *
 *  1. PIVOT ISOLATION — the admin role's `authorization_role_permissions`
 *     pivot set MUST NOT contain the engine primitives that gate
 *     cluster-aware or super_admin-only operations. The four
 *     primitives are:
 *       - (Organization, view)             → core.cluster_tree.view
 *       - (Organization, manage)           → core.cluster_tree.manage
 *       - (Organization, export)           → core.cluster_tree.export
 *       - (Organization, assign_roles)     → core.assign_roles
 *       - (Organization, view_organizations) → core.view_organizations
 *     Each is resolved by CapabilityToAuthorizationRolePermission::map()
 *     (this file documents the resolution at runtime) and the pivot
 *     absence is structural — the canonical pivot is the only data the
 *     engine reads for the gate.
 *
 *  2. CROSS-ORG CLUSTER ISOLATION — even with the actor's
 *     `organization_id` set up as an ancestor of the target's
 *     `organization_id` (cluster/hospital tree), the cluster-tree rescue
 *     branch in `AccessDecision::canonicalClusterTreeGrant()` MUST NOT
 *     fire for an admin actor because the admin role has no
 *     (Organization, view) pivot. The denial layer is
 *     `org_isolation_denied` or `none`, NEVER `cluster_tree_rescue`.
 *     This is the regression contract for "cannot obtain cross-org /
 *     cluster widening via core.cluster_tree.view".
 *
 *  3. POLICY CROSS-ORG ISOLATION — `UserPolicy::view/update/delete` and
 *     `DepartmentPolicy::view/update/delete` MUST NOT admit a cross-org
 *     target for an actor whose canonical assignment is the
 *     organization-scoped `admin` role. The UserPolicy has an explicit
 *     `belongsToUserOrganization()` helper because User is not
 *     ScopeAware; DepartmentPolicy uses the engine's
 *     `sameOrganization()` gate via `extractOrganizationId()`.
 *
 *  4. HTTP CROSS-ORG ISOLATION — `GET /api/users/{crossOrgUser}`,
 *     `DELETE /api/users/{crossOrgUser}`, and the cross-org
 *     `GET /api/authorization-role-assignments/user/{crossOrgUser}`
 *     MUST return 403 when the actor holds only the curated
 *     organization-scoped admin role. The directory index
 *     `GET /api/users` MUST return only same-org users via the
 *     `UserOrganizationScope` filter.
 *
 *  5. CORE_ASSIGN_ROLES PIVOT ABSENCE — the admin role's pivot set
 *     MUST NOT include the (Organization, assign_roles) row. The
 *     RoleController and AuthorizationAssignmentService apply
 *     privilege-escalation guards (CanonicalAuthorizationAssignmentActorGuard
 *     + `guardRoleCapabilityMutation`) so admin CANNOT mutate an
 *     admin-role definition or grant core.assign_roles to another role,
 *     even though the engine's `is_admin_role` shortcut
 *     (`AccessDecision::canonicalGrant`) lets admin users satisfy
 *     `core.assign_roles` for null / same-org targets via the
 *     `canonical_assignment` layer.
 *
 * TDD posture: these are REGRESSION guards (template: CSD pattern),
 * not bug-fix tests. Each assertion locks in the existing safe
 * behavior on the `09bd459` baseline. A future change that loosens
 * any of these contracts surfaces here as a RED test.
 *
 * The empirical ground truth that motivated the test design (probe
 * results, not speculation):
 *
 *   AccessDecision::whyCan(adminActor, X, $target) yields:
 *     X = CORE_ASSIGN_ROLES, target=null       -> canonical_assignment / granted
 *     X = CORE_ASSIGN_ROLES, target=cross-org  -> org_isolation_denied / denied
 *     X = CLUSTER_TREE_VIEW,  target=null      -> canonical_assignment / granted
 *     X = CLUSTER_TREE_VIEW,  target=same-org  -> canonical_assignment / granted
 *     X = CLUSTER_TREE_VIEW,  target=cross-org -> org_isolation_denied / denied
 *     X = CLUSTER_TREE_VIEW,  target=child-org-hierarchy-satisfied -> denied
 *     X = CLUSTER_TREE_VIEW,  target=child-org-hierarchy-satisfied -> layer != cluster_tree_rescue
 *
 * That shape is what the test suite locks in.
 */
class OrgAdminScopeTest extends TestCase
{
    use CanonicalAuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        AccessDecision::flushCache();
    }

    // ======================================================================
    // 1. Pivot isolation (data layer)
    // ======================================================================

    /**
     * The Organization-resource pivots that resolve to
     * core.cluster_tree.{view,manage,export} /
     * core.assign_roles / core.view_organizations MUST NOT appear on the
     * admin role. They are reserved for cluster-aware or super_admin
     * assignments and would otherwise widen the admin's reach if the
     * engine shortcut were ever removed or restricted.
     */
    public function test_admin_role_pivots_exclude_cluster_tree_primitives_and_super_admin_exclusive_caps(): void
    {
        $admin = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();

        $orgResourceId = AuthorizationResource::query()
            ->where('key', Organization::class)
            ->value('id');

        $this->assertNotNull(
            $orgResourceId,
            'Organization AuthorizationResource row must exist after seeding.',
        );

        $forbidden = [
            'view' => 'core.cluster_tree.view',
            'manage' => 'core.cluster_tree.manage',
            'export' => 'core.cluster_tree.export',
            'assign_roles' => 'core.assign_roles',
            'view_organizations' => 'core.view_organizations',
        ];

        foreach ($forbidden as $action => $capability) {
            $present = $admin->permissions()
                ->where('authorization_resource_id', $orgResourceId)
                ->where('action', $action)
                ->exists();

            $this->assertFalse(
                $present,
                "admin role MUST NOT carry (Organization, {$action}) — that pivot resolves to {$capability}, "
                .'reserved for cluster-aware or super_admin-exclusive grants.',
            );
        }
    }

    /**
     * Lock down the (resource, action) pair that each engine primitive
     * actually resolves to. The CapabilityToAuthorizationRolePermission
     * table is the single source of truth for the mapping; if any of
     * these rows change, every engine gate changes with them, so the
     * test pins the resolution here.
     */
    public function test_capability_to_authorization_role_permission_resolves_cluster_and_core_caps(): void
    {
        $expectations = [
            Capability::CLUSTER_TREE_VIEW => ['resource' => Organization::class, 'action' => 'view'],
            Capability::CLUSTER_TREE_MANAGE => ['resource' => Organization::class, 'action' => 'manage'],
            Capability::CLUSTER_TREE_EXPORT => ['resource' => Organization::class, 'action' => 'export'],
            Capability::CORE_ASSIGN_ROLES => ['resource' => Organization::class, 'action' => 'assign_roles'],
            Capability::CORE_VIEW_ORGANIZATIONS => ['resource' => Organization::class, 'action' => 'view_organizations'],
        ];

        foreach ($expectations as $capability => $expected) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            $this->assertNotNull($mapping, "{$capability} must resolve through CapabilityToAuthorizationRolePermission::map().");
            $this->assertSame($expected['resource'], $mapping['resource']);
            $this->assertSame($expected['action'], $mapping['action']);
        }
    }

    // ======================================================================
    // 2. Cross-org cluster isolation — engine layer
    // ======================================================================

    /**
     * Even with the actor in a parent cluster org and the target in a
     * child hospital org, the cluster_tree rescue branch MUST NOT fire
     * for an admin actor because:
     *
     *   - the admin role has no (Organization, view) pivot;
     *   - `canonicalClusterTreeGrant()` resolves roleIds via
     *     `AuthorizationRolePermission::where('action', 'view')` and
     *     finds zero matching roles;
     *   - the rescue branch returns null, the sameOrganization() gate
     *     fails closed, and the trace surfaces `org_isolation_denied`
     *     (NOT `cluster_tree_rescue`).
     */
    public function test_admin_user_cannot_use_cluster_tree_view_to_read_descendant_org_target(): void
    {
        [$cluster, $hospital] = $this->makeCluster();

        $adminUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin(
            $adminUser,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            (int) $cluster->id,
        );
        AccessDecision::flushCache();

        $hospitalUser = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);

        // Precondition: ancestor walk satisfied — the geometric precondition
        // for the rescue branch is in place. The test then verifies the
        // rescue does NOT fire because the admin role lacks the pivot.
        $this->assertContains(
            (int) $cluster->id,
            $hospitalUser->organization_id === $hospital->id ? $hospital->ancestorIds() : [],
            'precondition: cluster must be an ancestor of the hospital so the cluster_tree rescue precondition is geometrically satisfied.',
        );

        $this->assertFalse(
            AccessDecision::can($adminUser, Capability::CLUSTER_TREE_VIEW, $hospitalUser),
            'cluster-tree rescue MUST deny for an admin actor even with a satisfied ancestor walk — admin role has no (Organization, view) pivot.',
        );

        $trace = AccessDecision::whyCan($adminUser, Capability::CLUSTER_TREE_VIEW, $hospitalUser);
        $this->assertFalse(
            $trace['granted'],
            'whyCan() MUST report denied for an admin actor attempting CLUSTER_TREE_VIEW on a descendant-org target.',
        );
        $this->assertNotSame(
            'cluster_tree_rescue',
            $trace['layer'],
            'cluster_tree_rescue must NEVER be the denial layer for an admin actor — the admin role lacks the (Organization, view) pivot the rescue requires.',
        );
    }

    /**
     * Same-organizational-tree isolated-by-direction invariant: a child-org
     * actor holding the admin role on the child org cannot reach up to the
     * cluster ancestor via cluster_tree. The ancestor walk is
     * one-directional (child → parent is not in ancestorIds() of the
     * cluster). The trace still denies at org_isolation_denied, NOT at
     * cluster_tree_rescue, because the admin has no pivot anyway.
     */
    public function test_admin_user_in_child_org_cannot_use_cluster_tree_view_to_read_parent_cluster_org(): void
    {
        [$cluster, $hospital] = $this->makeCluster();

        $childAdmin = User::factory()->create([
            'organization_id' => $hospital->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin(
            $childAdmin,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            (int) $hospital->id,
        );
        AccessDecision::flushCache();

        $clusterUser = User::factory()->create([
            'organization_id' => $cluster->id,
            'is_active' => true,
        ]);

        $this->assertFalse(
            AccessDecision::can($childAdmin, Capability::CLUSTER_TREE_VIEW, $clusterUser),
            'child admin MUST NOT reach the parent cluster org — ancestor walk is one-directional.',
        );

        $trace = AccessDecision::whyCan($childAdmin, Capability::CLUSTER_TREE_VIEW, $clusterUser);
        $this->assertFalse($trace['granted']);
        $this->assertNotSame(
            'cluster_tree_rescue',
            $trace['layer'],
            'cluster_tree_rescue must never be the layer for an admin actor in the child org — admin role has no (Organization, view) pivot.',
        );
    }

    /**
     * Cross-sibling isolation: two unrelated orgs at the same cluster
     * depth (or anywhere else) — an admin of org A must never widen to
     * org B's user via the cluster_tree rescue. The rescue precondition
     * (ancestor walk) is structurally impossible between siblings, so
     * denial is via `org_isolation_denied`, and the trace must never
     * surface `cluster_tree_rescue`.
     */
    public function test_admin_user_cannot_obtain_cluster_tree_view_for_cross_org_user_via_rescue_branch(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);

        $this->assertFalse(
            AccessDecision::can($adminUser, Capability::CLUSTER_TREE_VIEW, $crossOrgUser),
            'cross-org cluster_tree.view MUST deny — sibling isolation.',
        );

        $trace = AccessDecision::whyCan($adminUser, Capability::CLUSTER_TREE_VIEW, $crossOrgUser);
        $this->assertFalse($trace['granted']);
        $this->assertNotSame(
            'cluster_tree_rescue',
            $trace['layer'],
            'cross-org cluster_tree.view trace must not surface cluster_tree_rescue for an admin actor.',
        );
    }

    /**
     * Engine cross-org denial for the other cluster primitives
     * (manage, export). Each is one of the three
     * `clusterTreePrimitiveCapabilities()` and the rescue branch's pivot
     * lookup must find no admin-role ids for any of them.
     */
    public function test_admin_user_cannot_obtain_cluster_tree_manage_or_export_for_cross_org_target(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);

        foreach ([Capability::CLUSTER_TREE_MANAGE, Capability::CLUSTER_TREE_EXPORT] as $capability) {
            $this->assertFalse(
                AccessDecision::can($adminUser, $capability, $crossOrgUser),
                "{$capability} MUST deny cross-org for an admin actor — pivot lookup fails for the rescue branch.",
            );

            $trace = AccessDecision::whyCan($adminUser, $capability, $crossOrgUser);
            $this->assertFalse($trace['granted']);
            $this->assertNotSame('cluster_tree_rescue', $trace['layer']);
        }
    }

    // ======================================================================
    // 3. Policy cross-org isolation — UserPolicy / DepartmentPolicy
    // ======================================================================

    /**
     * UserPolicy::view/update/delete gate cross-org access via an explicit
     * organization equality check (the User model is not ScopeAware, so
     * the engine's scope chain cannot resolve it). The admin's
     * organization-scoped role grants USERS_VIEW/USERS_EDIT/USERS_DELETE,
     * but the policy's same-org helper rejects cross-org targets.
     */
    public function test_admin_user_cannot_view_update_or_delete_a_user_in_a_different_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);

        $policy = new UserPolicy;

        $this->assertFalse(
            $policy->view($adminUser, $crossOrgUser),
            'UserPolicy::view MUST NOT admit a cross-org target for an org-scoped admin — the policy enforces strict same-org equality because User is not ScopeAware.',
        );
        $this->assertFalse(
            $policy->update($adminUser, $crossOrgUser),
            'UserPolicy::update MUST NOT admit a cross-org target for an org-scoped admin.',
        );
        $this->assertFalse(
            $policy->delete($adminUser, $crossOrgUser),
            'UserPolicy::delete MUST NOT admit a cross-org target for an org-scoped admin.',
        );

        // Sanity: same-org read is allowed, so the cross-org denial above
        // is the contract under test and not a blanket zero.
        $sameOrgUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $this->assertTrue(
            $policy->view($adminUser, $sameOrgUser),
            'precondition: UserPolicy::view admits same-org users for the org-scoped admin role.',
        );
    }

    /**
     * Department is ScopeAware, so the engine's sameOrganization() gate is
     * what enforces the boundary. The admin role grants
     * DEPARTMENTS_VIEW/EDIT/DELETE; the engine still denies cross-org
     * because the admin has no cross-org widening primitive.
     */
    public function test_admin_user_cannot_view_update_or_delete_a_department_in_a_different_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        $crossOrgDept = Department::factory()->create([
            'organization_id' => $orgB->id,
        ]);

        $policy = new DepartmentPolicy;

        $this->assertFalse(
            $policy->view($adminUser, $crossOrgDept),
            'DepartmentPolicy::view MUST deny a cross-org department for an org-scoped admin — engine sameOrganization() gate fails closed.',
        );
        $this->assertFalse(
            $policy->update($adminUser, $crossOrgDept),
            'DepartmentPolicy::update MUST deny a cross-org department for an org-scoped admin.',
        );
        $this->assertFalse(
            $policy->delete($adminUser, $crossOrgDept),
            'DepartmentPolicy::delete MUST deny a cross-org department for an org-scoped admin.',
        );

        // Sanity: same-org department is admitted, so the cross-org denial
        // is the contract under test and not a blanket zero.
        $sameOrgDept = Department::factory()->create([
            'organization_id' => $orgA->id,
        ]);
        $this->assertTrue(
            $policy->view($adminUser, $sameOrgDept),
            'precondition: DepartmentPolicy::view admits same-org departments for the org-scoped admin role.',
        );
    }

    // ======================================================================
    // 4. HTTP cross-org isolation — actual routes / controllers
    // ======================================================================

    /**
     * `GET /api/users/{user}` resolves to `UserController::show`, which
     * calls `$this->authorize('view', $user)`. UserPolicy::view fires
     * the `belongsToUserOrganization` helper that requires strict
     * same-org equality. Cross-org targets MUST surface a 403 (the
     * AuthorizationException is re-thrown by `handleException` so the
     * global handler emits the proper 403).
     */
    public function test_show_cross_org_user_as_org_admin_returns_403(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        Sanctum::actingAs($adminUser);

        $response = $this->getJson("/api/users/{$crossOrgUser->id}");
        $response->assertStatus(403);
    }

    /**
     * `DELETE /api/users/{user}` resolves to `UserController::destroy`
     * (route gated by `'throttle:delete'` only, no engine_capability
     * middleware), so the authz seam is `UserPolicy::delete` reached
     * through the controller's `$this->authorize('delete', $user)`.
     * Cross-org targets MUST surface a 403 — the policy's same-org
     * helper rejects cross-org even if `is_admin_role=true` granted
     * `users.delete` via the engine shortcut.
     */
    public function test_destroy_cross_org_user_as_org_admin_returns_403(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        Sanctum::actingAs($adminUser);

        $response = $this->deleteJson("/api/users/{$crossOrgUser->id}");
        $response->assertStatus(403);
    }

    /**
     * AuthorizationRoleAssignmentController::userAssignments also calls
     * `$this->authorize('view', $user)`, so the cross-org denial must
     * mirror the user-show endpoint.
     */
    public function test_authorization_role_assignments_user_as_org_admin_returns_403_for_cross_org_subject(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        Sanctum::actingAs($adminUser);

        $response = $this->getJson("/api/authorization-role-assignments/user/{$crossOrgUser->id}");
        $response->assertStatus(403);
    }

    /**
     * AuthorizationRoleAssignmentController::accessSummary uses the same
     * `authorize('view', $user)` seam — cross-org subject must surface
     * the same 403.
     */
    public function test_access_summary_for_cross_org_subject_as_org_admin_returns_403(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        Sanctum::actingAs($adminUser);

        $response = $this->getJson("/api/authorization-role-assignments/user/{$crossOrgUser->id}/access-summary");
        $response->assertStatus(403);
    }

    /**
     * `GET /api/users` is the canonical directory index. It calls
     * `authorize('viewAny', User::class)` and then `applyUserVisibility()`,
     * which scopes the listing to `UserOrganizationScope`. An admin actor
     * MUST receive only same-org users in the payload — never cross-org.
     */
    public function test_get_users_index_as_org_admin_returns_only_same_org_users(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $sameOrgColleague = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $crossOrgUser = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        Sanctum::actingAs($adminUser);

        $response = $this->getJson('/api/users');
        $response->assertOk();

        $payload = $response->json();
        $data = $payload['data'] ?? [];

        $emails = array_column($data, 'email');

        $this->assertContains(
            $adminUser->email,
            $emails,
            'precondition: admin should appear in their own org listing.',
        );
        $this->assertContains(
            $sameOrgColleague->email,
            $emails,
            'precondition: same-org colleague should appear in admin directory.',
        );
        $this->assertNotContains(
            $crossOrgUser->email,
            $emails,
            'OrgAdmin MUST NOT see cross-org users in the directory index — UserOrganizationScope gates the query to actor.organization_id.',
        );
    }

    /**
     * Department directory tree (`GET /api/hr/departments/tree`) must
     * also surface only same-org departments for the org-scoped admin.
     * Cross-org departments MUST NOT appear in the tree response.
     */
    public function test_get_departments_tree_as_org_admin_returns_only_same_org_departments(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);
        $sameOrgDept = Department::factory()->create([
            'organization_id' => $orgA->id,
        ]);
        $crossOrgDept = Department::factory()->create([
            'organization_id' => $orgB->id,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        Sanctum::actingAs($adminUser);

        $response = $this->getJson('/api/hr/departments/tree');
        $response->assertOk();

        $payload = $response->json();
        $data = $payload['data'] ?? $payload;

        // Walk every nested 'data' envelope in case the listing is paginated.
        $allIds = [];
        $walk = function ($node) use (&$walk, &$allIds): void {
            if (is_array($node)) {
                if (isset($node['id']) && is_numeric($node['id'])) {
                    $allIds[] = (int) $node['id'];
                }
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
            }
        };
        $walk($data);

        $this->assertContains(
            $sameOrgDept->id,
            $allIds,
            'precondition: same-org department must be in the tree.',
        );
        $this->assertNotContains(
            $crossOrgDept->id,
            $allIds,
            'OrgAdmin MUST NOT see cross-org departments in the tree — engine sameOrganization() gate fails closed at the resolver.',
        );
    }

    // ======================================================================
    // 5. Engine + controller privilege-escalation guard contract
    // ======================================================================

    /**
     * The RoleController's `guardRoleCapabilityMutation()` rejects
     * admin-role payload mutations OR any payload requesting
     * core.assign_roles unless the actor is a canonical super_admin.
     * Even though the admin user can satisfy core.assign_roles at the
     * engine layer (via the is_admin_role shortcut for null / same-org
     * targets), the role-definition mutation guard layer MUST deny the
     * request with the documented message.
     *
     * The test deliberately targets the controller's mutation guard by
     * attempting to update the admin role's pivot payload — which the
     * guard MUST reject because:
     *
     *   - `$existingRole->is_admin_role === true`
     *   - the actor is not canonical super_admin
     *
     * So even at the controller boundary, an admin user cannot widen
     * the curated pivot set.
     */
    public function test_admin_user_cannot_mutate_admin_role_definition_via_role_controller(): void
    {
        $org = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        $adminRole = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();

        Sanctum::actingAs($adminUser);

        $response = $this->putJson("/api/roles/{$adminRole->id}", [
            'name' => 'admin',
            'label' => 'Organization Admin',
            'scope_type' => 'organization',
            'capabilities' => [
                Capability::USERS_VIEW,
                Capability::DEPARTMENTS_VIEW,
                Capability::SETTINGS_VIEW,
                Capability::AUDIT_VIEW,
                Capability::CORE_ASSIGN_ROLES,
            ],
        ]);

        $response->assertStatus(403);
    }

    // ======================================================================
    // 6. Engine behavior documentation (baseline lock-in)
    // ======================================================================

    /**
     * Engine baseline: an admin user with a SCOPE_ORGANIZATION admin
     * assignment + null target + a non-requiresExplicitGrant capability
     * is satisfied via the is_admin_role shortcut in
     * `AccessDecision::canonicalGrant`. The layer is `canonical_assignment`,
     * NOT `cluster_tree_rescue`. This documents the current behavior so
     * any future change that affects the admin shortcut is caught by the
     * trace shape.
     */
    public function test_admin_user_satisfies_core_assign_roles_for_null_target_via_admin_shortcut(): void
    {
        $org = Organization::factory()->create();
        $adminUser = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($adminUser);
        AccessDecision::flushCache();

        $trace = AccessDecision::whyCan($adminUser, Capability::CORE_ASSIGN_ROLES);
        $this->assertTrue(
            $trace['granted'],
            'precondition: admin user satisfies CORE_ASSIGN_ROLES for null target via the is_admin_role shortcut.',
        );
        $this->assertSame(
            'canonical_assignment',
            $trace['layer'],
            'admin-shortcut grants must surface as canonical_assignment layer, not cluster_tree_rescue.',
        );
        $this->assertSame(
            'admin',
            $trace['role'],
            'admin-shortcut grants must surface as the admin role name.',
        );
    }

    // ======================================================================
    // 7. Helper factories
    // ======================================================================

    /**
     * Build a cluster/hospital organization tree.
     *
     * @return array{0: Organization, 1: Organization}
     */
    private function makeCluster(): array
    {
        $cluster = Organization::factory()->cluster()->create();
        $hospital = Organization::factory()->hospital()->childOf($cluster)->create();

        return [$cluster, $hospital];
    }
}
