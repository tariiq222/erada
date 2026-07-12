<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for PUT/PATCH /api/users/{user} — proves org-isolation, no
 * organization_id transfer, no super_admin escalation, no cross-org role
 * assignment. Defense layers tested:
 *   1. UpdateUserRequest::authorize() ⇒ UserPolicy::update ⇒ belongsToUserOrganization
 *   2. UserController::update inline checks (organization_id, department_id)
 *   3. UserRoleAssignmentGuard (Phase 3) — role assignment layer
 */
class UserUpdateIsolationTest extends TestCase
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
        $this->grantCanonicalAdmin($user);

        return $user;
    }

    public function test_org_a_admin_can_update_org_a_user(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($target);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'name' => 'Updated Name',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_org_a_admin_cannot_update_org_b_user(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($target);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'name' => 'Hijacked',
            ])
            ->assertStatus(403);
    }

    public function test_org_a_admin_cannot_change_organization_id(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($target);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'organization_id' => $this->orgB->id,
            ])
            ->assertOk(); // UpdateUserRequest doesn't include organization_id, so it gets stripped.

        // organization_id must remain orgA.
        $target->refresh();
        $this->assertSame($this->orgA->id, $target->organization_id);
    }

    public function test_org_a_admin_cannot_assign_super_admin_via_update(): void
    {
        // UpdateUserRequest::withValidator() runs canAssignRole() (RoleHierarchy)
        // BEFORE the controller sees the payload — the violation surfaces as 422.
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($target);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'roles' => ['super_admin'],
            ])
            ->assertStatus(422);
    }

    public function test_org_a_admin_cannot_assign_roles_to_org_b_user(): void
    {
        $admin = $this->admin($this->orgA, $this->deptA);
        $target = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);

        // First call: cross-org should 403 at the policy layer (before roles are processed).
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'roles' => ['viewer'],
            ])
            ->assertStatus(403);
    }

    public function test_self_update_does_not_allow_self_promotion(): void
    {
        // admin updates themselves trying to add super_admin — the FormRequest
        // withValidator's canAssignRole() check (RoleHierarchy) catches it as 422.
        $admin = $this->admin($this->orgA, $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$admin->id}", [
                'roles' => ['super_admin'],
            ])
            ->assertStatus(422);
    }

    public function test_super_admin_can_update_across_orgs(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $target = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalViewer($target);

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'name' => 'Updated By Super',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Updated By Super',
        ]);
    }

    public function test_super_admin_cannot_move_user_to_department_in_another_organization(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->orgA->id]);
        $superAdmin->assignRole('super_admin');
        $target = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);
        $target->assignRole('viewer');

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/users/{$target->id}", ['department_id' => $this->deptB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);

        $this->assertSame($this->deptA->id, $target->fresh()->department_id);
    }

    public function test_updating_existing_super_admin_roles_cannot_silently_remove_super_admin(): void
    {
        $actor = User::factory()->create(['organization_id' => $this->orgA->id]);
        $actor->assignRole('super_admin');
        $target = User::factory()->create(['organization_id' => $this->orgB->id]);
        $target->assignRole('super_admin');

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/users/{$target->id}", ['roles' => ['viewer']])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole('super_admin'));
    }

    public function test_super_admin_cannot_grant_super_admin_through_user_update(): void
    {
        $actor = User::factory()->create(['organization_id' => $this->orgA->id]);
        $actor->assignRole('super_admin');
        $target = User::factory()->create(['organization_id' => $this->orgA->id]);
        $target->assignRole('viewer');

        $this->actingAs($actor, 'sanctum')
            ->putJson("/api/users/{$target->id}", ['roles' => ['super_admin']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);

        $this->assertFalse($target->fresh()->hasRole('super_admin'));
    }
}
