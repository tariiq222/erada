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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserCanonicalAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_create_writes_only_canonical_assignments(): void
    {
        [$actor, $organization] = $this->authorizedActor();
        $role = $this->role('user-create-member');

        $response = $this->actingAs($actor, 'sanctum')->postJson('/api/users', [
            'name' => 'Canonical User',
            'email' => 'canonical-create@example.test',
            'password' => 'Password123!',
            'assignments' => [[
                'role_id' => $role->id,
                'scope_type' => 'organization',
                'scope_id' => $organization->id,
            ]],
        ], ['Idempotency-Key' => 'user-canonical-create']);

        $response->assertCreated();
        $userId = (int) $response->json('user.id');
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $role->id,
            'user_id' => $userId,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'source' => 'manual',
            'granted_by' => $actor->id,
        ]);
    }

    public function test_admin_update_atomically_replaces_manual_canonical_assignments(): void
    {
        [$actor, $organization] = $this->authorizedActor();
        $target = User::factory()->create(['organization_id' => $organization->id]);
        $oldRole = $this->role('user-update-old');
        $newRole = $this->role('user-update-new');
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $oldRole->id,
            'user_id' => $target->id,
            'organization_id' => $organization->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'source' => 'manual',
        ]);

        $response = $this->actingAs($actor, 'sanctum')->putJson("/api/users/{$target->id}", [
            'name' => 'Updated Canonical User',
            'assignments' => [[
                'role_id' => $newRole->id,
                'scope_type' => 'organization',
                'scope_id' => $organization->id,
            ]],
        ], ['Idempotency-Key' => 'user-canonical-update']);

        $response->assertOk()->assertJsonPath('user.name', 'Updated Canonical User');
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $oldRole->id,
            'user_id' => $target->id,
            'source' => 'manual',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $newRole->id,
            'user_id' => $target->id,
            'source' => 'manual',
        ]);
    }

    public function test_admin_create_and_update_reject_legacy_roles_payload(): void
    {
        [$actor, $organization] = $this->authorizedActor();
        $target = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/users', [
            'name' => 'Legacy Payload',
            'email' => 'legacy-payload@example.test',
            'password' => 'Password123!',
            'roles' => ['viewer'],
        ], ['Idempotency-Key' => 'user-legacy-create'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');

        $this->actingAs($actor, 'sanctum')->putJson("/api/users/{$target->id}", [
            'roles' => ['viewer'],
        ], ['Idempotency-Key' => 'user-legacy-update'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles');
    }

    /** @return array{User, Organization} */
    private function authorizedActor(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $organization->id]);
        $managerRole = $this->role('user-canonical-manager');

        foreach ([Capability::USERS_CREATE, Capability::USERS_EDIT, Capability::CORE_ASSIGN_ROLES] as $capability) {
            $mapping = CapabilityToAuthorizationRolePermission::map($capability);
            self::assertNotNull($mapping);
            $resource = AuthorizationResource::query()->firstOrCreate(
                ['key' => $mapping['resource']],
                ['label' => $mapping['resource']],
            );
            AuthorizationRolePermission::query()->create([
                'authorization_role_id' => $managerRole->id,
                'authorization_resource_id' => $resource->id,
                'action' => $mapping['action'],
            ]);
        }

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $managerRole->id,
            'user_id' => $actor->id,
            'organization_id' => $organization->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'source' => 'manual',
        ]);

        return [$actor, $organization];
    }

    private function role(string $name): AuthorizationRole
    {
        return AuthorizationRole::query()->create([
            'name' => $name,
            'label' => $name,
            'is_admin_role' => false,
            'is_active' => true,
        ]);
    }
}
