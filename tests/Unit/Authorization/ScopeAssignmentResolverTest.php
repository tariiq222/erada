<?php

namespace Tests\Unit\Authorization;

use App\Modules\Core\Authorization\Support\ScopeAssignmentResolver;
use App\Modules\Core\Authorization\Support\ScopeTypeToTargetColumn;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Projects\Models\Project;
use App\Modules\Strategy\Models\Portfolio;
use App\Modules\Strategy\Models\Program;
use App\Modules\Surveys\Models\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2.1.2 -- Unit-level coverage for the resolver + scope→target-column
 * support classes that the new-path `assignmentScopeApplies` is being wired
 * through. Lives in tests/Unit/Authorization because both classes are pure
 * PHP value objects; they do not perform DB I/O. The DB-touching branch is
 * covered by tests/Feature/Core/Authorization/BackfillScopedRolesFullSemanticsTest.
 *
 * Coverage:
 *  1. ScopeTypeToTargetColumn maps every supported scope_type to the column
 *     a probe target of that type uses to identify itself.
 *  2. ScopeAssignmentResolver::applies() returns true when an assignment's
 *     scope_type/scope_id target a node that the target's ScopeAware chain
 *     traverses -- for organization, department (own + descendants), project,
 *     program, portfolio, kpi, meeting, survey.
 *  3. department inherit_to_children=true matches descendant department
 *     targets; inherit_to_children=false narrows to that exact node.
 *  4. Assignments whose scope_type is NOT in the supported set (cluster,
 *     hospital, team, own) return false (fail-closed).
 *  5. OR semantics across multiple assignments: a first-applicable
 *     assignment returns true even if a later one would not.
 *  6. target=null always returns false (the resolver is target-bound).
 */
class ScopeAssignmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Phase 2.1.2 resolver test is PostgreSQL-only.');
        }

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
    }

    // =====================================================================
    // 1. ScopeTypeToTargetColumn contract
    // =====================================================================

    public function test_scope_type_to_target_column_maps_every_supported_scope_to_a_column(): void
    {
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('organization'));
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('department'));
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('project'));
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('program'));
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('portfolio'));
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('kpi'));
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('meeting'));
        $this->assertSame('id', ScopeTypeToTargetColumn::columnFor('survey'));
    }

    public function test_scope_type_to_target_column_returns_null_for_unsupported_scope(): void
    {
        $this->assertNull(ScopeTypeToTargetColumn::columnFor('cluster'));
        $this->assertNull(ScopeTypeToTargetColumn::columnFor('hospital'));
        $this->assertNull(ScopeTypeToTargetColumn::columnFor('team'));
        $this->assertNull(ScopeTypeToTargetColumn::columnFor('own'));
        $this->assertNull(ScopeTypeToTargetColumn::columnFor('all'));
        $this->assertNull(ScopeTypeToTargetColumn::columnFor('not_a_real_scope'));
    }

    public function test_supported_scope_types_lists_every_type_the_resolver_can_handle(): void
    {
        $supported = ScopeTypeToTargetColumn::supportedScopeTypes();

        $this->assertContains('organization', $supported);
        $this->assertContains('department', $supported);
        $this->assertContains('project', $supported);
        $this->assertContains('program', $supported);
        $this->assertContains('portfolio', $supported);
        $this->assertContains('kpi', $supported);
        $this->assertContains('meeting', $supported);
        $this->assertContains('survey', $supported);

        $this->assertNotContains('cluster', $supported);
        $this->assertNotContains('hospital', $supported);
        $this->assertNotContains('team', $supported);
        $this->assertNotContains('own', $supported);
        $this->assertNotContains('all', $supported);
    }

    // =====================================================================
    // 2. Resolver: organization scope
    // =====================================================================

    public function test_resolver_applies_for_organization_scope_when_target_org_matches(): void
    {
        $project = $this->makeProjectInOrg();

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'organization', scopeId: $this->org->id),
                $project,
            ),
            'org-scoped assignment must apply when the target belongs to that org.'
        );
    }

    public function test_resolver_denies_organization_scope_when_target_org_differs(): void
    {
        $foreignOrg = Organization::factory()->create();
        $foreignProject = $this->makeProjectInOrg($foreignOrg);

        $this->assertFalse(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'organization', scopeId: $this->org->id),
                $foreignProject,
            ),
            'org-scoped assignment must NOT apply when the target belongs to another org.'
        );
    }

    // =====================================================================
    // 3. Resolver: department scope (exact + descendants)
    // =====================================================================

    public function test_resolver_applies_for_department_scope_when_target_is_in_department(): void
    {
        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = $this->makeProjectInOrg($this->org, $department);

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'department', scopeId: $department->id),
                $project,
            ),
            'dept-scoped assignment must apply when the target lives in that department.'
        );
    }

    public function test_resolver_denies_department_scope_when_target_is_in_another_department(): void
    {
        $department = Department::factory()->create(['organization_id' => $this->org->id]);
        $otherDept = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = $this->makeProjectInOrg($this->org, $otherDept);

        $this->assertFalse(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'department', scopeId: $department->id),
                $project,
            ),
            'dept-scoped assignment must NOT apply when the target is in a different department.'
        );
    }

    public function test_resolver_applies_for_department_scope_when_inherit_to_children_true_and_target_is_descendant(): void
    {
        $parentDept = Department::factory()->create(['organization_id' => $this->org->id]);
        $childDept = Department::factory()->create([
            'organization_id' => $this->org->id,
            'parent_id' => $parentDept->id,
        ]);
        $descendantProject = $this->makeProjectInOrg($this->org, $childDept);

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(
                    scopeType: 'department',
                    scopeId: $parentDept->id,
                    inheritToChildren: true,
                ),
                $descendantProject,
            ),
            'dept-scoped assignment with inherit_to_children=true must apply to descendant departments.'
        );
    }

    public function test_resolver_denies_department_scope_when_inherit_to_children_false_and_target_is_descendant(): void
    {
        $parentDept = Department::factory()->create(['organization_id' => $this->org->id]);
        $childDept = Department::factory()->create([
            'organization_id' => $this->org->id,
            'parent_id' => $parentDept->id,
        ]);
        $descendantProject = $this->makeProjectInOrg($this->org, $childDept);

        $this->assertFalse(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(
                    scopeType: 'department',
                    scopeId: $parentDept->id,
                    inheritToChildren: false,
                ),
                $descendantProject,
            ),
            'dept-scoped assignment with inherit_to_children=false must NOT apply to descendant departments.'
        );
    }

    // =====================================================================
    // 4. Resolver: project / program / portfolio / kpi / meeting / survey
    // =====================================================================

    public function test_resolver_applies_for_project_scope_when_target_is_that_project(): void
    {
        $project = $this->makeProjectInOrg();

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'project', scopeId: $project->id),
                $project,
            )
        );
    }

    public function test_resolver_denies_project_scope_when_target_is_a_different_project(): void
    {
        $projectA = $this->makeProjectInOrg();
        $projectB = $this->makeProjectInOrg();

        $this->assertFalse(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'project', scopeId: $projectA->id),
                $projectB,
            )
        );
    }

    public function test_resolver_applies_for_program_scope_when_target_is_that_program(): void
    {
        // Program's ScopeAware chain: program -> portfolio. A probe against
        // the program itself matches the 'program' node in the chain.
        $portfolio = Portfolio::factory()->create(['organization_id' => $this->org->id]);
        $program = Program::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_id' => $portfolio->id,
        ]);

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'program', scopeId: $program->id),
                $program,
            ),
            "program scope must match a Program target whose id is the assignment's scope_id."
        );
    }

    public function test_resolver_denies_program_scope_when_target_is_a_different_program(): void
    {
        $portfolio = Portfolio::factory()->create(['organization_id' => $this->org->id]);
        $programA = Program::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_id' => $portfolio->id,
        ]);
        $programB = Program::factory()->create([
            'organization_id' => $this->org->id,
            'portfolio_id' => $portfolio->id,
        ]);

        $this->assertFalse(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'program', scopeId: $programA->id),
                $programB,
            )
        );
    }

    public function test_resolver_applies_for_portfolio_scope_when_target_is_that_portfolio(): void
    {
        $portfolio = Portfolio::factory()->create(['organization_id' => $this->org->id]);

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'portfolio', scopeId: $portfolio->id),
                $portfolio,
            )
        );
    }

    public function test_resolver_applies_for_kpi_scope_when_target_is_that_kpi(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $kpi = Kpi::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'kpi', scopeId: $kpi->id),
                $kpi,
            )
        );
    }

    public function test_resolver_applies_for_meeting_scope_when_target_is_that_meeting(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $meeting = Meeting::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'meeting', scopeId: $meeting->id),
                $meeting,
            )
        );
    }

    public function test_resolver_applies_for_survey_scope_when_target_is_that_survey(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'survey', scopeId: $survey->id),
                $survey,
            )
        );
    }

    // =====================================================================
    // 5. Resolver: fail-closed on unsupported scope types
    // =====================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedAssignmentScopesProvider(): array
    {
        return [
            'cluster' => ['cluster'],
            'hospital' => ['hospital'],
            'team' => ['team'],
            'own' => ['own'],
        ];
    }

    /**
     * @dataProvider unsupportedAssignmentScopesProvider
     */
    public function test_resolver_denies_unsupported_scope_types(string $scopeType): void
    {
        $project = $this->makeProjectInOrg();

        $this->assertFalse(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: $scopeType, scopeId: $project->id),
                $project,
            ),
            "Resolver must fail-closed on unsupported scope_type [{$scopeType}]."
        );
    }

    // =====================================================================
    // 6. Resolver: OR semantics across multiple assignments
    // =====================================================================

    public function test_resolver_or_semantics_first_applicable_assignment_wins(): void
    {
        $projectA = $this->makeProjectInOrg();
        $projectB = $this->makeProjectInOrg();

        // Two assignments for the same user/role. The first is scoped to
        // projectA; the second is scoped to projectB. A probe against
        // projectA must apply via the first assignment and deny via the
        // second. The engine's `hasNewPermission` short-circuits on the
        // first match, so a probe against projectA returns true and against
        // projectB returns true -- both via different assignments.
        $a1 = $this->makeAssignment(scopeType: 'project', scopeId: $projectA->id);
        $a2 = $this->makeAssignment(scopeType: 'project', scopeId: $projectB->id);

        $this->assertTrue(
            ScopeAssignmentResolver::anyApplies([$a1, $a2], $projectA),
            'First matching assignment must grant; OR semantics across multiple assignments.'
        );
        $this->assertTrue(
            ScopeAssignmentResolver::anyApplies([$a1, $a2], $projectB),
            'Second matching assignment must grant when the first does not match.'
        );
    }

    public function test_resolver_or_semantics_returns_false_when_no_assignment_matches(): void
    {
        $projectA = $this->makeProjectInOrg();
        $projectB = $this->makeProjectInOrg();

        $a1 = $this->makeAssignment(scopeType: 'project', scopeId: $projectA->id);
        $a2 = $this->makeAssignment(scopeType: 'project', scopeId: $projectB->id);
        $projectC = $this->makeProjectInOrg();

        $this->assertFalse(
            ScopeAssignmentResolver::anyApplies([$a1, $a2], $projectC),
            'No matching assignment must deny the probe.'
        );
    }

    // =====================================================================
    // 7. Resolver: null target always returns false
    // =====================================================================

    public function test_resolver_denies_null_target(): void
    {
        $this->assertFalse(
            ScopeAssignmentResolver::applies(
                $this->makeAssignment(scopeType: 'organization', scopeId: $this->org->id),
                null,
            ),
            'Resolver is target-bound; a null target must NOT match.'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Build a Project inside the given org, with a department that also
     * lives in the same org. The factory's definition auto-creates an
     * Organization when `organization_id` is the only override, so we
     * always pass `department_id` too -- the explicit `organization_id`
     * override is honored only when at least one Factory-valued entry is
     * also present in the call.
     */
    private function makeProjectInOrg(?Organization $org = null, ?Department $department = null): Project
    {
        $org ??= $this->org;
        $department ??= Department::factory()->create(['organization_id' => $org->id]);

        return Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $department->id,
        ]);
    }

    /**
     * Build a minimal AuthorizationRoleAssignment-like array. The resolver
     * takes a shape (array) not an Eloquent model so the unit test stays
     * cheap and does not have to touch authorization_role_assignments
     * foreign keys.
     *
     * @return array{scope_type: string, scope_id: int|null, organization_id: int|null, inherit_to_children: bool}
     */
    private function makeAssignment(
        string $scopeType,
        ?int $scopeId,
        ?int $organizationId = null,
        bool $inheritToChildren = true,
    ): array {
        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'organization_id' => $organizationId ?? ($scopeType === 'organization' ? $this->org->id : null),
            'inherit_to_children' => $inheritToChildren,
        ];
    }
}
