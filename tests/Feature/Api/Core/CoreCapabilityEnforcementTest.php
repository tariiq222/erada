<?php

namespace Tests\Feature\Api\Core;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Capability enforcement tests for engine-readable capabilities that today
 * have ZERO HTTP-level coverage:
 *
 *   core.view_organizations — OrganizationController + ScopeTypeController
 *   core.assign_roles       — RoleController::assignToUser
 *   audit.view              — ActivityLogController + ScopedRoleController
 *   audit.export            — ActivityLogController::export
 *
 * The test that simply asserts "with capability → 200 / without → 403" is
 * too thin to catch the split-brain problems this codebase has already
 * suffered from (memory: pre-deploy-hardening-broke-authz). Each test here
 * asserts BOTH halves — grant the capability, the route must allow; revoke
 * it, the route must deny — so a single regression in either direction
 * lights up the suite.
 *
 * NOTE on CORE_VIEW_ORGANIZATIONS + CORE_ASSIGN_ROLES: the only HTTP routes
 * that check these are wrapped in `role:super_admin` middleware (see
 * app/Modules/Core/Routes/api.php), so the deny path is unreachable from
 * any non-super-admin request. We assert that here as a design-pin:
 * those capabilities are wired but inert until a route that uses them is
 * moved out of the super_admin-only group.
 */
class CoreCapabilityEnforcementTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Department $deptA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function makeUser(?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    // ========== core.view_organizations — currently unreachable ==========

    public function test_core_view_organizations_is_unreachable_due_to_super_admin_middleware(): void
    {
        // The only routes that check CORE_VIEW_ORGANIZATIONS (Organization
        // and ScopeType CRUD) are wrapped in `role:super_admin` middleware.
        // A non-super_admin user is rejected at the middleware layer with
        // 403 before the engine check fires. We pin this here so a future
        // refactor that opens one of these routes to a non-super role is
        // caught: the test must be updated to grant the capability and
        // assert 200 instead.
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/organizations')
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/scope-types')
            ->assertStatus(403);

        // Even with the capability granted, the middleware still wins.
        $this->grantEngineCapability($admin, Capability::CORE_VIEW_ORGANIZATIONS);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/organizations')
            ->assertStatus(403);
    }

    // ========== core.assign_roles — currently unreachable ==========

    public function test_core_assign_roles_is_unreachable_due_to_super_admin_middleware(): void
    {
        // POST /api/roles/assign is the only endpoint that checks
        // CORE_ASSIGN_ROLES, and it is wrapped in `role:super_admin`.
        // Even a super_admin cannot assign super_admin to themselves via
        // this endpoint without the right state. We pin the gap: a non-super
        // is 403'd at the middleware, and the capability is never consulted.
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('viewer');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['viewer'],
            ])
            ->assertStatus(403);
    }

    // ========== audit.view — REACHABLE on /api/activity-logs index ==========

    public function test_audit_view_denies_viewer_without_capability(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/activity-logs')
            ->assertStatus(403);
    }

    public function test_audit_view_grants_access_to_activity_log_index(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantEngineCapability($admin, Capability::AUDIT_VIEW);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/activity-logs')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_activity_log_show_allows_same_org_user_with_audit_view_capability(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantEngineCapability($admin, Capability::AUDIT_VIEW);

        $log = DB::table('activity_logs')->insertGetId([
            'user_id' => $admin->id,
            'action' => 'test_event',
            'description' => 'wave1 fixture',
            'loggable_type' => User::class,
            'loggable_id' => $admin->id,
            'organization_id' => $admin->organization_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/activity-logs/{$log}")
            ->assertStatus(200);
    }

    public function test_activity_log_show_denies_same_org_user_without_audit_view_capability(): void
    {
        $viewer = $this->makeUser('viewer');

        $log = DB::table('activity_logs')->insertGetId([
            'user_id' => $viewer->id,
            'action' => 'test_event',
            'description' => 'wave1 fixture',
            'loggable_type' => User::class,
            'loggable_id' => $viewer->id,
            'organization_id' => $viewer->organization_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/activity-logs/{$log}")
            ->assertStatus(403);
    }

    // ========== audit.export — REACHABLE on /api/activity-logs/export ==========

    public function test_audit_export_denies_without_capability(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/activity-logs/export')
            ->assertStatus(403);
    }

    public function test_audit_export_grants_access_to_viewer_with_capability(): void
    {
        $viewer = $this->makeUser('viewer');
        $this->grantEngineCapability($viewer, Capability::AUDIT_EXPORT);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/activity-logs/export')
            ->assertStatus(200);
    }

    public function test_audit_export_does_not_grant_index_view_access(): void
    {
        // Separating VIEW and EXPORT means a user with only AUDIT_EXPORT
        // can stream the CSV but cannot read the JSON index. Pin this
        // split-brain guarantee.
        $viewer = $this->makeUser('viewer');
        $this->grantEngineCapability($viewer, Capability::AUDIT_EXPORT);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/activity-logs/export')
            ->assertStatus(200);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/activity-logs')
            ->assertStatus(403);
    }

    public function test_audit_view_does_not_grant_export_access(): void
    {
        // Reverse direction: AUDIT_VIEW alone must not unlock the CSV.
        $viewer = $this->makeUser('viewer');
        $this->grantEngineCapability($viewer, Capability::AUDIT_VIEW);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/activity-logs')
            ->assertStatus(200);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/activity-logs/export')
            ->assertStatus(403);
    }

    // ========== Unauthenticated ==========

    public function test_unauthenticated_cannot_access_audit_log_endpoints(): void
    {
        $this->getJson('/api/activity-logs')->assertStatus(401);
        $this->getJson('/api/activity-logs/export')->assertStatus(401);
        $this->getJson('/api/activity-logs/1')->assertStatus(401);
    }
}
