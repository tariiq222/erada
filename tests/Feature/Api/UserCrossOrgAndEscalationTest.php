<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class UserCrossOrgAndEscalationTest extends TestCase
{
    use GrantsEngineCapability;
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

    /** @param list<string> $capabilities */
    private function makeCapabilityUser(array $capabilities, Organization $org): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $org->id === $this->orgA->id ? $this->deptA->id : $this->deptB->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, $capabilities, 'organization', $org->id);

        return $user;
    }

    private function makeUser(string $role, Organization $org): User
    {
        $dept = $org->id === $this->orgA->id ? $this->deptA : $this->deptB;

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($user, $role);

        return $user;
    }

    public function test_non_admin_view_users_holder_cannot_view_cross_org_user(): void
    {
        $actor = $this->makeCapabilityUser([Capability::USERS_VIEW], $this->orgA);
        $target = $this->makeUser('member', $this->orgB);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}");

        $response->assertStatus(403);
    }

    public function test_non_admin_edit_users_holder_cannot_update_cross_org_user(): void
    {
        $actor = $this->makeCapabilityUser([Capability::USERS_EDIT], $this->orgA);
        $target = $this->makeUser('member', $this->orgB);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'name' => 'Cross Org Rename',
            ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_delete_users_holder_cannot_delete_cross_org_user(): void
    {
        $actor = $this->makeCapabilityUser([Capability::USERS_DELETE], $this->orgA);
        $target = $this->makeUser('member', $this->orgB);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/users/{$target->id}");

        $response->assertStatus(403);
    }

    public function test_non_admin_holder_can_view_same_org_user(): void
    {
        $actor = $this->makeCapabilityUser([Capability::USERS_VIEW], $this->orgA);
        $target = $this->makeUser('member', $this->orgA);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}");

        $response->assertStatus(200);
    }

    public function test_index_does_not_include_cross_org_users(): void
    {
        $actor = $this->makeCapabilityUser([Capability::USERS_VIEW], $this->orgA);
        $orgBMember = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($orgBMember, 'member');

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson('/api/users');

        $response->assertStatus(200);
        $response->assertJsonMissing(['email' => $orgBMember->email]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($orgBMember->id));
    }

    public function test_user_store_rejects_cross_org_roles_array(): void
    {
        // Non-super_admin actor in orgA with USERS_CREATE capability.
        $actor = $this->makeCapabilityUser([Capability::USERS_CREATE], $this->orgA);
        $this->grantCanonicalAdmin($actor, 'organization', $this->orgA->id);
        $this->grantEngineCapability($actor, Capability::USERS_CREATE, 'organization', $this->orgA->id, 'admin');

        // Attempt to create a user pinned to orgB with a canonical assignment.
        // The controller's org-lock step forces organization_id to the actor's
        // own org (orgA) for non-super_admin actors, AND the new explicit
        // assertSameOrganization guard (defense-in-depth) before applyRoleAssignment
        // refuses to assign any role if the resolved org mismatches the actor's.
        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Cross Org User',
                'email' => 'cross.org.user@example.com',
                'password' => 'Password123!',
                'organization_id' => $this->orgB->id,
                'department_id' => $this->deptB->id,
                'assignments' => [[
                    'role_id' => $this->roleId('member'),
                    'scope_type' => 'organization',
                    'scope_id' => $this->orgB->id,
                ]],
            ]);

        // The org-lock step rejects the cross-org payload before the user is
        // created, so the response is 403 (NOT 201) and no user row is written.
        $response->assertStatus(403);

        $this->assertDatabaseMissing('users', [
            'email' => 'cross.org.user@example.com',
        ]);
    }

    public function test_user_store_creates_user_in_actors_own_org_with_canonical_assignment(): void
    {
        // Positive case: non-super_admin with admin-tier status can create a
        // user in their own org with a canonical assignment. The org-lock +
        // assertSameOrganization guards pass by construction because the new
        // user is forced into orgA.
        $actor = $this->makeCapabilityUser([
            Capability::USERS_CREATE,
            Capability::CORE_ASSIGN_ROLES,
        ], $this->orgA);
        $this->grantCanonicalAdmin($actor, 'organization', $this->orgA->id);
        $this->grantEngineCapability(
            $actor,
            [Capability::USERS_CREATE, Capability::CORE_ASSIGN_ROLES],
            'organization',
            $this->orgA->id,
            'admin',
        );

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Same Org User',
                'email' => 'same.org.user@example.com',
                'password' => 'Password123!',
                'department_id' => $this->deptA->id,
                'assignments' => [[
                    'role_id' => $this->roleId('member'),
                    'scope_type' => 'organization',
                    'scope_id' => $this->orgA->id,
                ]],
            ]);

        $response->assertStatus(201);

        $created = User::where('email', 'same.org.user@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame($this->orgA->id, $created->organization_id);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $created->id,
            'authorization_role_id' => $this->roleId('member'),
            'scope_type' => 'organization',
            'scope_id' => $this->orgA->id,
        ]);
    }

    public function test_user_store_accepts_custom_canonical_role(): void
    {
        $actor = $this->makeCapabilityUser([
            Capability::USERS_CREATE,
            Capability::CORE_ASSIGN_ROLES,
            Capability::PROJECTS_VIEW,
        ], $this->orgA);
        $this->grantCanonicalAdmin($actor, 'organization', $this->orgA->id);
        $this->grantEngineCapability(
            $actor,
            [Capability::USERS_CREATE, Capability::CORE_ASSIGN_ROLES, Capability::PROJECTS_VIEW],
            'organization',
            $this->orgA->id,
            'admin',
        );

        $role = AuthorizationRole::query()->create([
            'name' => 'pmo_member',
            'label' => 'PMO Member',
            'scope_type' => 'organization',
            'is_active' => true,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'PMO Scoped User',
                'email' => 'pmo.scoped.user@example.com',
                'password' => 'Password123!',
                'department_id' => $this->deptA->id,
                'assignments' => [[
                    'role_id' => $role->id,
                    'scope_type' => 'organization',
                    'scope_id' => $this->orgA->id,
                ]],
            ]);

        $response->assertStatus(201);

        $created = User::where('email', 'pmo.scoped.user@example.com')->first();
        $this->assertNotNull($created);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $created->id,
            'authorization_role_id' => $role->id,
            'scope_type' => 'organization',
            'scope_id' => $this->orgA->id,
            'source' => 'manual',
        ]);
    }

    private function roleId(string $name): int
    {
        return (int) AuthorizationRole::query()->where('name', $name)->valueOrFail('id');
    }
}
