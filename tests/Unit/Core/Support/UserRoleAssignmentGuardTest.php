<?php

namespace Tests\Unit\Core\Support;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\UserRoleAssignmentGuard;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Unit tests for UserRoleAssignmentGuard — the unified cross-org + escalation
 * guard that UserController::store/update and RoleController::assignToUser
 * call before any role write.
 *
 * Semantics (per Phase 3 v3 plan):
 *   1. super_admin in roles + actor not super_admin ⇒ 403 (NOT silent strip)
 *   2. super_admin bypass: actor super_admin ⇒ allow (per RoleHierarchy)
 *   3. null-org actor ⇒ 403
 *   4. cross-org target ⇒ 403
 *   5. null-org target + actor not super_admin ⇒ 403
 *   6. self-escalation ⇒ 403 if any role is higher than current
 *   7. invalid role_key ⇒ 403
 *   8. RoleHierarchy::canAssignAll denies ⇒ 403
 *   9. empty roles ⇒ no-op (pass)
 */
class UserRoleAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    private UserRoleAssignmentGuard $guard;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->guard = new UserRoleAssignmentGuard;

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function makeUser(string $role, Organization $org, ?Department $dept = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept?->id,
            'is_active' => true,
        ]);

        if ($role === 'super_admin') {
            $this->grantCanonicalSuperAdmin($user);
        } elseif ($role !== 'norole') {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    public function test_empty_roles_is_no_op(): void
    {
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->guard->assertCanAssign($actor, $target, []);

        // No exception = pass.
        $this->assertTrue(true);
    }

    public function test_same_org_assignment_allowed_with_capability(): void
    {
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        // admin can assign viewer (RoleHierarchy: admin > viewer).
        $this->guard->assertCanAssign($actor, $target, ['viewer']);

        $this->assertTrue(true);
    }

    public function test_admin_can_assign_admin_role_to_same_org(): void
    {
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->guard->assertCanAssign($actor, $target, ['admin']);

        $this->assertTrue(true);
    }

    public function test_cross_org_assignment_denied(): void
    {
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('المستخدم خارج نطاق مؤسستك');

        $this->guard->assertCanAssign($actor, $target, ['viewer']);
    }

    public function test_admin_cannot_assign_super_admin(): void
    {
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('super_admin');

        $this->guard->assertCanAssign($actor, $target, ['super_admin']);
    }

    public function test_viewer_cannot_escalate_self_to_admin(): void
    {
        $actor = $this->makeUser('viewer', $this->orgA, $this->deptA);
        $target = $actor; // self

        $this->expectException(AccessDeniedHttpException::class);
        // self-escalation check is "level > actor max level"; viewer level=0 and
        // admin level=0 — same level, so step 6 doesn't fire. Step 8 (RoleHierarchy)
        // is the one that blocks a viewer from granting any functional role.
        $this->expectExceptionMessage('لا تملك صلاحية');

        $this->guard->assertCanAssign($actor, $target, ['admin']);
    }

    public function test_self_escalation_strictly_higher_level_caught_by_super_admin_guard(): void
    {
        // super_admin (level=3) is the only role higher than admin/viewer (level=0).
        // The super_admin escalation guard (step 1) fires BEFORE the self-escalation
        // check (step 6) and produces the dedicated "super_admin" message. Step 6 is
        // the defensive layer for FUTURE levels > 3 that don't exist today.
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $actor;

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('super_admin');

        $this->guard->assertCanAssign($actor, $target, ['super_admin']);
    }

    public function test_self_no_escalation_when_roles_match(): void
    {
        // admin assigning viewer to themselves = no escalation (admin > viewer via RoleHierarchy).
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $actor;

        $this->guard->assertCanAssign($actor, $target, ['viewer']);

        $this->assertTrue(true);
    }

    public function test_null_org_actor_denied(): void
    {
        $nullOrgActor = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('بدون مؤسسة');

        $this->guard->assertCanAssign($nullOrgActor, $target, ['viewer']);
    }

    public function test_null_org_target_denied_for_non_super_admin(): void
    {
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $nullOrgTarget = User::factory()->create([
            'organization_id' => null,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('بلا مؤسسة');

        $this->guard->assertCanAssign($actor, $nullOrgTarget, ['viewer']);
    }

    public function test_super_admin_can_assign_across_orgs(): void
    {
        $superAdmin = $this->makeUser('super_admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        // super_admin bypasses org check + can assign super_admin to anyone.
        $this->guard->assertCanAssign($superAdmin, $target, ['viewer', 'admin']);

        $this->assertTrue(true);
    }

    public function test_super_admin_can_assign_super_admin_role(): void
    {
        $superAdmin = $this->makeUser('super_admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('admin', $this->orgA, $this->deptA);

        $this->guard->assertCanAssign($superAdmin, $target, ['super_admin']);

        $this->assertTrue(true);
    }

    public function test_super_admin_with_null_org_allowed(): void
    {
        // super_admin bypass — should work even if actor.org is null.
        $superAdmin = User::factory()->create([
            'organization_id' => null,
            'department_id' => null,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        $this->guard->assertCanAssign($superAdmin, $target, ['viewer']);

        $this->assertTrue(true);
    }

    public function test_invalid_role_key_rejected(): void
    {
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->expectException(AccessDeniedHttpException::class);

        $this->guard->assertCanAssign($actor, $target, ['nonexistent_role']);
    }

    public function test_role_hierarchy_blocks_viewer_assigning_functional_roles(): void
    {
        $actor = $this->makeUser('viewer', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('لا تملك صلاحية');

        // viewer cannot grant admin/functional roles — RoleHierarchy::canAssignAll denies.
        $this->guard->assertCanAssign($actor, $target, ['admin']);
    }

    public function test_roles_payload_not_mutated(): void
    {
        // Critical: Guard must not silently strip super_admin or any other role.
        $actor = $this->makeUser('admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgA, $this->deptA);

        $payload = ['super_admin'];
        $payloadCopy = $payload;

        try {
            $this->guard->assertCanAssign($actor, $target, $payload);
            $this->fail('Expected AccessDeniedHttpException');
        } catch (AccessDeniedHttpException $e) {
            // payload must be unchanged after the call.
            $this->assertSame($payloadCopy, $payload);
        }
    }

    public function test_assignable_role_key_uses_compat_spatie_set(): void
    {
        // super_admin/admin/viewer are valid compat roles — AssignableRoleKey accepts them.
        $actor = $this->makeUser('super_admin', $this->orgA, $this->deptA);
        $target = $this->makeUser('viewer', $this->orgB, $this->deptB);

        // super_admin bypasses; assigning compat roles to cross-org target should pass.
        $this->guard->assertCanAssign($actor, $target, ['admin', 'viewer']);

        $this->assertTrue(true);
    }
}
