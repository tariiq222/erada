<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 (ADR-UNIFIED-ROLE-ACCESS): the role editor persists the per-module reach
 * cap onto canonical authorization role permissions and returns it for editing.
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
        $this->grantCanonicalSuperAdmin($this->superAdmin);
    }

    public function test_store_persists_reach_map_on_definition(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'dept_lead_'.time(),
                'scope_type' => 'organization',
                'label_ar' => 'قائد إدارة',
                'capabilities' => ['projects.view', 'users.view'],
                'reach' => ['projects' => 'department', 'users' => 'own'],
            ])->assertCreated();

        $role = AuthorizationRole::query()->with('permissions')
            ->where('label_ar', 'قائد إدارة')
            ->firstOrFail();

        $reach = $role->permissions->pluck('reach')->filter()
            ->reduce(fn (array $carry, array $item): array => array_replace($carry, $item), []);

        $this->assertSame('department', $reach['projects']);
        $this->assertSame('own', $reach['users']);
        $this->assertArrayNotHasKey('tasks', $reach);
    }

    public function test_invalid_reach_value_is_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'bad_reach_'.time(),
                'scope_type' => 'organization',
                'capabilities' => ['projects.view'],
                'reach' => ['projects' => 'galaxy'],
            ])->assertStatus(422);
    }
}
