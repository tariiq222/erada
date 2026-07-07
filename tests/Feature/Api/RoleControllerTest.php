<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $regularUser;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->regularUser->assignRole('member');
    }

    // ========================================
    // اختبارات قراءة الأدوار
    // ========================================

    public function test_super_admin_can_list_roles(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name'],
                ],
            ]);
    }

    public function test_regular_user_cannot_list_roles(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson('/api/roles');

        $response->assertStatus(403);
    }

    public function test_can_view_single_role_with_permissions(): void
    {
        // Roles are addressed by their scoped_role_definition id (single source).
        $def = $this->orgDef('admin');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson("/api/roles/{$def->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'permissions',
                ],
            ]);
        $this->assertEquals('admin', $response->json('data.name'));
    }

    // ========================================
    // اختبارات إنشاء الأدوار
    // ========================================

    public function test_can_create_role(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'custom_role',
                'permissions_capabilities' => ['projects.view', 'projects.create'],
            ]);

        $response->assertStatus(201);
        // Single source: the definition is written, no Spatie role.
        $this->assertDatabaseHas('scoped_role_definitions', ['role_key' => 'custom_role']);
        $this->assertDatabaseMissing('roles', ['name' => 'custom_role']);
    }

    public function test_cannot_create_duplicate_role(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'admin', // موجود
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_role_validation(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => '', // فارغ
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ========================================
    // اختبارات تحديث الأدوار
    // ========================================

    public function test_can_update_role_permissions(): void
    {
        $defId = $this->createRole('test_role', ['projects.view']);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/roles/{$defId}", [
                'name' => 'test_role_updated',
                'permissions_capabilities' => ['projects.view', 'tasks.view'],
            ]);

        $response->assertStatus(200);

        $def = ScopedRoleDefinition::find($defId);
        $this->assertEquals('test_role_updated', $def->role_key);
        $this->assertContains('tasks.view', $def->permissions ?? []);
    }

    public function test_cannot_rename_system_role(): void
    {
        // System (compat-set) definitions cannot be renamed via the API.
        $adminDef = $this->orgDef('admin');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/roles/{$adminDef->id}", [
                'name' => 'renamed_admin',
            ]);

        $response->assertStatus(403);
        $this->assertEquals('admin', $adminDef->fresh()->role_key);
    }

    // ========================================
    // اختبارات حذف الأدوار
    // ========================================

    public function test_can_delete_custom_role(): void
    {
        $defId = $this->createRole('deletable_role');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/roles/{$defId}");

        $response->assertStatus(200);
        // Soft-disable: the definition row stays but is deactivated.
        $def = ScopedRoleDefinition::find($defId);
        $this->assertNotNull($def);
        $this->assertFalse((bool) $def->is_active);
    }

    public function test_cannot_delete_system_roles(): void
    {
        // The compat-set (super_admin/admin/viewer) definitions are protected.
        $adminDef = $this->orgDef('admin');

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/roles/{$adminDef->id}");

        $response->assertStatus(403);
        $this->assertTrue((bool) $adminDef->fresh()->is_active);
    }

    public function test_cannot_delete_role_with_users(): void
    {
        $defId = $this->createRole('role_with_users');
        $roleKey = ScopedRoleDefinition::find($defId)->role_key;

        // Users hold this role via an org-scope scoped assignment.
        ScopedRole::create([
            'user_id' => $this->regularUser->id,
            'role' => $roleKey,
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => $this->regularUser->organization_id ?? 1,
            'granted_by' => $this->superAdmin->id,
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->deleteJson("/api/roles/{$defId}");

        $response->assertStatus(422);
    }

    // ========================================
    // اختبارات الصلاحيات
    // ========================================

    public function test_can_list_permissions(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/roles/permissions');

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_list_permissions(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson('/api/roles/permissions');

        $response->assertStatus(403);
    }

    public function test_abilities_registry_groups_carry_store_and_engine_caps(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/roles/abilities');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['groups' => [['key', 'label', 'store', 'abilities' => [['id', 'label']]]]],
            ]);

        $groups = collect($response->json('data.groups'));

        // engine module exposes scope-less capability ids (module.action)
        $projects = $groups->firstWhere('key', 'projects');
        $this->assertSame('engine', $projects['store']);
        $this->assertContains('projects.view', array_column($projects['abilities'], 'id'));

        // flat module keeps real Spatie permission names
        $users = $groups->firstWhere('key', 'users');
        $this->assertSame('flat', $users['store']);
        $this->assertContains('view_users', array_column($users['abilities'], 'id'));

        // no engine module leaks back in as a flat group (no duplication)
        $flatKeys = $groups->where('store', 'flat')->pluck('key');
        foreach (['projects', 'tasks', 'risks', 'ovr', 'strategy', 'departments'] as $engineKey) {
            $this->assertFalse($flatKeys->contains($engineKey), "engine module {$engineKey} duplicated as flat");
        }
    }

    public function test_abilities_requires_super_admin(): void
    {
        $this->actingAs($this->regularUser, 'sanctum')
            ->getJson('/api/roles/abilities')
            ->assertStatus(403);
    }

    // ========================================
    // اختبارات الأمان
    // ========================================

    public function test_unauthenticated_cannot_access_roles(): void
    {
        $this->getJson('/api/roles')->assertStatus(401);
        $this->postJson('/api/roles', ['name' => 'test'])->assertStatus(401);
    }

    public function test_regular_user_cannot_create_roles(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'hacker_role',
            ]);

        $response->assertStatus(403);
    }

    public function test_regular_user_cannot_delete_roles(): void
    {
        $def = $this->orgDef('viewer');

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->deleteJson("/api/roles/{$def->id}");

        $response->assertStatus(403);
    }

    // ========================================
    // Helpers
    // ========================================

    /** Create a role via the API and return its definition id. */
    private function createRole(string $name, array $capabilities = []): int
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/roles', [
                'name' => $name,
                'permissions_capabilities' => $capabilities,
            ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /** Fetch an org-scope role definition by role_key. */
    private function orgDef(string $roleKey): ScopedRoleDefinition
    {
        $orgScopeTypeId = ScopeType::findByKey('organization')?->id;

        return ScopedRoleDefinition::where('scope_type_id', $orgScopeTypeId)
            ->where('role_key', $roleKey)
            ->firstOrFail();
    }
}
