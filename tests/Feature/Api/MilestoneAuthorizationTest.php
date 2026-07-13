<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization tests for MilestoneController.
 *
 * The controller authorizes all write operations via `$this->authorize('update', $project)`.
 * These tests cover:
 *   - Cross-project / cross-org denial (user from org B cannot touch org A milestones)
 *   - Project viewer/member without edit capability is denied on store/update/destroy
 *   - Org admin (is_admin_role=true scoped role) is allowed
 *   - super_admin bypasses everything
 */
class MilestoneAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'start_date' => now(),
            'end_date' => now()->addMonths(6),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(string $role, ?int $orgId = null): User
    {
        $org = $orgId ?? $this->org->id;
        $user = User::factory()->create([
            'organization_id' => $org,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, $role);

        return $user;
    }

    private function storePayload(): array
    {
        return [
            'project_id' => $this->project->id,
            'name' => 'Test Milestone',
            'duration_value' => 1,
            'duration_unit' => 'week',
        ];
    }

    // -------------------------------------------------------------------------
    // Cross-org denial: user from org B cannot access org A milestones
    // -------------------------------------------------------------------------

    public function test_cross_org_user_gets_403_on_store(): void
    {
        $orgB = Organization::factory()->create();
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => null,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($outsider, 'admin');

        $response = $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertForbidden();
    }

    public function test_cross_org_user_gets_403_on_update(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

        $orgB = Organization::factory()->create();
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => null,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($outsider, 'admin');

        $response = $this->actingAs($outsider, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Changed']);

        $response->assertForbidden();
    }

    public function test_cross_org_user_gets_403_on_destroy(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

        $orgB = Organization::factory()->create();
        $outsider = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => null,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($outsider, 'admin');

        $response = $this->actingAs($outsider, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Project viewer (no edit capability) is denied write operations
    // -------------------------------------------------------------------------

    public function test_project_viewer_cannot_store_milestone(): void
    {
        $viewer = $this->makeUser('viewer');
        $this->assignCanonicalRole($viewer, 'project_viewer', 'project', $this->project->id);

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertForbidden();
    }

    public function test_project_viewer_cannot_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $viewer = $this->makeUser('viewer');
        $this->assignCanonicalRole($viewer, 'project_viewer', 'project', $this->project->id);

        $response = $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Changed']);

        $response->assertForbidden();
    }

    public function test_project_viewer_cannot_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $viewer = $this->makeUser('viewer');
        $this->assignCanonicalRole($viewer, 'project_viewer', 'project', $this->project->id);

        $response = $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Project member (no edit capability) is denied write operations
    // -------------------------------------------------------------------------

    public function test_project_member_cannot_store_milestone(): void
    {
        $member = $this->makeUser('viewer');
        $this->assignCanonicalRole($member, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($member, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertForbidden();
    }

    public function test_project_member_cannot_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $member = $this->makeUser('viewer');
        $this->assignCanonicalRole($member, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($member, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Changed']);

        $response->assertForbidden();
    }

    public function test_project_member_cannot_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $member = $this->makeUser('viewer');
        $this->assignCanonicalRole($member, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Project manager (can_edit=true) is allowed
    // -------------------------------------------------------------------------

    public function test_project_manager_can_store_milestone(): void
    {
        $manager = $this->makeUser('viewer');
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $this->project->id);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertStatus(201);
    }

    public function test_project_manager_can_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $manager = $this->makeUser('viewer');
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $this->project->id);

        $response = $this->actingAs($manager, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Updated by Manager']);

        $response->assertOk();
    }

    public function test_project_manager_can_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $manager = $this->makeUser('viewer');
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $this->project->id);

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // Org admin (is_admin_role=true) is allowed
    // -------------------------------------------------------------------------

    public function test_org_admin_can_store_milestone(): void
    {
        $admin = $this->makeUser('admin');
        $this->grantCanonicalAdmin($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertStatus(201);
    }

    public function test_org_admin_can_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $admin = $this->makeUser('admin');
        $this->grantCanonicalAdmin($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'Admin Updated']);

        $response->assertOk();
    }

    public function test_org_admin_can_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $admin = $this->makeUser('admin');
        $this->grantCanonicalAdmin($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // super_admin bypasses all checks
    // -------------------------------------------------------------------------

    public function test_super_admin_can_store_milestone(): void
    {
        $sa = $this->makeUser('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->postJson('/api/milestones', $this->storePayload());

        $response->assertStatus(201);
    }

    public function test_super_admin_can_update_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $sa = $this->makeUser('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->putJson("/api/milestones/{$milestone->id}", ['name' => 'SA Updated']);

        $response->assertOk();
    }

    public function test_super_admin_can_destroy_milestone(): void
    {
        $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
        $sa = $this->makeUser('super_admin');

        $response = $this->actingAs($sa, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertOk();
    }
}
