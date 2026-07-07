<?php

namespace Tests\Feature\Api\Core;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * ScopeTypeController HTTP-level coverage.
 *
 * Routes (app/Modules/Core/Routes/api.php):
 *   GET    /api/scope-types             index   (capability-gated)
 *   POST   /api/scope-types             store   (super_admin only)
 *   GET    /api/scope-types/{type}      show    (capability-gated)
 *   PUT    /api/scope-types/{type}      update  (super_admin only)
 *   PATCH  /api/scope-types/{type}      update  (super_admin only)
 *   DELETE /api/scope-types/{type}      destroy (super_admin only)
 *
 * The CRUD routes are wrapped in `role:super_admin` middleware; index/show
 * also enforce Capability::CORE_VIEW_ORGANIZATIONS inside the controller.
 *
 * KNOWN GAP (not addressed by this test): ScopeType::destroy has NO in-use
 * guard, AND `scoped_role_definitions.scope_type_id` carries NO foreign-key
 * constraint to `scope_types.id` (verified against the live schema: zero FK
 * constraints on the table). Deleting an in-use scope type silently leaves
 * scoped_role_definitions rows as orphans pointing at a non-existent scope.
 * Two-part fix for a future task: (a) add the FK with cascadeOnDelete() or
 * restrict (so the DB enforces referential integrity), and (b) add a 422
 * in-use guard in the controller. This test pins the broken current state.
 */
class ScopeTypeControllerTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected Organization $orgA;

    protected Department $deptA;

    protected ScopeType $organizationScopeType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);

        // The seeder ensures an 'organization' scope type exists.
        $this->organizationScopeType = ScopeType::firstOrCreate(
            ['key' => 'organization'],
            ['label_ar' => 'مؤسسة', 'label_en' => 'Organization', 'model_class' => Organization::class]
        );
    }

    private function makeUser(?Organization $org = null, ?string $role = null, ?Department $dept = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org?->id,
            'department_id' => $dept?->id,
            'is_active' => true,
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    // ========== Unauthenticated 401 ==========

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/scope-types')->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson("/api/scope-types/{$this->organizationScopeType->id}")->assertStatus(401);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/scope-types', [
            'key' => 'project', 'label_ar' => 'مشروع', 'label_en' => 'Project',
            'model_class' => 'App\\Modules\\Projects\\Models\\Project',
        ])->assertStatus(401);
    }

    public function test_update_requires_authentication(): void
    {
        $this->putJson("/api/scope-types/{$this->organizationScopeType->id}", [
            'label_ar' => 'محدّث',
        ])->assertStatus(401);
    }

    public function test_destroy_requires_authentication(): void
    {
        $this->deleteJson("/api/scope-types/{$this->organizationScopeType->id}")->assertStatus(401);
    }

    // ========== Non-super-admin denial (403) ==========

    public function test_admin_role_cannot_store_scope_type(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/scope-types', [
                'key' => 'project', 'label_ar' => 'مشروع', 'label_en' => 'Project',
                'model_class' => 'App\\Modules\\Projects\\Models\\Project',
            ])
            ->assertStatus(403);
    }

    public function test_admin_role_cannot_update_scope_type(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/scope-types/{$this->organizationScopeType->id}", ['label_ar' => 'محدّث'])
            ->assertStatus(403);
    }

    public function test_admin_role_cannot_destroy_scope_type(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/scope-types/{$this->organizationScopeType->id}")
            ->assertStatus(403);
    }

    public function test_viewer_role_cannot_index_scope_types_without_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer', $this->deptA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/scope-types')
            ->assertStatus(403);
    }

    public function test_viewer_role_cannot_show_scope_type_without_capability(): void
    {
        $viewer = $this->makeUser($this->orgA, 'viewer', $this->deptA);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/scope-types/{$this->organizationScopeType->id}")
            ->assertStatus(403);
    }

    // ========== Happy path: super_admin ==========

    public function test_super_admin_can_index_scope_types(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/scope-types')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_super_admin_can_show_scope_type(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/scope-types/{$this->organizationScopeType->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $this->organizationScopeType->id)
            ->assertJsonPath('data.key', 'organization');
    }

    public function test_super_admin_can_store_scope_type_and_logs_activity(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/scope-types', [
                'key' => 'wave1_custom_type',
                'label_ar' => 'نوع مخصص',
                'label_en' => 'Custom Type',
                'model_class' => 'App\\Modules\\Projects\\Models\\Project',
                'sort_order' => 50,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('scope_types', ['key' => 'wave1_custom_type']);
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityLog::ACTION_CREATED,
            'loggable_type' => ScopeType::class,
            'loggable_id' => $response->json('data.id'),
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_can_update_scope_type_and_logs_activity(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson("/api/scope-types/{$this->organizationScopeType->id}", [
                'label_ar' => 'مؤسسة (محدّث)',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('scope_types', [
            'id' => $this->organizationScopeType->id,
            'label_ar' => 'مؤسسة (محدّث)',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityLog::ACTION_UPDATED,
            'loggable_type' => ScopeType::class,
            'loggable_id' => $this->organizationScopeType->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_can_patch_scope_type(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/scope-types/{$this->organizationScopeType->id}", [
                'sort_order' => 99,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('scope_types', [
            'id' => $this->organizationScopeType->id,
            'sort_order' => 99,
        ]);
    }

    // ========== Design-pin: scope-types are super-admin-only ==========

    public function test_non_super_admin_cannot_reach_controller_even_with_capability(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin', $this->deptA);
        $this->grantEngineCapability($admin, Capability::CORE_VIEW_ORGANIZATIONS);

        // `role:super_admin` middleware blocks every non-super-admin before
        // the controller runs. Capability grants do not unlock CRUD here.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/scope-types')
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/scope-types/{$this->organizationScopeType->id}")
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/scope-types', [
                'key' => 'x', 'label_ar' => 'x', 'label_en' => 'x', 'model_class' => 'x',
            ])
            ->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/scope-types/{$this->organizationScopeType->id}")
            ->assertStatus(403);
    }

    // ========== Known gap: destroy of in-use scope type has no guard ==========

    public function test_destroy_scope_type_leaves_orphaned_scoped_role_definitions(): void
    {
        $superAdmin = $this->makeUser(null, 'super_admin');

        // Seed a definition tied to the organization scope type.
        // Use DB::table to force-fill the legacy NOT NULL columns
        // (name, display_name, scope_type) — see LR-103.
        $defId = DB::table('scoped_role_definitions')->insertGetId([
            'name' => 'organization.wave1_test_role',
            'display_name' => 'Wave1 Test Role',
            'scope_type' => 'organization',
            'scope_type_id' => $this->organizationScopeType->id,
            'role_key' => 'wave1_test_role',
            'permissions' => json_encode(['audit.view']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson("/api/scope-types/{$this->organizationScopeType->id}")
            ->assertStatus(200);

        // Pins CURRENT behavior: the scope_types row is hard-deleted, but
        // scoped_role_definitions.scope_type_id has NO FK constraint on the
        // DB, and the controller has no in-use guard — so the definition
        // row remains as an orphan pointing at a non-existent scope type.
        // This is the security gap flagged in the class docblock.
        $this->assertDatabaseMissing('scope_types', ['id' => $this->organizationScopeType->id]);
        $this->assertDatabaseHas('scoped_role_definitions', [
            'id' => $defId,
            'scope_type_id' => $this->organizationScopeType->id,
        ]);
    }
}
