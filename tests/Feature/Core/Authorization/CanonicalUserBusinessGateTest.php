<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalUserBusinessGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_requires_active_unexpired_system_role_at_all_scope(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $role = AuthorizationRole::query()->updateOrCreate(['name' => 'super_admin'], [
            'label' => 'Super admin',
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'is_admin_role' => true,
            'is_system' => true,
            'is_active' => true,
        ]);
        $assignment = $this->assign($user, $role, AuthorizationRoleAssignment::SCOPE_ALL);

        $this->assertTrue($user->isSuperAdmin());

        $assignment->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($user->isSuperAdmin());

        $assignment->update(['expires_at' => null, 'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION, 'scope_id' => $organization->id, 'organization_id' => $organization->id]);
        $this->assertFalse($user->isSuperAdmin());

        $assignment->update(['scope_type' => AuthorizationRoleAssignment::SCOPE_ALL, 'scope_id' => null, 'organization_id' => null]);
        $role->update(['is_system' => false]);
        $this->assertFalse($user->isSuperAdmin());

        $role->update(['is_system' => true, 'is_active' => false]);
        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_user_without_canonical_assignment_is_never_super_admin(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_malformed_assignment_whose_scope_differs_from_role_never_grants(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $role = AuthorizationRole::query()->create([
            'name' => 'malformed_admin',
            'label' => 'Malformed admin',
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'is_admin_role' => true,
            'is_active' => true,
        ]);
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $organization->id,
            'organization_id' => $organization->id,
            'source' => 'manual',
        ]);

        AccessDecision::flushUserCache($user->id);

        $this->assertFalse(AccessDecision::can($user->fresh(), Capability::PROJECTS_VIEW));
    }

    public function test_canonical_role_names_exclude_expired_and_inactive_definitions(): void
    {
        $user = User::factory()->create();
        $active = AuthorizationRole::create(['name' => 'active_role', 'label' => 'Active', 'is_active' => true]);
        $inactive = AuthorizationRole::create(['name' => 'inactive_role', 'label' => 'Inactive', 'is_active' => false]);
        $expired = AuthorizationRole::create(['name' => 'expired_role', 'label' => 'Expired', 'is_active' => true]);

        $this->assign($user, $active, AuthorizationRoleAssignment::SCOPE_ALL);
        $this->assign($user, $inactive, AuthorizationRoleAssignment::SCOPE_ALL);
        $this->assign($user, $expired, AuthorizationRoleAssignment::SCOPE_ALL, now()->subMinute());

        $this->assertSame(['active_role'], $user->canonicalRoleNames());
    }

    private function assign(
        User $user,
        AuthorizationRole $role,
        string $scopeType,
        mixed $expiresAt = null,
    ): AuthorizationRoleAssignment {
        return AuthorizationRoleAssignment::create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => $scopeType,
            'scope_id' => null,
            'organization_id' => null,
            'expires_at' => $expiresAt,
            'source' => 'manual',
        ]);
    }
}
