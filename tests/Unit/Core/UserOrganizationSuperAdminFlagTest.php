<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserOrganizationSuperAdminFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_organization_super_admin_returns_true_only_for_active_canonical_assignment(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertTrue($user->fresh()->isOrganizationSuperAdmin());
    }

    public function test_is_organization_super_admin_returns_false_when_no_assignment(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        $this->assertFalse($user->fresh()->isOrganizationSuperAdmin());
    }

    public function test_is_organization_super_admin_returns_false_when_assignment_is_inactive(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'organization_super_admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => false,
                'is_system' => true,
                'is_active' => true,
                'label' => 'Organization Super Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => false,
        ]);

        $this->assertFalse($user->fresh()->isOrganizationSuperAdmin());
    }

    public function test_is_organization_super_admin_returns_false_for_curated_admin_role(): void
    {
        // Legacy `admin` role must NOT be re-classified as Org-Super, even
        // though it shares scope_type=organization + is_admin_role=true.
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => 'admin'],
            [
                'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
                'is_admin_role' => true,
                'is_system' => false,
                'is_active' => true,
                'label' => 'Organization Admin',
            ],
        );
        AuthorizationRoleAssignment::query()->create([
            'user_id' => $user->id,
            'authorization_role_id' => $role->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $org->id,
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $this->assertFalse($user->fresh()->isOrganizationSuperAdmin());
    }
}
