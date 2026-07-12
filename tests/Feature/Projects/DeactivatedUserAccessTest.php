<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the EnsureUserIsActive middleware gap.
 *
 * The authz decision (`AccessDecision::can`) is computed from the user's
 * persisted state, so a user deactivated AFTER receiving a Sanctum token
 * would otherwise still pass `auth:sanctum` and reach the controller. The
 * `EnsureUserIsActive` middleware is the single defense — these tests
 * verify it actually fires on every Projects endpoint that mutates state.
 */
class DeactivatedUserAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Department $department;

    protected User $deactivatedUser;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->deactivatedUser = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'is_active' => false,
        ]);
        $this->grantCanonicalAdmin($this->deactivatedUser);

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->deactivatedUser->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
    }

    public function test_deactivated_user_cannot_list_projects(): void
    {
        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->getJson('/api/projects');

        $response->assertStatus(401)
            ->assertJsonPath('reason', 'account_deactivated');
    }

    public function test_deactivated_user_cannot_show_project(): void
    {
        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}");

        $response->assertStatus(401);
    }

    public function test_deactivated_user_cannot_update_project(): void
    {
        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->putJson("/api/projects/{$this->project->id}", [
                'name' => 'New name',
            ]);

        $response->assertStatus(401);
    }

    public function test_deactivated_user_cannot_delete_project(): void
    {
        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$this->project->id}");

        $response->assertStatus(401);
        $this->assertNotSoftDeleted('projects', ['id' => $this->project->id]);
    }

    public function test_deactivated_user_cannot_add_member(): void
    {
        $newUser = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $newUser->id,
                'role' => 'member',
            ]);

        $response->assertStatus(401);
    }

    public function test_deactivated_user_cannot_add_risk(): void
    {
        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'risk' => 'Test risk',
                'probability' => 'low',
                'impact' => 'low',
            ]);

        $response->assertStatus(401);
    }

    public function test_deactivated_user_cannot_read_settings(): void
    {
        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->getJson('/api/projects/settings');

        $response->assertStatus(401);
    }

    public function test_deactivated_user_cannot_advance_pdca_phase(): void
    {
        $improvementProject = Project::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'created_by' => $this->deactivatedUser->id,
            'type' => 'improvement',
            'status' => 'in_progress',
            'current_pdca_phase' => 'plan',
        ]);

        $response = $this->actingAs($this->deactivatedUser, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->patchJson("/api/projects/{$improvementProject->id}/pdca-phase", [
                'phase' => 'do',
            ]);

        $response->assertStatus(401);
    }

    public function test_active_user_with_same_token_still_works(): void
    {
        // Sanity check: a deactivated user gets 401, but an active user with
        // a freshly issued token can still hit the same endpoint.
        $activeUser = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($activeUser);

        $response = $this->actingAs($activeUser, 'sanctum')
            ->getJson('/api/projects');

        $response->assertOk();
    }
}
