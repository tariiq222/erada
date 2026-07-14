<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOrgAdminFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_org_admin_returns_true_when_active_admin_assignment_with_org_scope(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            ['scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION, 'is_admin_role' => true, 'is_system' => false, 'is_active' => true, 'label' => 'Organization Admin']
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertTrue($user->fresh()->isOrgAdmin());
    }

    public function test_is_org_admin_returns_false_when_no_assignment(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $this->assertFalse($user->isOrgAdmin());
    }

    public function test_is_org_admin_returns_false_when_assignment_is_inactive(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            ['scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION, 'is_admin_role' => true, 'is_system' => false, 'is_active' => true, 'label' => 'Organization Admin']
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            // `authorization_role_assignments` has NO `is_active` column (see
            // migration 2026_07_12_000005_add_lifecycle_to_authorization_roles_and_assignments
            // — it adds only `expires_at` to the assignments table). The
            // lifecycle is honored by User::activeCanonicalRoleAssignments(),
            // which filters `expires_at > now()`. Modeled as an unambiguously
            // past immutable expiry so it is excluded from the active set.
            'expires_at' => now()->subSecond()->toImmutable(),
        ]);

        $this->assertFalse($user->fresh()->isOrgAdmin());
    }
}
