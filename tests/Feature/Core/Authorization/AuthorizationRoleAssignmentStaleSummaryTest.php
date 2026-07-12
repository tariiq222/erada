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
use Tests\Support\CanonicalAuthorizationFixtures;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * AuthorizationRoleAssignmentStaleSummaryTest — CSD-CA23078-CORE-002 regression.
 *
 * Locks in the stale-org filter on
 * AuthorizationRoleAssignmentController::canonicalAssignmentSummaries() — the
 * read-only access view returned by GET /api/authorization-role-assignments/user/{user}.
 *
 * Before the fix, a user moved from Org A to Org B still had their A-scoped
 * assignments returned with `scope_name` set (the department/project/org name),
 * which misled super_admin viewers into thinking the assignment was still
 * active. After the fix, stale rows are dropped entirely (not emitted with
 * a `__stale: true` marker) for consistency with the engine filter, and the
 * safety-net migration expires the underlying row in-place.
 */
class AuthorizationRoleAssignmentStaleSummaryTest extends TestCase
{
    use CanonicalAuthorizationFixtures;
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_stale_org_scoped_assignment_is_excluded_from_user_assignments_endpoint(): void
    {
        $orgA = Organization::factory()->create(['name' => 'org-a']);
        $deptA = Department::factory()->create([
            'organization_id' => $orgA->id,
            'name' => 'department-a',
        ]);
        $orgB = Organization::factory()->create(['name' => 'org-b']);

        $superAdmin = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $target = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);

        // While the target is in Org A, grant them a department-scoped
        // PROJECTS_VIEW. The grant trait snapshots organization_id=A onto the
        // assignment row (denormalized convenience).
        $this->grantEngineCapability(
            $target,
            Capability::PROJECTS_VIEW,
            'department',
            $deptA->id,
        );

        // Sanity precondition: while the target is in Org A, the assignment
        // appears in the response and carries `department-a` as scope_name.
        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.0.scope_type',
                'department',
            )
            ->assertJsonPath(
                'data.0.scope_id',
                $deptA->id,
            )
            ->assertJsonPath(
                'data.0.scope_name',
                'department-a',
            )
            ->assertJsonPath(
                'data.0.organization_id',
                $orgA->id,
            );

        // Step 2 — super_admin moves the target user from Org A to Org B.
        // The assignment row's organization_id (A) is now stale relative to
        // the user's current organization_id (B).
        $target->update(['organization_id' => $orgB->id]);
        $targetInB = $target->fresh();
        $this->assertSame(
            (int) $orgB->id,
            (int) $targetInB->organization_id,
            'precondition: target user was actually moved to Org B',
        );

        // Primary assertion: GET /api/authorization-role-assignments/user/{user}
        // must NOT return the A-department assignment. The endpoint is for a
        // super_admin viewer, but the filter still applies because the target
        // is NOT a super_admin (and is not scope_type='all').
        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$targetInB->id}")
            ->assertOk()
            ->json('data');

        $this->assertIsArray($response);
        $this->assertSame(
            [],
            array_values(array_filter(
                $response,
                fn (array $row): bool => (int) $row['scope_id'] === (int) $deptA->id,
            )),
            'stale A-department assignment must be dropped from the response (decision: drop entirely, not __stale: true)',
        );

        // Cross-check: the underlying row is still in the database (the
        // endpoint filter is a read-time gate; the safety-net migration is
        // responsible for expiring the row).
        $this->assertDatabaseHas('authorization_role_assignments', [
            'user_id' => $targetInB->id,
            'scope_type' => 'department',
            'scope_id' => $deptA->id,
            'organization_id' => $orgA->id,
        ]);
    }

    public function test_stale_org_scoped_assignment_is_excluded_from_access_summary_endpoint(): void
    {
        $orgA = Organization::factory()->create();
        $deptA = Department::factory()->create([
            'organization_id' => $orgA->id,
            'name' => 'a-dept',
        ]);
        $orgB = Organization::factory()->create();

        $superAdmin = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        $target = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $target,
            Capability::PROJECTS_VIEW,
            'department',
            $deptA->id,
        );

        $target->update(['organization_id' => $orgB->id]);
        $targetInB = $target->fresh();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$targetInB->id}/access-summary")
            ->assertOk()
            ->json('data.assignments');

        $this->assertIsArray($response);
        $this->assertSame(
            [],
            array_values(array_filter(
                $response,
                fn (array $row): bool => (int) $row['scope_id'] === (int) $deptA->id,
            )),
            'stale A-department assignment must be excluded from the access-summary endpoint',
        );
    }

    public function test_super_admin_can_still_see_all_scope_row_for_target(): void
    {
        // The scope_type='all' + actor-is-super_admin exception: when the
        // actor making the request is a canonical super_admin, all-scope
        // rows on the target pass the filter even if their organization_id
        // is stale (this is rare because all-scope rows usually have a null
        // organization_id, but the exception mirrors the canonical rule).
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $superAdmin = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($superAdmin);

        // Target: a regular user in Org B who also happens to hold an
        // all-scope role assignment whose organization_id is stale (set to
        // Org A). This is the rare "all-scope with stale organization_id"
        // scenario the exception protects.
        $target = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_active' => true,
        ]);

        $viewerRole = $this->makeRoleWithCapability('test-viewer-all', Capability::PROJECTS_VIEW);
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $viewerRole->id,
            'user_id' => $target->id,
            'scope_type' => 'all',
            'scope_id' => null,
            'organization_id' => $orgA->id, // stale (target is in B)
            'inherit_to_children' => false,
            'expires_at' => null,
            'source' => 'manual',
            'granted_by' => null,
        ]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/authorization-role-assignments/user/{$target->id}")
            ->assertOk()
            ->json('data');

        $allRows = array_values(array_filter(
            $response,
            fn (array $row): bool => $row['scope_type'] === 'all',
        ));
        $this->assertNotEmpty(
            $allRows,
            'super_admin viewer must still see the all-scope row for the target via the exception',
        );
    }

    private function makeRoleWithCapability(string $name, string $capability): AuthorizationRole
    {
        $mapping = CapabilityToAuthorizationRolePermission::map($capability);
        $this->assertNotNull($mapping, "no canonical mapping for capability [{$capability}]");

        $resource = AuthorizationResource::query()->firstOrCreate(
            ['key' => $mapping['resource']],
            ['label' => $mapping['resource']],
        );

        $role = AuthorizationRole::query()->updateOrCreate(
            ['name' => $name],
            [
                'label' => $name,
                'label_ar' => $name,
                'label_en' => $name,
                'scope_type' => 'all',
                'is_admin_role' => false,
                'is_system' => false,
                'is_active' => true,
            ],
        );

        AuthorizationRolePermission::query()->updateOrCreate(
            [
                'authorization_role_id' => $role->id,
                'authorization_resource_id' => $resource->id,
                'action' => $mapping['action'],
            ],
            ['reach' => null],
        );

        return $role;
    }
}
