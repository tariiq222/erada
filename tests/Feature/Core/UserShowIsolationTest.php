<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for GET /api/users/{user} — proves the org-isolation floor at
 * the per-record read boundary (ViewUserRequest authorize() → UserPolicy::view
 * → belongsToUserOrganization).
 */
class UserShowIsolationTest extends TestCase
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

    public function test_org_a_admin_can_show_org_a_user(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $target->assignRole('viewer');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertOk()
            ->assertJsonStructure(['id', 'email', 'roles']);
    }

    public function test_org_a_admin_cannot_show_org_b_user(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $target->assignRole('viewer');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertStatus(403);
    }

    public function test_null_org_target_denied(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $nullOrgTarget = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/users/{$nullOrgTarget->id}")
            ->assertStatus(403);
    }

    public function test_super_admin_can_show_any_user(): void
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
        $target->assignRole('viewer');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/users/{$target->id}")
            ->assertOk();
    }
}
