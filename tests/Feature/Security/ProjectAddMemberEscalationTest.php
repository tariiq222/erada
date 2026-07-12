<?php

namespace Tests\Feature\Security;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * H-02: an edit-tier project manager must not be able to mint another manager
 * via addMember, and a denied attempt must not persist the escalated role.
 */
class ProjectAddMemberEscalationTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_edit_only_member_cannot_mint_manager_via_add_member(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $owner = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($owner);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $owner->id,
        ]);

        // Mallory is the project manager: edit-tier (can add members) but NOT
        // delete-tier (cannot mint new managers).
        $mallory = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole(
            $mallory,
            'project_manager',
            AuthorizationRoleAssignment::SCOPE_PROJECT,
            $project->id,
        );

        $frank = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id, 'is_active' => true]);
        $eve = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id, 'is_active' => true]);
        Cache::flush();

        // Sanity: Mallory has edit, so adding a plain member succeeds.
        $this->actingAs($mallory, 'sanctum')
            ->postJson("/api/projects/{$project->id}/members", ['user_id' => $frank->id, 'role' => 'member'])
            ->assertOk();

        Cache::flush();
        // But minting a manager is delete-tier — forbidden.
        $this->actingAs($mallory, 'sanctum')
            ->postJson("/api/projects/{$project->id}/members", ['user_id' => $eve->id, 'role' => 'manager'])
            ->assertForbidden();

        Cache::flush();
        // The escalation must NOT have persisted.
        $this->assertFalse(
            AccessDecision::can($eve->fresh(), Capability::PROJECTS_EDIT, $project->fresh()),
            'Eve gained edit access despite the 403 — escalation persisted'
        );
    }
}
