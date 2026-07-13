<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Core\Authorization\Support\CapabilityToAuthorizationRolePermission;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalRoleRetirementSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_super_admin_may_use_the_explicit_global_exception(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $subject = User::factory()->create(['is_active' => true]);
        $superAdmin = AuthorizationRole::query()->where('name', 'super_admin')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $superAdmin->id,
            'user_id' => $actor->id,
            'scope_type' => 'all',
            'scope_id' => null,
            'source' => 'manual',
            'granted_by' => $actor->id,
        ]);
        $source = $this->role('retire-source-global', scopeType: 'all');
        $replacement = $this->role('retire-target-global', scopeType: 'all');
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $source->id,
            'user_id' => $subject->id,
            'scope_type' => 'all',
            'scope_id' => null,
            'source' => 'manual',
            'granted_by' => $actor->id,
        ]);
        AccessDecision::flushUserCache($actor->id);
        $this->assertTrue(app(AuthorizationAssignmentActorGuard::class)->allows(
            $actor,
            $subject,
            $replacement,
            new AssignmentScope('all', null),
        ));

        $response = $this->actingAs($actor, 'sanctum')->deleteJson("/api/roles/{$source->id}", [
            'reassign_to_role_id' => $replacement->id,
        ]);

        $this->assertSame(200, $response->status(), $response->content());
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $replacement->id,
            'user_id' => $subject->id,
            'scope_type' => 'all',
        ]);
    }

    public function test_role_manager_cannot_retire_a_role_into_an_admin_role(): void
    {
        [$actor, $subject, $organization] = $this->roleManager();
        $source = $this->role('retire-source-admin');
        $replacement = $this->role('retire-admin-target', admin: true);
        $this->assignment($source, $subject, $organization, $actor);

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/roles/{$source->id}", [
            'reassign_to_role_id' => $replacement->id,
        ])->assertForbidden();

        $this->assertTrue($source->fresh()->is_active);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $source->id,
            'user_id' => $subject->id,
        ]);
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $replacement->id,
            'user_id' => $subject->id,
        ]);
    }

    public function test_role_manager_cannot_retire_a_role_into_a_stronger_role(): void
    {
        [$actor, $subject, $organization] = $this->roleManager();
        $source = $this->role('retire-source-stronger');
        $replacement = $this->role('retire-stronger-target');
        $this->grant($replacement, Capability::PROJECTS_DELETE);
        $this->assignment($source, $subject, $organization, $actor);

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/roles/{$source->id}", [
            'reassign_to_role_id' => $replacement->id,
        ])->assertForbidden();

        $this->assertTrue($source->fresh()->is_active);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $source->id,
            'user_id' => $subject->id,
        ]);
    }

    public function test_safe_replacement_preserves_assignment_scope_provenance_and_lifecycle(): void
    {
        [$actor, $subject, $organization] = $this->roleManager();
        $source = $this->role('retire-source-safe');
        $replacement = $this->role('retire-safe-target');
        $this->grant($replacement, Capability::PROJECTS_VIEW);
        $assignment = $this->assignment($source, $subject, $organization, $actor, expiresAt: now()->addWeek());
        $original = $assignment->only([
            'user_id',
            'scope_type',
            'scope_id',
            'organization_id',
            'inherit_to_children',
            'expires_at',
            'source',
            'granted_by',
        ]);
        $originalExpiry = \DB::table('authorization_role_assignments')->where('id', $assignment->id)->value('expires_at');

        $this->actingAs($actor, 'sanctum')->deleteJson("/api/roles/{$source->id}", [
            'reassign_to_role_id' => $replacement->id,
        ])->assertOk();

        $moved = $assignment->fresh();
        $this->assertSame($replacement->id, $moved->authorization_role_id);
        foreach ($original as $column => $value) {
            if ($column === 'expires_at') {
                continue;
            }
            $this->assertEquals($value, $moved->{$column}, "The {$column} assignment field changed during retirement.");
        }
        $this->assertEquals(
            $originalExpiry,
            \DB::table('authorization_role_assignments')->where('id', $assignment->id)->value('expires_at'),
        );
        $this->assertFalse($source->fresh()->is_active);
    }

    /** @return array{User, User, Organization} */
    private function roleManager(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $organization->id, 'is_active' => true]);
        $subject = User::factory()->create(['organization_id' => $organization->id, 'is_active' => true]);
        $manager = $this->role('retirement-manager-'.str()->random(8));

        foreach ([Capability::ROLES_DELETE, Capability::CORE_ASSIGN_ROLES, Capability::PROJECTS_VIEW] as $capability) {
            $this->grant($manager, $capability);
        }

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $manager->id,
            'user_id' => $actor->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'source' => 'manual',
            'granted_by' => $actor->id,
        ]);
        AccessDecision::flushUserCache($actor->id);

        return [$actor, $subject, $organization];
    }

    private function role(string $name, bool $admin = false, string $scopeType = 'organization'): AuthorizationRole
    {
        return AuthorizationRole::query()->create([
            'name' => $name,
            'label' => $name,
            'scope_type' => $scopeType,
            'is_admin_role' => $admin,
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    private function grant(AuthorizationRole $role, string $capability): void
    {
        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        self::assertNotNull($mapping);
        $resource = AuthorizationResource::query()->firstOrCreate(
            ['key' => $mapping['resource']],
            ['label' => $mapping['resource']],
        );
        AuthorizationRolePermission::query()->create([
            'authorization_role_id' => $role->id,
            'authorization_resource_id' => $resource->id,
            'action' => $mapping['action'],
        ]);
    }

    private function assignment(
        AuthorizationRole $role,
        User $subject,
        Organization $organization,
        User $actor,
        mixed $expiresAt = null,
    ): AuthorizationRoleAssignment {
        return AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'inherit_to_children' => true,
            'expires_at' => $expiresAt,
            'source' => 'manual',
            'granted_by' => $actor->id,
        ]);
    }
}
