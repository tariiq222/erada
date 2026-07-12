<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * OrgIsolationInvariantTest — Phase 0, Task 4.
 *
 * Proves the organization-isolation invariant survives the new subtree-expansion
 * and owner-floor mechanisms: a user in Org A gets can=false AND an empty list for
 * an Org B record, even with a stray cross-org scoped-role row that (pathologically)
 * references an Org B department and would otherwise grant projects.view.
 *
 * The org gate is the OUTERMOST gate: in can() it returns false at sameOrganization
 * before the owner floor or any role check runs; in the list it prefilters
 * where organization_id = A before subtree expansion, so a stray cross-org scope_id
 * is inert.
 */
class OrgIsolationInvariantTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_cross_org_record_is_invisible_even_with_stray_role_row(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $a->id]);
        $deptB = Department::factory()->create(['organization_id' => $b->id]);
        $projectB = Project::factory()->create([
            'organization_id' => $b->id,
            'department_id' => $deptB->id,
        ]);

        // Pathological: a canonical department assignment for userA pointing at an Org B
        // department, with inherit_to_children so subtree expansion could (wrongly)
        // pick up Org B departments if the org gate did not contain it.
        $this->grantEngineCapability(
            $userA,
            Capability::PROJECTS_VIEW,
            'department',
            $deptB->id,
            'stray_dept_manager_view',
            ['inherit_to_children' => true],
        );

        // Element: org gate denies before owner floor / role checks.
        $this->assertFalse(
            AccessDecision::can($userA->fresh(), Capability::PROJECTS_VIEW, $projectB),
            'cross-org view must be denied at the organization gate'
        );

        // List: the org prefilter (where organization_id = A) excludes the Org B project
        // before subtree expansion runs.
        $visible = app(ProjectQueryService::class)
            ->applyPermissionFilter(Project::query(), $userA->fresh())
            ->pluck('id');

        $this->assertFalse(
            $visible->contains($projectB->id),
            'cross-org project must not appear in the Org A user list'
        );
    }

    public function test_cross_org_record_invisible_even_when_user_is_recorded_owner(): void
    {
        // Combine the owner floor with org isolation: even if a cross-org record
        // pathologically records the user as its creator, the org gate (which runs
        // before the owner floor) keeps it invisible.
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $userA = User::factory()->create(['organization_id' => $a->id]);
        $deptB = Department::factory()->create(['organization_id' => $b->id]);
        $projectB = Project::factory()->create([
            'organization_id' => $b->id,
            'department_id' => $deptB->id,
            'created_by' => $userA->id,
            'status' => 'planning',
        ]);

        $this->assertFalse(
            AccessDecision::can($userA->fresh(), Capability::PROJECTS_VIEW, $projectB),
            'owner floor must never override the organization gate'
        );

        $visible = app(ProjectQueryService::class)
            ->applyPermissionFilter(Project::query(), $userA->fresh())
            ->pluck('id');

        $this->assertFalse(
            $visible->contains($projectB->id),
            'cross-org owned project must not appear in the Org A user list'
        );
    }
}
