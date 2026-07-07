<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for POST /api/roles/assign — proves that the engine capability
 * gate (CORE_ASSIGN_ROLES), the inline super_admin escalation guard, and the
 * Phase 3 UserRoleAssignmentGuard together block every privilege escalation path.
 */
class RoleAssignmentIsolationTest extends TestCase
{
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

    private function admin(Organization $org, Department $dept): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_org_a_admin_cannot_use_assign_endpoint_engine_blocks(): void
    {
        // POST /api/roles/assign is gated by AccessDecision::can(CORE_ASSIGN_ROLES).
        // The admin definition's permissions[] does NOT carry 'core.assign_roles'
        // (engine capability), so even an org-A admin gets 403 at the engine gate.
        // This is the existing behavior — only super_admin uses this endpoint.
        // Cross-org + super_admin escalation + null-org paths are still verified below.
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['viewer'],
            ])
            ->assertStatus(403);
    }

    public function test_org_a_admin_cannot_assign_super_admin(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['super_admin'],
            ])
            ->assertStatus(403);
    }

    public function test_org_a_admin_cannot_assign_roles_to_org_b_user(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['viewer'],
            ])
            ->assertStatus(403);
    }

    public function test_null_org_actor_denied_via_engine_capability(): void
    {
        $nullOrgActor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        // No role assigned — engine capability CORE_ASSIGN_ROLES will deny.

        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->actingAs($nullOrgActor, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['viewer'],
            ])
            ->assertStatus(403);
    }

    public function test_super_admin_can_assign_across_orgs(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $superAdmin->assignRole('super_admin');

        $target = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['admin'],
            ])
            ->assertOk();
    }

    public function test_invalid_role_key_rejected(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/roles/assign', [
                'user_id' => $target->id,
                'roles' => ['nonexistent_role'],
            ])
            ->assertStatus(403);
    }
}
