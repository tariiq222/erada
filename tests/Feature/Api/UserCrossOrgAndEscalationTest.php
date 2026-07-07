<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
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

        // Spatie role kept (some legacy code paths still read it) but it carries
        // no flat permissions — engine capability grants are the only thing the
        // UserPolicy consults.
        Role::create(['name' => 'org_viewer', 'guard_name' => 'web']);
    }

    private function makeUser(string $role, Organization $org): User
    {
        $dept = $org->id === $this->orgA->id ? $this->deptA : $this->deptB;

        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_non_admin_view_users_holder_cannot_view_cross_org_user(): void
    {
        $actor = $this->makeUser('org_viewer', $this->orgA);
        $target = $this->makeUser('member', $this->orgB);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}");

        $response->assertStatus(403);
    }

    public function test_non_admin_edit_users_holder_cannot_update_cross_org_user(): void
    {
        $actor = $this->makeUser('org_viewer', $this->orgA);
        $target = $this->makeUser('member', $this->orgB);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/users/{$target->id}", [
                'name' => 'Cross Org Rename',
            ]);

        $response->assertStatus(403);
    }

    public function test_non_admin_delete_users_holder_cannot_delete_cross_org_user(): void
    {
        $actor = $this->makeUser('org_viewer', $this->orgA);
        $target = $this->makeUser('member', $this->orgB);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/users/{$target->id}");

        $response->assertStatus(403);
    }

    public function test_non_admin_holder_can_view_same_org_user(): void
    {
        $actor = $this->makeUser('org_viewer', $this->orgA);
        // Engine: UserPolicy::view routes through Capability::USERS_VIEW; the
        // legacy 'view_users' Spatie permission is no longer honored.
        $this->grantEngineCapability($actor, Capability::USERS_VIEW);
        $target = $this->makeUser('member', $this->orgA);

        $response = $this->actingAs($actor, 'sanctum')
            ->getJson("/api/users/{$target->id}");

        $response->assertStatus(200);
    }

    public function test_index_does_not_include_cross_org_users(): void
    {
        $actor = $this->makeUser('org_viewer', $this->orgA);
        $this->grantEngineCapability($actor, Capability::USERS_VIEW);
        $orgBMember = User::factory()->create([
            'organization_id' => $this->orgB->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $orgBMember->assignRole('member');

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
        $actor = $this->makeUser('org_viewer', $this->orgA);
        $this->grantEngineCapability($actor, Capability::USERS_CREATE);

        // admin is part of the Spatie compat set, so the assignment helper
        // still mirrors it there in addition to the org-scoped row.
        Role::findOrCreate('admin', 'web');

        // Attempt to create a user pinned to orgB AND with roles[] applied.
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
                'roles' => ['admin'],
            ]);

        // The org-lock step rejects the cross-org payload before the user is
        // created, so the response is 403 (NOT 201) and no user row is written.
        $response->assertStatus(403);

        $this->assertDatabaseMissing('users', [
            'email' => 'cross.org.user@example.com',
        ]);
    }

    public function test_user_store_creates_user_in_actors_own_org_with_roles(): void
    {
        // Positive case: non-super_admin with admin-tier status can create a
        // user in their own org with roles[] applied. The org-lock +
        // assertSameOrganization guards pass by construction because the new
        // user is forced into orgA.
        $actor = $this->makeUser('org_viewer', $this->orgA);
        // Admin tier (SETTINGS_MANAGE) is required to assign the 'admin' role
        // per RoleHierarchy::canAssignTo. USERS_CREATE alone is not enough.
        $this->grantEngineCapability($actor, [
            Capability::USERS_CREATE,
            Capability::SETTINGS_MANAGE,
        ]);

        Role::findOrCreate('admin', 'web');

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'Same Org User',
                'email' => 'same.org.user@example.com',
                'password' => 'Password123!',
                'department_id' => $this->deptA->id,
                'roles' => ['admin'],
            ]);

        $response->assertStatus(201);

        $created = User::where('email', 'same.org.user@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame($this->orgA->id, $created->organization_id);
        $this->assertContains('admin', $created->fresh()->roles->pluck('name')->all());
    }

    public function test_user_store_accepts_scoped_definition_role_without_spatie_role(): void
    {
        $actor = $this->makeUser('org_viewer', $this->orgA);
        $this->grantEngineCapability($actor, [
            Capability::USERS_CREATE,
            Capability::SETTINGS_MANAGE,
        ]);

        $this->createOrgRoleDefinition('pmo_member', 'عضو مكتب المشاريع', [
            Capability::PROJECTS_VIEW,
        ]);
        $this->assertDatabaseMissing('roles', ['name' => 'pmo_member']);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'PMO Scoped User',
                'email' => 'pmo.scoped.user@example.com',
                'password' => 'Password123!',
                'department_id' => $this->deptA->id,
                'roles' => ['pmo_member'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.roles.0', 'pmo_member');

        $created = User::where('email', 'pmo.scoped.user@example.com')->first();
        $this->assertNotNull($created);
        $this->assertDatabaseHas('model_has_scoped_roles', [
            'user_id' => $created->id,
            'role' => 'pmo_member',
            'scope_type' => 'organization',
            'scope_id' => $this->orgA->id,
            'source' => 'manual',
        ]);
        $this->assertDatabaseMissing('model_has_roles', [
            'model_id' => $created->id,
            'model_type' => User::class,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createOrgRoleDefinition(string $roleKey, string $labelAr, array $permissions): void
    {
        $orgScopeTypeId = DB::table('scope_types')->where('key', 'organization')->value('id');
        $this->assertNotNull($orgScopeTypeId);

        DB::table('scoped_role_definitions')->updateOrInsert(
            ['scope_type_id' => $orgScopeTypeId, 'role_key' => $roleKey],
            [
                'name' => 'organization.'.$roleKey,
                'display_name' => $labelAr,
                'scope_type' => 'organization',
                'label_ar' => $labelAr,
                'label_en' => $roleKey,
                'permissions' => json_encode($permissions),
                'is_admin_role' => false,
                'is_active' => true,
                'sort_order' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
