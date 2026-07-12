<?php

namespace Tests\Feature\Api\Core;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * OrganizationController HTTP-level coverage.
 *
 * Routes (app/Modules/Core/Routes/api.php):
 *   GET    /api/organizations                index   (capability-gated)
 *   POST   /api/organizations                store   (super_admin only)
 *   GET    /api/organizations/{org}          show    (capability-gated)
 *   PUT    /api/organizations/{org}          update  (super_admin only)
 *   PATCH  /api/organizations/{org}          update  (super_admin only)
 *   DELETE /api/organizations/{org}          destroy (super_admin only)
 *
 * The CRUD routes are wrapped in `role:super_admin` middleware; index/show
 * also enforce Capability::CORE_VIEW_ORGANIZATIONS inside the controller.
 * Tenant creation, switching, and deletion are super-admin-only by design —
 * non-super roles must never reach the controller.
 */
class OrganizationControllerTest extends TestCase
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

    private function makeUser(?Organization $org, ?string $role = null, ?Department $dept = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $dept?->id,
            'is_active' => true,
        ]);
        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    // ========== Unauthenticated 401 ==========

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/organizations')->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson("/api/organizations/{$this->orgA->id}")->assertStatus(401);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/organizations', ['name' => 'X', 'code' => 'X'])->assertStatus(401);
    }

    public function test_update_requires_authentication(): void
    {
        $this->putJson("/api/organizations/{$this->orgA->id}", ['name' => 'X'])->assertStatus(401);
    }

    public function test_destroy_requires_authentication(): void
    {
        $this->deleteJson("/api/organizations/{$this->orgA->id}")->assertStatus(401);
    }

    // ========== Non-super-admin denial (403) ==========

    public function test_admin_role_cannot_store_organization(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/organizations', ['name' => 'Acme', 'code' => 'ACME'])
            ->assertStatus(403);
    }

    public function test_admin_role_cannot_update_organization(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/organizations/{$this->orgA->id}", ['name' => 'Hacked'])
            ->assertStatus(403);
    }

    public function test_admin_role_cannot_destroy_organization(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/organizations/{$this->orgA->id}")
            ->assertStatus(403);
    }

    public function test_viewer_role_cannot_index_organizations_without_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer', $this->deptA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/organizations')
            ->assertStatus(403);
    }

    public function test_viewer_role_cannot_show_organization_without_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer', $this->deptA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/organizations/{$this->orgA->id}")
            ->assertStatus(403);
    }

    // ========== Happy path: super_admin ==========

    public function test_super_admin_can_index_organizations(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/organizations')
            ->assertStatus(200);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_super_admin_can_show_organization(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/organizations/{$this->orgA->id}")
            ->assertStatus(200);

        $this->assertSame($this->orgA->id, $response->json('data.id'));
        $this->assertArrayHasKey('users_count', $response->json('data'));
        $this->assertArrayHasKey('projects_count', $response->json('data'));
    }

    public function test_super_admin_can_store_organization_and_logs_activity(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/organizations', [
                'name' => 'New Tenant',
                'code' => 'NEWTENANT',
                'email' => 'tenant@example.com',
                'is_active' => true,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('organizations', [
            'name' => 'New Tenant',
            'code' => 'NEWTENANT',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityLog::ACTION_CREATED,
            'loggable_type' => Organization::class,
            'loggable_id' => $response->json('data.id'),
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_can_update_organization_and_logs_old_and_new_values(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/organizations/{$this->orgA->id}", [
                'name' => 'Renamed Org',
            ])
            ->assertStatus(200);

        $this->assertSame('Renamed Org', $response->json('data.name'));
        $this->assertDatabaseHas('organizations', ['id' => $this->orgA->id, 'name' => 'Renamed Org']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityLog::ACTION_UPDATED,
            'loggable_type' => Organization::class,
            'loggable_id' => $this->orgA->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_can_patch_organization(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/organizations/{$this->orgA->id}", ['is_active' => false])
            ->assertStatus(200);

        $this->assertDatabaseHas('organizations', ['id' => $this->orgA->id, 'is_active' => false]);
    }

    public function test_super_admin_can_destroy_empty_organization(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/organizations/{$this->orgA->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('organizations', ['id' => $this->orgA->id]);
    }

    // ========== In-use guard ==========

    public function test_destroy_organization_with_users_is_rejected(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');
        // orgA already has a department + user attached
        $this->makeUser($this->orgA, 'admin', $this->deptA);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/organizations/{$this->orgA->id}")
            ->assertStatus(422);

        $this->assertSame(1, $response->json('users_count'));
        $this->assertDatabaseHas('organizations', ['id' => $this->orgA->id]);
    }

    // ========== Capability gate ==========

    public function test_non_super_admin_cannot_reach_controller_even_with_capability(): void
    {
        // The route group uses `role:super_admin` middleware that blocks
        // every non-super-admin before any controller body runs.
        // The Capability::CORE_VIEW_ORGANIZATIONS check inside the controller
        // is therefore only reachable for super_admin (who always passes it).
        // This test pins the design: tenant CRUD is super-admin-only.
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);
        $this->grantEngineCapability($admin, Capability::CORE_VIEW_ORGANIZATIONS);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/organizations')
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/organizations/{$this->orgA->id}")
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/organizations', ['name' => 'X', 'code' => 'X2'])
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/organizations/{$this->orgA->id}", ['name' => 'X'])
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/organizations/{$this->orgA->id}")
            ->assertStatus(403);
    }
}
