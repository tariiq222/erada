<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 (ADR-UNIFIED-ROLE-ACCESS): the role editor persists the per-module reach
 * cap onto the scoped_role_definition, and returns it back for editing.
 */
class RoleReachPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $dept = Department::factory()->create();
        $this->superAdmin = User::factory()->create([
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_store_persists_reach_map_on_definition(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'dept_lead_'.time(),
                'scope_type' => 'organization',
                'label_ar' => 'قائد إدارة',
                'permissions_capabilities' => ['projects.view', 'users.view'],
                'reach' => ['projects' => 'department', 'users' => 'own'],
            ])->assertCreated();

        $orgScopeId = ScopeType::findByKey('organization')->id;
        $def = ScopedRoleDefinition::where('scope_type_id', $orgScopeId)
            ->where('label_ar', 'قائد إدارة')
            ->first();

        $this->assertNotNull($def);
        $this->assertSame('department', $def->reachForModule('projects'));
        $this->assertSame('own', $def->reachForModule('users'));
        // A module without a reach entry defaults to 'all'.
        $this->assertSame('all', $def->reachForModule('tasks'));
    }

    public function test_invalid_reach_value_is_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'bad_reach_'.time(),
                'scope_type' => 'organization',
                'permissions_capabilities' => ['projects.view'],
                'reach' => ['projects' => 'galaxy'],
            ])->assertStatus(422);
    }
}
