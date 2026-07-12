<?php

namespace Tests\Unit\Core\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Authorization\Services\AssignmentScopeResolver;
use App\Modules\Core\Authorization\Services\CanonicalAuthorizationAssignmentActorGuard;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for CanonicalAuthorizationAssignmentActorGuard.
 *
 * Focus: the guard's super-admin detection (private isCanonicalSuperAdmin)
 * is invoked whenever an actor attempts to assign a role at
 * AssignmentScope::ALL. The detection must mirror User::isSuperAdmin() —
 * the role's declared scope_type MUST equal
 * AuthorizationRoleAssignment::SCOPE_ALL. A role that declares
 * scope_type='organization' but whose assignment is shaped
 * scope_type=all/scope_id=null MUST NOT escalate the actor to system-wide
 * super admin.
 */
class CanonicalAuthorizationAssignmentActorGuardTest extends TestCase
{
    use RefreshDatabase;

    private CanonicalAuthorizationAssignmentActorGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new CanonicalAuthorizationAssignmentActorGuard(new AssignmentScopeResolver);
    }

    private function makeRole(string $name, string $scopeType): AuthorizationRole
    {
        return AuthorizationRole::query()->updateOrCreate(['name' => $name], [
            'label' => $name,
            'scope_type' => $scopeType,
            'is_admin_role' => true,
            'is_system' => $name === 'super_admin',
            'is_active' => true,
        ]);
    }

    private function makeActor(): User
    {
        $organization = Organization::factory()->create();

        return User::factory()->create(['organization_id' => $organization->id]);
    }

    public function test_canonical_super_admin_at_all_scope_passes_assignment_at_all(): void
    {
        $actor = $this->makeActor();
        $subject = $this->makeActor();

        $role = $this->makeRole('super_admin', AuthorizationRoleAssignment::SCOPE_ALL);
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $actor->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'source' => 'manual',
        ]);

        AccessDecision::flushUserCache($actor->id);

        $this->assertTrue(
            $this->guard->allows(
                $actor,
                $subject,
                $role,
                new AssignmentScope(AuthorizationRoleAssignment::SCOPE_ALL, null),
            ),
            'Canonical super_admin (role.scope_type=all + assignment scope_type=all/null) must pass AssignmentScope::ALL.',
        );
    }

    public function test_malformed_super_admin_role_with_non_all_declared_scope_is_not_canonical(): void
    {
        $actor = $this->makeActor();
        $subject = $this->makeActor();

        // Malformed: role's declared scope_type is 'organization', but the
        // assignment is shaped scope_type=all + scope_id=null. The current
        // guard (pre-fix) returns true because it only checks the assignment
        // shape and role.name='super_admin'. After the fix, the role's
        // declared scope_type MUST equal 'all'.
        $role = $this->makeRole('super_admin', AuthorizationRoleAssignment::SCOPE_ORGANIZATION);
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $actor->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'source' => 'manual',
        ]);

        AccessDecision::flushUserCache($actor->id);

        $this->assertFalse(
            $this->guard->allows(
                $actor,
                $subject,
                $role,
                new AssignmentScope(AuthorizationRoleAssignment::SCOPE_ALL, null),
            ),
            'A super_admin role whose declared scope_type != "all" must NOT be treated as canonical super admin even when its assignment is shaped all/null.',
        );
    }

    public function test_user_without_assignment_is_not_canonical_super_admin(): void
    {
        $actor = $this->makeActor();
        $subject = $this->makeActor();

        $role = $this->makeRole('super_admin', AuthorizationRoleAssignment::SCOPE_ALL);

        AccessDecision::flushUserCache($actor->id);

        $this->assertFalse(
            $this->guard->allows(
                $actor,
                $subject,
                $role,
                new AssignmentScope(AuthorizationRoleAssignment::SCOPE_ALL, null),
            ),
            'An actor with no super_admin assignment cannot assign at AssignmentScope::ALL.',
        );
    }
}
