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
 * HTTP-level coverage for user/security + role-tree authz endpoints that
 * today have weak or missing tests:
 *
 *   GET  /api/users/{user}/security         (UserPolicy::view)
 *   GET  /api/users/stats                   (UserPolicy::viewAny + visibility)
 *   GET  /api/authorization-role-assignments/user/{user}
 *   GET  /api/authorization-role-assignments/audit-logs (Capability::AUDIT_VIEW)
 *
 * The previous registration-approval tests at the bottom of this file were
 * removed in the simplified-registration cutover (the routes + controller +
 * capability they covered no longer exist). See the new
 * tests/Feature/Auth/RegistrationInvariantsTest for the corresponding
 * "endpoint is gone" assertions.
 */
class UserSecurityAuthzTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function makeUser(?Organization $org, ?Department $dept = null, ?string $role = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $dept?->id ?? ($org?->id === $this->orgA->id ? $this->deptA->id : $this->deptB->id),
            'is_active' => true,
        ]);
        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    // ========== GET /api/users/{user}/security ==========

    public function test_admin_can_view_security_status_of_same_org_user(): void
    {
        $admin = $this->makeUser($this->orgA, null, 'admin');
        $this->grantEngineCapability($admin, Capability::USERS_VIEW);
        $target = $this->makeUser($this->orgA);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$target->id}/security")
            ->assertStatus(200)
            ->assertJsonStructure(['security']);
    }

    public function test_admin_cannot_view_security_status_of_cross_org_user(): void
    {
        $admin = $this->makeUser($this->orgA, null, 'admin');
        $this->grantEngineCapability($admin, Capability::USERS_VIEW);
        $foreign = $this->makeUser($this->orgB);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$foreign->id}/security")
            ->assertStatus(403);
    }

    public function test_viewer_without_users_view_cannot_see_security_status(): void
    {
        $viewer = $this->makeUser($this->orgA, null, 'viewer');
        $target = $this->makeUser($this->orgA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/users/{$target->id}/security")
            ->assertStatus(403);
    }

    // ========== GET /api/users/stats ==========

    public function test_non_admin_gets_403_on_stats(): void
    {
        $viewer = $this->makeUser($this->orgA, null, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/users/stats')
            ->assertStatus(403);
    }

    public function test_stats_are_org_scoped(): void
    {
        $admin = $this->makeUser($this->orgA, null, 'admin');
        $this->grantEngineCapability($admin, Capability::USERS_VIEW);

        // Seed extra users in each org
        $this->makeUser($this->orgA);  // +1 in orgA beyond admin
        $this->makeUser($this->orgB);  // +1 in orgB
        $this->makeUser($this->orgB);  // +1 in orgB

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/users/stats')
            ->assertStatus(200);

        // orgA admin should see exactly the users in orgA (admin + the one we made)
        $total = $response->json('total');
        $this->assertGreaterThanOrEqual(2, $total, 'must include admin + 1 same-org user');
        $this->assertSame(2, $total, 'must NOT count orgB users');
    }

    // ========== GET /api/authorization-role-assignments/user/{user} ==========

    public function test_member_cannot_view_other_users_scoped_roles(): void
    {
        $member = $this->makeUser($this->orgA, null, 'viewer');
        $target = $this->makeUser($this->orgA);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}")
            ->assertStatus(403);
    }

    public function test_user_can_view_own_scoped_roles(): void
    {
        $user = $this->makeUser($this->orgA);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$user->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_super_admin_can_view_any_users_scoped_roles(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $foreign = $this->makeUser($this->orgB);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$foreign->id}")
            ->assertStatus(200);
    }

    public function test_same_org_admin_has_view_users_via_spatie_and_passes(): void
    {
        // The admin Spatie role is granted Permission::VIEW_USERS (legacy
        // gate). AuthorizationRoleAssignmentController::userAssignments still uses
        // `$currentUser->can('view_users')` directly, so admin reaches the
        // response. This test pins that legacy behavior — a future task
        // should migrate the check to the engine (USERS_VIEW capability).
        $admin = $this->makeUser($this->orgA, null, 'admin');
        $target = $this->makeUser($this->orgA);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}")
            ->assertStatus(200);
    }

    public function test_cross_org_admin_with_view_users_is_blocked_by_org_check(): void
    {
        $admin = $this->makeUser($this->orgA, null, 'admin');
        $this->grantEngineCapability($admin, Capability::USERS_VIEW);
        $foreign = $this->makeUser($this->orgB);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$foreign->id}")
            ->assertStatus(403);
    }

    public function test_same_org_user_with_only_engine_users_view_can_access_scoped_roles(): void
    {
        // Engine-only USERS_VIEW (no Spatie 'view_users' permission on a
        // viewer-role user). userAssignments must authorize through
        // UserPolicy::view, not the legacy `$user->can('view_users')` check.
        // RED for the legacy path: a viewer lacks Permission::VIEW_USERS, so
        // `$user->can('view_users')` returns false => 403 (should be 200).
        $viewer = $this->makeUser($this->orgA, null, 'viewer');
        $this->grantEngineCapability($viewer, Capability::USERS_VIEW);
        $target = $this->makeUser($this->orgA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_same_org_user_with_only_engine_users_view_can_access_access_summary(): void
    {
        // accessSummary must authorize through UserPolicy::view (engine
        // USERS_VIEW + same-org), not the legacy Spatie `view_users` check.
        $viewer = $this->makeUser($this->orgA, null, 'viewer');
        $this->grantEngineCapability($viewer, Capability::USERS_VIEW);
        $target = $this->makeUser($this->orgA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}/access-summary")
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['functional_roles', 'scoped']]);
    }

    public function test_cluster_directory_grant_alone_does_not_widen_user_endpoints_cross_org(): void
    {
        // Holding CLUSTER_TREE_VIEW (cluster directory primitive) but NOT
        // USERS_VIEW must not authorize cross-org reads on userAssignments
        // or accessSummary. UserPolicy::view demands the module capability;
        // viewDirectory() is the dedicated cluster seam, not wired here.
        $actor = $this->makeUser($this->orgA, null, 'viewer');
        $this->grantEngineCapability($actor, Capability::CLUSTER_TREE_VIEW);
        $foreign = $this->makeUser($this->orgB);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$foreign->id}")
            ->assertStatus(403);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$foreign->id}/access-summary")
            ->assertStatus(403);
    }

    // ========== GET /api/authorization-role-assignments/audit-logs ==========

    public function test_member_cannot_view_audit_logs(): void
    {
        $viewer = $this->makeUser($this->orgA, null, 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/authorization-role-assignments/audit-logs')
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_view_audit_logs(): void
    {
        $this->getJson('/api/authorization-role-assignments/audit-logs')->assertStatus(401);
    }

    public function test_user_with_audit_view_can_access_audit_logs(): void
    {
        $admin = $this->makeUser($this->orgA, null, 'admin');
        $this->grantEngineCapability($admin, Capability::AUDIT_VIEW);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/authorization-role-assignments/audit-logs')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_audit_logs_are_org_scoped_for_non_super_admin(): void
    {
        $adminA = $this->makeUser($this->orgA, null, 'admin');
        $this->grantEngineCapability($adminA, Capability::AUDIT_VIEW);

        $targetA = $this->makeUser($this->orgA);
        $targetB = $this->makeUser($this->orgB);

        // Seed canonical assignment audit entries targeting each organization.
        $logA = DB::table('authorization_assignment_audits')->insertGetId([
            'actor_id' => $adminA->id, 'event' => 'canonical_assignment_assigned',
            'target_user_id' => $targetA->id, 'scope_type' => 'organization',
            'scope_id' => $this->orgA->id,
            'role' => 'viewer', 'created_at' => now(),
        ]);
        $logB = DB::table('authorization_assignment_audits')->insertGetId([
            'actor_id' => $adminA->id, 'event' => 'canonical_assignment_assigned',
            'target_user_id' => $targetB->id, 'scope_type' => 'organization',
            'scope_id' => $this->orgB->id,
            'role' => 'viewer', 'created_at' => now(),
        ]);

        $response = $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/authorization-role-assignments/audit-logs')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($logA, $ids);
        $this->assertNotContains($logB, $ids, 'orgA admin must not see orgB audit entries');
        $response->assertJsonMissingPath('data.0.target_user_id');
    }
}
