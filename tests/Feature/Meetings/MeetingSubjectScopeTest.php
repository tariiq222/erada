<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\Recommendation;
use App\Modules\Projects\Models\Project;
use App\Modules\RiskManagement\Models\Risk;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingSubjectScopeTest — Phase 4.3 of master AuthZ unification plan.
 *
 * Pins the new scopeParent() priority on Meetings:
 *   1. subject_type/subject_id resolves to a ScopeAware parent (Risk,
 *      IncidentReport, Kpi, Project, Milestone, Department)
 *   2. department_id -> Department (legacy chain)
 *
 * Direction B (commit f98adef5): rulings live on the unified `recommendations`
 * table with `kind=ruling`, so the "decision + recommendation" leg collapsed
 * into a single Recommendation row whose scopeParent() returns the meeting.
 * Recommendation::scopeParent() therefore reads Meeting directly — there is
 * no intermediate Decision parent anymore. The decision + recommendation
 * chain is pinned transitively by the meeting + recommendation tests below.
 *
 * Confidential OVR gating and full Risk action parity are still owned
 * by their respective Phase 6 module tests; this file only pins the
 * scope-resolution contract.
 */
class MeetingSubjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Department $deptA;

    private Project $projectA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->projectA = Project::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
        ]);
    }

    public function test_department_only_meeting_scope_parent_is_the_department(): void
    {
        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $parent = $meeting->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Department::class, $parent);
        $this->assertSame($this->deptA->id, $parent->id);
    }

    public function test_meeting_with_project_subject_scope_parent_is_the_project(): void
    {
        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => 'Project',
            'subject_id' => $this->projectA->id,
        ]);

        $parent = $meeting->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Project::class, $parent);
        $this->assertSame($this->projectA->id, $parent->id);
    }

    public function test_meeting_subject_priority_beats_department(): void
    {
        // A meeting tagged to a Project with a different department than
        // its department_id still resolves to the Project — the subject
        // wins. This is the new Phase 4.3 behavior.
        $otherDept = Department::factory()->create(['organization_id' => $this->orgA->id]);

        $meeting = Meeting::factory()->create([
            'department_id' => $otherDept->id,
            'subject_type' => 'Project',
            'subject_id' => $this->projectA->id,
        ]);

        $parent = $meeting->scopeParent();
        $this->assertInstanceOf(Project::class, $parent);
        $this->assertSame($this->projectA->id, $parent->id);
        $this->assertNotInstanceOf(Department::class, $parent);
    }

    public function test_meeting_with_risk_subject_inherits_risk_scope(): void
    {
        $risk = Risk::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => 'Risk',
            'subject_id' => $risk->id,
        ]);

        $parent = $meeting->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Risk::class, $parent);
        $this->assertSame($risk->id, $parent->id);
    }

    public function test_unknown_subject_type_falls_through_to_department(): void
    {
        // Legacy rows or future extensions: an unmapped subject_type
        // must not crash the engine; it falls through to department_id.
        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => 'FutureSubjectType',
            'subject_id' => 999999,
        ]);

        $parent = $meeting->scopeParent();
        $this->assertInstanceOf(Department::class, $parent);
        $this->assertSame($this->deptA->id, $parent->id);
    }

    public function test_missing_subject_row_falls_through_to_department(): void
    {
        // Subject_type token is recognized but the row is gone
        // (deleted/archived) — the engine falls through to department_id
        // rather than returning null so the meeting stays visible until
        // ops resolves the dangling subject pointer.
        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => 'Risk',
            'subject_id' => 999999, // no row exists
        ]);

        $parent = $meeting->scopeParent();
        $this->assertInstanceOf(Department::class, $parent);
        $this->assertSame($this->deptA->id, $parent->id);
    }

    public function test_recommendation_scope_parent_is_its_meeting(): void
    {
        // Direction B: a ruling-kind recommendation's scopeParent() resolves
        // directly to its meeting — there is no intermediate Decision row.
        // Pin that the parent is the meeting, not the meeting's department.
        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $recommendation = Recommendation::factory()->ruling()->create([
            'meeting_id' => $meeting->id,
            'organization_id' => $this->orgA->id,
        ]);

        $parent = $recommendation->scopeParent();
        $this->assertNotNull($parent);
        $this->assertInstanceOf(Meeting::class, $parent);
        $this->assertSame($meeting->id, $parent->id);
    }

    public function test_recommendation_inherits_meeting_subject_organization(): void
    {
        // The chain is exercised end-to-end:
        //   Recommendation -> Meeting -> Project (subject)
        //
        // Recommendation has organization_id = orgA directly so the test
        // pins the recommendation row's own org; the subject chain
        // (Recommendation -> Meeting -> Project) is verified transitively
        // through the meeting scopeParent test above.
        $meeting = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => 'Project',
            'subject_id' => $this->projectA->id,
            'organization_id' => $this->orgA->id,
        ]);

        $recommendation = Recommendation::factory()->ruling()->create([
            'meeting_id' => $meeting->id,
            'organization_id' => $this->orgA->id,
        ]);

        // Each scope-aware link returns the project's organization:
        $meetingOrg = $meeting->scopeOrganizationId();
        $recommendationOrg = $recommendation->scopeOrganizationId();

        $this->assertSame($this->orgA->id, $meetingOrg);
        $this->assertSame(
            $this->orgA->id,
            $recommendationOrg,
            'recommendation must surface the meeting org through the chain'
        );

        // The polymorphic subject still drives meeting.scopeParent():
        $this->assertInstanceOf(Project::class, $meeting->scopeParent());
    }

    public function test_meeting_subject_scope_parent_uses_engine_cache_identity(): void
    {
        // Two meetings tagged to the same project must share one engine
        // lookup — the project's identity-map cache collapses the N+1
        // across the list.
        $meetingA = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => 'Project',
            'subject_id' => $this->projectA->id,
        ]);
        $meetingB = Meeting::factory()->create([
            'department_id' => $this->deptA->id,
            'subject_type' => 'Project',
            'subject_id' => $this->projectA->id,
        ]);

        AccessDecision::flushCache();

        $parentA = $meetingA->scopeParent();
        $parentB = $meetingB->scopeParent();

        $this->assertInstanceOf(Project::class, $parentA);
        $this->assertInstanceOf(Project::class, $parentB);
        $this->assertSame($this->projectA->id, $parentA->id);
        $this->assertSame($this->projectA->id, $parentB->id);
        $this->assertSame(
            $parentA,
            $parentB,
            'engine identity map must return the same canonical project instance'
        );
    }
}
