<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\Projects\Models\Project;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProjectAbilitiesTest — every /api/projects/{id} response must carry an
 * `abilities` object computed by AccessDecision. The negative sibling-branch
 * case (review point 5c) proves intra-org isolation at the ability layer.
 */
class ProjectAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    public function test_project_response_carries_engine_abilities(): void
    {
        $org = Organization::factory()->create();
        $sector = Department::factory()->create([
            'organization_id' => $org->id,
            'parent_id' => null,
        ]);
        $child = Department::factory()->create([
            'organization_id' => $org->id,
            'parent_id' => $sector->id,
        ]);

        DepartmentCapacityRole::create([
            'department_id' => $sector->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $sectorMgr = User::factory()->create(['organization_id' => $org->id]);
        $sector->update(['manager_id' => $sectorMgr->id]);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $child->id,
        ]);

        // Vertical: sector manager sees child-department project through scope-chain ascent.
        $this->actingAs($sectorMgr->fresh(), 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('abilities.view', true)
            ->assertJsonPath('abilities.edit', true);
    }

    public function test_out_of_scope_branch_manager_cannot_reach_the_project(): void
    {
        // Negative isolation INSIDE the same org: a sibling-branch manager must NOT
        // see the child-department project at all. The engine excludes unauthorized
        // projects at the query layer, so the controller returns 404 — which is the
        // strongest possible negative assertion for an API surface.
        $org = Organization::factory()->create();
        $sector = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => null]);
        $child = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);
        $sibling = Department::factory()->create(['organization_id' => $org->id, 'parent_id' => $sector->id]);

        DepartmentCapacityRole::create(['department_id' => $sibling->id, 'capacity' => 'manager', 'role_key' => 'dept_manager']);

        $siblingMgr = User::factory()->create(['organization_id' => $org->id]);
        $sibling->update(['manager_id' => $siblingMgr->id]);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $child->id,
        ]);

        $this->actingAs($siblingMgr->fresh(), 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertNotFound();
    }

    public function test_creator_without_role_loses_edit_when_project_is_closed(): void
    {
        // The owner-floor grants view unconditionally, but edit is lifecycle-gated
        // via Project::isOwnerEditable(): a closed/completed/cancelled project
        // is NOT editable by its creator through the owner floor.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => null,
            'status' => 'completed',
        ]);
        $creator = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $project->update(['created_by' => $creator->id]);

        $this->actingAs($creator->fresh(), 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('abilities.view', true)
            ->assertJsonPath('abilities.edit', false)
            ->assertJsonPath('abilities.delete', false);
    }

    public function test_super_admin_can_view_project_with_all_abilities_true(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $superAdmin = User::factory()->create(['organization_id' => $org->id]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('abilities.view', true)
            ->assertJsonPath('abilities.edit', true)
            ->assertJsonPath('abilities.delete', true)
            ->assertJsonPath('abilities.assign_roles', true)
            ->assertJsonMissingPath('abilities.manage_members')
            ->assertJsonMissingPath('abilities.change_status')
            ->assertJsonMissingPath('abilities.close');
    }
}
