<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalRoleCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => AuthorizationRole::query()->where('name', 'super_admin')->value('id'),
            'user_id' => $this->actor->id,
            'scope_type' => 'all',
            'scope_id' => null,
            'organization_id' => null,
            'source' => 'manual',
            'granted_by' => $this->actor->id,
        ]);
        AccessDecision::flushUserCache($this->actor->id);
        $trace = AccessDecision::canonicalTrace($this->actor, Capability::ROLES_CREATE);
        $this->assertTrue($trace['granted'], json_encode($trace));
    }

    public function test_crud_uses_numeric_canonical_role_and_permission_ids(): void
    {
        $created = $this->actingAs($this->actor, 'sanctum')->postJson('/api/roles', [
            'name' => 'canonical_reviewer',
            'label' => 'Reviewer',
            'label_ar' => 'مراجع',
            'label_en' => 'Reviewer',
            'scope_type' => 'organization',
            'capabilities' => [Capability::PROJECTS_VIEW, Capability::TASKS_VIEW],
            'reach' => ['projects' => 'department'],
        ])->assertCreated();

        $id = $created->json('data.id');
        $this->assertIsInt($id);
        $this->assertDatabaseHas('authorization_roles', ['id' => $id, 'name' => 'canonical_reviewer']);
        $this->assertDatabaseCount('authorization_role_permissions', 2 + $this->seededPermissionCountExcluding($id));

        $this->actingAs($this->actor, 'sanctum')->putJson("/api/roles/{$id}", [
            'label_ar' => 'مراجع أول',
            'capabilities' => [Capability::PROJECTS_EDIT],
            'reach' => ['projects' => 'own'],
        ])->assertOk()->assertJsonPath('data.capabilities.0', Capability::PROJECTS_EDIT);

        $this->actingAs($this->actor, 'sanctum')->deleteJson("/api/roles/{$id}")
            ->assertOk();
        $this->assertDatabaseHas('authorization_roles', ['id' => $id, 'is_active' => false]);
    }

    public function test_unknown_capability_is_rejected_without_writes(): void
    {
        $this->actingAs($this->actor, 'sanctum')->postJson('/api/roles', [
            'name' => 'unknown_capability_role',
            'capabilities' => ['unknown.do_anything'],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('authorization_roles', ['name' => 'unknown_capability_role']);
    }

    public function test_system_role_is_immutable(): void
    {
        $role = AuthorizationRole::query()->where('name', 'super_admin')->firstOrFail();

        $this->actingAs($this->actor, 'sanctum')->putJson("/api/roles/{$role->id}", ['label' => 'Changed'])->assertForbidden();
        $this->actingAs($this->actor, 'sanctum')->deleteJson("/api/roles/{$role->id}")->assertForbidden();
    }

    public function test_assigned_role_requires_explicit_reassignment_before_disable(): void
    {
        $source = AuthorizationRole::query()->create(['name' => 'source_role', 'label' => 'Source', 'scope_type' => 'all', 'is_active' => true]);
        $replacement = AuthorizationRole::query()->create(['name' => 'replacement_role', 'label' => 'Replacement', 'scope_type' => 'all', 'is_active' => true]);
        $subject = User::factory()->create(['is_active' => true]);
        $source->assignments()->create([
            'user_id' => $subject->id, 'scope_type' => 'all', 'scope_id' => null,
            'organization_id' => null, 'source' => 'manual', 'granted_by' => $this->actor->id,
        ]);

        $this->actingAs($this->actor, 'sanctum')->deleteJson("/api/roles/{$source->id}")->assertUnprocessable();
        $this->actingAs($this->actor, 'sanctum')->deleteJson("/api/roles/{$source->id}", [
            'reassign_to_role_id' => $replacement->id,
        ])->assertOk();

        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $replacement->id, 'user_id' => $subject->id,
        ]);
    }

    public function test_assigned_role_scope_cannot_change_incompatibly(): void
    {
        $role = AuthorizationRole::query()->create([
            'name' => 'assigned_all_role',
            'label' => 'Assigned all role',
            'scope_type' => 'all',
            'is_active' => true,
        ]);
        $subject = User::factory()->create(['is_active' => true]);
        $role->assignments()->create([
            'user_id' => $subject->id,
            'scope_type' => 'all',
            'scope_id' => null,
            'organization_id' => null,
            'source' => 'manual',
            'granted_by' => $this->actor->id,
        ]);

        $this->actingAs($this->actor, 'sanctum')->putJson("/api/roles/{$role->id}", [
            'scope_type' => 'organization',
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_type');

        $this->assertDatabaseHas('authorization_roles', [
            'id' => $role->id,
            'scope_type' => 'all',
        ]);
    }

    private function seededPermissionCountExcluding(int $roleId): int
    {
        return (int) \DB::table('authorization_role_permissions')->where('authorization_role_id', '!=', $roleId)->count();
    }
}
