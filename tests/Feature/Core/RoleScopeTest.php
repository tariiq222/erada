<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\User;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scope-aware role create/update (Phase 4b — single source):
 *  - store/update accept an optional scope_type (default 'organization').
 *  - scoped_role_definitions is the SOLE store for role definitions at every
 *    scope — no Spatie Role is created, org scope included.
 *  - GET /api/roles/scope-options returns the scopes plus their definitions for
 *    the editor's pickers.
 */
class RoleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_create_department_scoped_role_writes_definition_without_spatie_role(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/roles', [
            'name' => 'er_manager',
            'scope_type' => 'department',
            'label_ar' => 'مدير الطوارئ',
            'label_en' => 'ER Manager',
            'permissions_capabilities' => ['projects.view', 'projects.edit', 'tasks.view'],
        ])->assertCreated();

        $deptScopeId = DB::table('scope_types')->where('key', 'department')->value('id');
        $this->assertDatabaseHas('scoped_role_definitions', [
            'scope_type_id' => $deptScopeId, 'role_key' => 'er_manager',
        ]);
        // no Spatie role for a non-org scope
        $this->assertDatabaseMissing('roles', ['name' => 'er_manager']);
    }

    public function test_create_organization_scoped_role_writes_definition_without_spatie_role(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/roles', [
            'name' => 'org_auditor',
            'label_ar' => 'مدقق المؤسسة',
            'label_en' => 'Org Auditor',
            'permissions_capabilities' => ['projects.view'],
        ])->assertCreated();

        $orgScopeId = DB::table('scope_types')->where('key', 'organization')->value('id');
        $this->assertDatabaseHas('scoped_role_definitions', [
            'scope_type_id' => $orgScopeId, 'role_key' => 'org_auditor',
        ]);
        // Single source: org scope no longer creates a Spatie role either.
        $this->assertDatabaseMissing('roles', ['name' => 'org_auditor']);
    }

    public function test_scope_options_returns_scopes_and_definitions(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/roles/scope-options')
            ->assertOk()
            ->assertJsonStructure([
                'scopes' => [
                    '*' => ['key', 'label'],
                ],
                'definitions',
            ]);

        $scopeKeys = collect($response->json('scopes'))->pluck('key');
        $this->assertTrue($scopeKeys->contains('organization'));
        $this->assertTrue($scopeKeys->contains('department'));

        $deptDefs = collect($response->json('definitions.department'))->pluck('role_key');
        $this->assertTrue($deptDefs->contains('dept_member'));
        $this->assertTrue($deptDefs->contains('dept_manager'));
    }
}
