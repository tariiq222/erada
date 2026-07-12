<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class CanonicalListScopeProjectionTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_organization_assignment_with_all_reach_projects_the_organization(): void
    {
        [$organization, $user] = $this->organizationUser();

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $organization->id,
            reach: ['projects' => 'all'],
        );

        $this->assertTrue(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
        $this->assertSame(
            ['organization' => [$organization->id]],
            AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW),
        );
    }

    public function test_organization_assignment_with_department_reach_projects_the_users_department(): void
    {
        [$organization, $user, $department] = $this->organizationUser();

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $organization->id,
            reach: ['projects' => 'department'],
        );

        $this->assertFalse(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
        $this->assertSame(
            ['department' => [$department->id]],
            AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW),
        );
    }

    public function test_own_reach_contributes_no_positional_scope(): void
    {
        [$organization, $user] = $this->organizationUser();

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_DELETE,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $organization->id,
            reach: ['projects' => 'own'],
        );

        $this->assertFalse(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_DELETE));
        $this->assertSame([], AccessDecision::grantingScopes($user, Capability::PROJECTS_DELETE));
    }

    public function test_scoped_assignments_project_their_scope_id_for_both_inheritance_modes(): void
    {
        [$organization, $user] = $this->organizationUser();
        $direct = Department::factory()->create(['organization_id' => $organization->id]);
        $inheriting = Department::factory()->create(['organization_id' => $organization->id]);

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            (int) $direct->getKey(),
            roleKey: 'direct_department_viewer',
            definitionFlags: ['inherit_to_children' => false],
            reach: ['projects' => 'all'],
        );
        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            (int) $inheriting->getKey(),
            roleKey: 'inheriting_department_viewer',
            definitionFlags: ['inherit_to_children' => true],
            reach: ['projects' => 'all'],
        );

        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => (int) $direct->getKey(),
            'inherit_to_children' => false,
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_DEPARTMENT,
            'scope_id' => (int) $inheriting->getKey(),
            'inherit_to_children' => true,
        ]);
        $this->assertFalse(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
        $this->assertSame(
            ['department' => [(int) $direct->getKey(), (int) $inheriting->getKey()]],
            AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW),
        );
    }

    public function test_expired_assignment_is_excluded_from_both_helpers(): void
    {
        [$organization, $user] = $this->organizationUser();

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $organization->id,
            reach: ['projects' => 'all'],
        );
        $updated = AuthorizationRoleAssignment::query()->where('user_id', $user->id)->update([
            'expires_at' => now()->subMinute(),
        ]);
        AccessDecision::flushCache();

        $this->assertGreaterThan(0, $updated);
        $this->assertSame(
            0,
            AuthorizationRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
        );
        $this->assertFalse(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
        $this->assertSame([], AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW));
    }

    public function test_inactive_role_is_excluded_from_both_helpers(): void
    {
        [$organization, $user] = $this->organizationUser();

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $organization->id,
            reach: ['projects' => 'all'],
        );
        $assignment = AuthorizationRoleAssignment::query()
            ->with('role')
            ->where('user_id', $user->id)
            ->firstOrFail();
        $assignment->role->update(['is_active' => false]);

        $this->assertFalse(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
        $this->assertSame([], AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW));
    }

    public function test_cross_organization_assignment_is_excluded_fail_closed(): void
    {
        [$organization, $user] = $this->organizationUser();
        $otherOrganization = Organization::factory()->create();

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            $otherOrganization->id,
            reach: ['projects' => 'all'],
        );

        $this->assertNotSame($organization->id, $otherOrganization->id);
        $this->assertFalse(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
        $this->assertSame([], AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW));
    }

    public function test_all_scope_grants_at_organization_and_projects_the_users_organization(): void
    {
        [$organization, $user] = $this->organizationUser();

        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            AuthorizationRoleAssignment::SCOPE_ALL,
            reach: ['projects' => 'all'],
        );

        $this->assertTrue(AccessDecision::grantsAtOrganization($user, Capability::PROJECTS_VIEW));
        $this->assertSame(
            ['organization' => [$organization->id]],
            AccessDecision::grantingScopes($user, Capability::PROJECTS_VIEW),
        );
    }

    /** @return array{Organization, User, Department} */
    private function organizationUser(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => (int) $department->getKey(),
            'is_active' => true,
        ]);

        return [$organization, $user, $department];
    }
}
