<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CanonicalRoleAssignmentEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_atomically_assigns_explicit_canonical_scopes_without_legacy_writes(): void
    {
        [$actor, $subject, $organization] = $this->authorizedUsers();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $role = $this->role('endpoint-member', scopeType: 'department');
        $expiry = now()->addDay()->startOfSecond();

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/roles/assign', [
            'user_id' => $subject->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'department',
                'scope_id' => $department->id,
                'inherit_to_children' => true,
                'expires_at' => $expiry->toIso8601String(),
            ]],
        ], ['Idempotency-Key' => 'canonical-assignment-success']);

        $response->assertOk()
            ->assertJsonPath('data.user_id', $subject->id)
            ->assertJsonPath('data.assignments.0.role_id', $role->id)
            ->assertJsonPath('data.assignments.0.scope_type', 'department')
            ->assertJsonPath('data.assignments.0.scope_id', $department->id)
            ->assertJsonPath('data.assignments.0.inherit_to_children', true);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
            'scope_type' => 'department',
            'scope_id' => $department->id,
            'organization_id' => $organization->id,
            'source' => 'manual',
            'granted_by' => $actor->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $actor->id,
            'target_user_id' => $subject->id,
            'action' => 'system_role_assigned',
        ]);
    }

    public function test_it_returns_validation_errors_without_partial_writes(): void
    {
        [$actor, $subject] = $this->authorizedUsers();
        $role = $this->role('endpoint-validation');

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/roles/assign', [
            'user_id' => $subject->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'organization',
                'scope_id' => null,
            ]],
        ], ['Idempotency-Key' => 'canonical-assignment-invalid']);

        $response->assertUnprocessable()->assertJsonValidationErrors('assignments.0.scope_id');
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
        ]);
    }

    public function test_guard_denial_rolls_back_every_assignment_in_the_request(): void
    {
        [$actor, $subject] = $this->authorizedUsers();
        $regularRole = $this->role('endpoint-regular', scopeType: 'own');
        $adminRole = $this->role('endpoint-admin', true, 'own');

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/roles/assign', [
            'user_id' => $subject->id,
            'replace_all' => true,
            'assignments' => [
                ['role_id' => $regularRole->id, 'scope_type' => 'own', 'scope_id' => null],
                ['role_id' => $adminRole->id, 'scope_type' => 'own', 'scope_id' => null],
            ],
        ], ['Idempotency-Key' => 'canonical-assignment-rollback']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $subject->id,
            'authorization_role_id' => $regularRole->id,
        ]);
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'user_id' => $subject->id,
            'authorization_role_id' => $adminRole->id,
        ]);
    }

    public function test_organization_role_manager_cannot_grant_global_all_scope(): void
    {
        [$actor, $subject] = $this->authorizedUsers();
        $role = $this->role('endpoint-global-regular', scopeType: 'all');

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/roles/assign', [
            'user_id' => $subject->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'all',
                'scope_id' => null,
            ]],
        ], ['Idempotency-Key' => 'canonical-assignment-global-denied']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
        ]);
    }

    public function test_manual_sync_never_converts_or_clears_matching_automatic_assignment(): void
    {
        [$actor, $subject, $organization] = $this->authorizedUsers();
        $role = $this->role('endpoint-auto-preserved');
        AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'source' => 'auto',
        ]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/roles/assign', [
            'user_id' => $subject->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'organization',
                'scope_id' => $organization->id,
            ]],
        ], ['Idempotency-Key' => 'canonical-assignment-auto-collision'])->assertOk();

        $this->actingAs($actor, 'sanctum')->postJson('/api/roles/assign', [
            'user_id' => $subject->id,
            'replace_all' => true,
            'assignments' => [],
        ], ['Idempotency-Key' => 'canonical-assignment-auto-clear'])->assertOk();

        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'source' => 'auto',
        ]);
    }

    public function test_role_manager_cannot_delegate_role_assignment_capability(): void
    {
        [$actor, $subject, $organization] = $this->authorizedUsers();
        $role = $this->role('endpoint-peer-manager');
        $mapping = CapabilityToAuthorizationRolePermission::map(Capability::CORE_ASSIGN_ROLES);
        self::assertNotNull($mapping);
        $resource = AuthorizationResource::firstOrCreate(
            ['key' => $mapping['resource']],
            ['label' => 'User'],
        );
        AuthorizationRolePermission::create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => $mapping['action'],
        ]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/roles/assign', [
            'user_id' => $subject->id,
            'replace_all' => true,
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'organization',
                'scope_id' => $organization->id,
            ]],
        ], ['Idempotency-Key' => 'canonical-assignment-peer-denied'])
            ->assertForbidden();

        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
        ]);
    }

    /** @return array{User, User, Organization} */
    private function authorizedUsers(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $organization->id]);
        $subject = User::factory()->create(['organization_id' => $organization->id]);
        $managerRole = $this->role('endpoint-role-manager');
        $mapping = CapabilityToAuthorizationRolePermission::map(Capability::CORE_ASSIGN_ROLES);
        self::assertNotNull($mapping);
        $resource = AuthorizationResource::query()->firstOrCreate(
            ['key' => $mapping['resource']],
            ['label' => 'Organization'],
        );
        AuthorizationRolePermission::query()->create([
            'authorization_role_id' => $managerRole->id,
            'authorization_resource_id' => $resource->id,
            'action' => $mapping['action'],
        ]);
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $managerRole->id,
            'user_id' => $actor->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'source' => 'manual',
        ]);

        return [$actor, $subject, $organization];
    }

    private function role(string $name, bool $admin = false, string $scopeType = 'organization'): AuthorizationRole
    {
        return AuthorizationRole::query()->create([
            'name' => $name,
            'label' => $name,
            'scope_type' => $scopeType,
            'is_admin_role' => $admin,
            'is_active' => true,
        ]);
    }
}
