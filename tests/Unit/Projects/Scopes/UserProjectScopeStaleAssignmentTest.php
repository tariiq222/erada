<?php

namespace Tests\Unit\Projects\Scopes;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\Support\CanonicalAuthorizationFixtures;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * UserProjectScopeStaleAssignmentTest — CSD-CA23078-PROJECTS-001 regression.
 *
 * Locks in the stale-org filter added to UserProjectScope::canonicalGrantingScopes.
 *
 * Before the fix, a user moved from Org A to Org B kept their A-scoped
 * `PROJECTS_VIEW` assignment visible to canonicalGrantingScopes(). The resulting
 * `$engineScopes['organization']` entry made `$hasFlatAll === true`, so
 * apply() returned `$query` without any org floor and the user saw projects
 * from both A and B. After the fix, the stale row is dropped from the engine
 * scope query, $hasFlatAll becomes false, and the org floor narrows back to
 * the user's current organization.
 *
 * The exception (scope_type='all' + actor-is-canonical-super_admin) is
 * defensive — super_admin short-circuits in apply() and never reaches
 * canonicalGrantingScopes() in the read path. The test focuses on the
 * realistic path: a regular user with stale org/dept-scope assignments.
 */
class UserProjectScopeStaleAssignmentTest extends TestCase
{
    use CanonicalAuthorizationFixtures;
    use GrantsEngineCapability;
    use RefreshDatabase;

