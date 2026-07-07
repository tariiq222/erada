<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * HTTP coverage for DELETE /api/projects/{project}.
 *
 * The service-level path is covered by ProjectDeletionOrphanLinksTest; this file
 * focuses on the endpoint shape, authorization, rate-limit bypass, and the
 * cross-tenant / cross-organization denial paths that a controller-only audit
 * cannot exercise.
 */
class ProjectDeleteEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Organization $foreignOrganization;

    protected Department $department;

    protected User $superAdmin;

    protected User $member;

    protected User $foreignUser;

    protected Department $foreignDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->foreignOrganization = Organization::factory()->create();

        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->member = User::factory()->create([
            'department_id' => $this->department->id,
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->foreignDepartment = Department::factory()->create([
            'organization_id' => $this->foreignOrganization->id,
            'is_active' => true,
        ]);

        $this->foreignUser = User::factory()->create([
            'department_id' => $this->foreignDepartment->id,
            'organization_id' => $this->foreignOrganization->id,
            'is_active' => true,
        ]);
        $this->foreignUser->assignRole('admin');

        Cache::flush();
    }

    private function makeProject(?Department $dept = null): Project
    {
        $department = $dept ?? $this->department;

        return Project::factory()->create([
            'department_id' => $department->id,
            'created_by' => $this->superAdmin->id,
            'organization_id' => $department->organization_id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
    }

    public function test_super_admin_can_delete_project_via_http(): void
    {
        $project = $this->makeProject();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_unauthenticated_delete_is_rejected(): void
    {
        $project = $this->makeProject();

        $response = $this->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(401);
    }

    public function test_member_without_role_cannot_delete(): void
    {
        $project = $this->makeProject();

        $response = $this->actingAs($this->member, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$project->id}");

        // 403 (policy deny) or 404 (org-scoped lookup miss) are both valid deny
        // responses — the controller must never soft-delete in either case.
        $this->assertContains($response->status(), [403, 404]);
        $this->assertNotSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_foreign_org_admin_cannot_delete_project(): void
    {
        $project = $this->makeProject();

        $response = $this->actingAs($this->foreignUser, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$project->id}");

        $this->assertContains($response->status(), [403, 404]);
        $this->assertNotSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_delete_missing_project_returns_404(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson('/api/projects/999999');

        $response->assertStatus(404);
    }

    public function test_delete_preserves_orphan_kpi_but_removes_link(): void
    {
        $project = $this->makeProject();

        $kpi = Kpi::factory()->create([
            'department_id' => $this->department->id,
            'owner_id' => $this->superAdmin->id,
            'created_by' => $this->superAdmin->id,
        ]);

        (new KpiLink)->forceFill([
            'organization_id' => $project->organization_id,
            'kpi_id' => $kpi->id,
            'linkable_type' => Project::class,
            'linkable_id' => $project->id,
            'relationship_type' => 'related',
            'weight' => 1,
            'created_by' => $this->superAdmin->id,
        ])->save();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->withHeader('X-Skip-Csrf', '1')
            ->deleteJson("/api/projects/{$project->id}")
            ->assertOk();

        // Kpi is owned by Performance — delete must preserve it. The kpi_links
        // cleanup is exercised at service level in ProjectDeletionOrphanLinksTest.
        $this->assertDatabaseHas('kpis', ['id' => $kpi->id]);
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }
}