    private UserProjectScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = new UserProjectScope;
    }

    public function test_stale_org_scoped_assignment_is_dropped_after_org_transfer(): void
    {
        $orgA = Organization::factory()->create(['name' => 'org-a']);
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $orgB = Organization::factory()->create(['name' => 'org-b']);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);

        // Step 1 — sanity: while the user is in Org A, the org-scoped
        // PROJECTS_VIEW grant lands in canonicalGrantingScopes['organization']
        // and `hasFlatAll` is true. apply() returns the query unmodified (no
        // org floor) and the user sees all A projects.
        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            'organization',
            $orgA->id,
        );

        $scopes = $this->invokeCanonicalGrantingScopes($user, Capability::PROJECTS_VIEW);
        $this->assertNotEmpty(
            $scopes['organization'] ?? [],
            'precondition: while in Org A, the org-scoped grant populates organization[]',
        );
        $this->assertTrue(
            $this->hasFlatAllFromScopes($scopes),
            'precondition: hasFlatAll is true while user still in Org A',
        );

        // Step 2 — super_admin moves the user to Org B (the org-switch path
        // that triggered CSD-CA23078-PROJECTS-001).
        $user->update(['organization_id' => $orgB->id, 'department_id' => $deptB->id]);
        AccessDecision::flushCache();
        $userInB = $user->fresh();
        $this->assertSame(
            (int) $orgB->id,
            (int) $userInB->organization_id,
            'precondition: user was actually moved to Org B',
        );

        // Step 3 — primary assertion: the stale org-scoped assignment is
        // dropped from canonicalGrantingScopes. Both the 'organization' and
        // 'all' buckets must be empty (hasFlatAll is therefore false).
        $staleScopes = $this->invokeCanonicalGrantingScopes($userInB, Capability::PROJECTS_VIEW);
        $this->assertSame(
            [],
            $staleScopes['organization'] ?? [],
            'stale org-scoped assignment must be dropped after org transfer',
        );
        $this->assertSame(
            [],
            $staleScopes['all'] ?? [],
            'no all-scope rows must leak through (user is not a super_admin)',
        );
        $this->assertFalse(
            $this->hasFlatAllFromScopes($staleScopes),
            'hasFlatAll must become false after org transfer (stale org-scope is dropped)',
        );

        // Step 4 — assertion against the DB: the stale row still exists
        // (we did not delete it — the safety-net migration is responsible for
        // expiring rows in-place). This proves the filter is purely a read-time
        // gate, not a destructive operation.
        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $userInB->id,
            'scope_type' => 'organization',
            'scope_id' => $orgA->id,
            'organization_id' => $orgA->id,
        ]);
    }

    public function test_apply_returns_query_bounded_to_new_org_after_transfer(): void
    {
        $orgA = Organization::factory()->create(['name' => 'org-a']);
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $orgB = Organization::factory()->create(['name' => 'org-b']);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);

        // Give the user the org-scoped PROJECTS_VIEW grant while in A. Without
        // the stale filter, this would have widened apply() to all projects.
        $this->grantEngineCapability(
            $user,
            Capability::PROJECTS_VIEW,
            'organization',
            $orgA->id,
        );

        // Fixtures: a project in A (user is creator — would pass direct
        // relations if the org floor wasn't applied) and a project in B
        // (user has no direct relation but is in B's org).
        $projectInA = Project::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'created_by' => $user->id,
        ]);
        $projectInB = Project::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        // Move the user to Org B.
        $user->update(['organization_id' => $orgB->id, 'department_id' => $deptB->id]);
        AccessDecision::flushCache();
        $userInB = $user->fresh();

        $query = Project::query();
        $this->scope->apply($query, $userInB);
        $visibleIds = $query->pluck('id')->all();

        // Primary assertion: the A project is NOT visible (org floor is now
        // Org B only). The stale filter is what makes hasFlatAll false, which
        // is what keeps the org floor narrow. If the filter were broken,
        // apply() would return the query unmodified and BOTH projects would
        // be visible.
        $this->assertNotContains(
            $projectInA->id,
            $visibleIds,
            'A-org project must NOT be visible after org transfer — stale org-scoped assignment must not widen the floor',
        );
        $this->assertNotContains(
            $projectInB->id,
            $visibleIds,
            'B-org project with no direct relation is also not visible (no engine grant remains)',
        );

        // Cross-check via direct SQL: an A-org project created by the user is
        // still in the database — only the scope filter excludes it.
        $this->assertDatabaseHas('projects', [
            'id' => $projectInA->id,
            'organization_id' => $orgA->id,
            'created_by' => $user->id,
        ]);
    }

    public function test_stale_org_scoped_assignment_for_other_capability_is_also_dropped(): void
    {
        // The same filter sits on every canonicalGrantingScopes() call (any
        // capability key flows through the same join + filter). Prove the
        // behavior is not specific to PROJECTS_VIEW.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);

        $this->grantEngineCapability(
            $user,
            Capability::USERS_VIEW,
            'organization',
            $orgA->id,
        );

        $beforeTransfer = $this->invokeCanonicalGrantingScopes($user, Capability::USERS_VIEW);
        $this->assertNotEmpty($beforeTransfer['organization'] ?? []);

        $user->update(['organization_id' => $orgB->id]);
        AccessDecision::flushCache();

        $afterTransfer = $this->invokeCanonicalGrantingScopes($user->fresh(), Capability::USERS_VIEW);
        $this->assertSame(
            [],
            $afterTransfer['organization'] ?? [],
            'stale org-scoped USERS_VIEW assignment must also be dropped',
        );
    }

    public function test_super_admin_exception_keeps_all_scope_row_visible(): void
    {
        // Defensive: a canonical super_admin row with a stale organization_id
        // is exempted by the scope_type='all' + actor-is-super_admin branch.
        // super_admin short-circuits in apply() so this is rarely exercised
        // at runtime, but the read-time filter must mirror the engine.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $superAdmin = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);

        // Grant the super_admin via the canonical fixtures (creates the role,
        // the role_permission pivot, and the all-scope assignment with
        // organization_id=null). Then we manually re-stamp organization_id=A
        // to simulate the rare "all-scope with a stale organization_id" case
        // the exception protects.
        $this->grantCanonicalSuperAdmin($superAdmin);
        AuthorizationRoleAssignment::query()
            ->where('user_id', $superAdmin->id)
            ->update(['organization_id' => $orgA->id]);
        AccessDecision::flushCache();

        $scopes = $this->invokeCanonicalGrantingScopes($superAdmin->fresh(), Capability::PROJECTS_VIEW);
        $this->assertNotEmpty(
            $scopes['all'] ?? [],
            'super_admin exception: all-scope row with stale organization_id must still pass',
        );
    }

    /**
     * @return array<string, list<int>>
     */
    private function invokeCanonicalGrantingScopes(User $user, string $capability): array
    {
        $reflection = new ReflectionClass(UserProjectScope::class);
        $method = $reflection->getMethod('canonicalGrantingScopes');
        $method->setAccessible(true);

        return $method->invoke($this->scope, $user, $capability);
    }

    /**
     * @param  array<string, list<int>>  $scopes
     */
    private function hasFlatAllFromScopes(array $scopes): bool
    {
        return ! empty($scopes['organization'] ?? [])
            || ! empty($scopes['all'] ?? []);
    }
}
