<?php

namespace Tests\Unit\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the P1 "project org = department org" bug.
 *
 * Invariant: `project.organization_id` is the authoritative org for engine
 * authorization. When the snapshot is missing (project.organization_id = null),
 * the accessor's fallback MUST derive the org from the department — never
 * from the creator's user row. A drift in either direction allowed cross-tenant
 * leaks in the original incident.
 *
 * This test exercises the accessor directly rather than the HTTP layer because
 * the bug surfaces at scope-chain computation, and the engine silently coerces
 * null-org targets to "deny" — which masks the bug in endpoint tests.
 */
class ProjectScopeOrganizationIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_self_organization_when_snapshot_is_set(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => $org->id,
        ]);

        $this->assertSame((int) $org->id, $project->scopeOrganizationId());
    }

    public function test_falls_back_to_department_org_when_snapshot_is_null(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => null,
        ]);

        $this->assertSame(
            (int) $org->id,
            $project->scopeOrganizationId(),
            'Accessor must derive org from department when project.organization_id is null'
        );
    }

    public function test_returns_null_when_both_snapshot_and_department_org_are_null(): void
    {
        $dept = Department::factory()->create([
            'organization_id' => null,
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'department_id' => $dept->id,
            'organization_id' => null,
        ]);

        $this->assertNull($project->scopeOrganizationId());
    }

    public function test_returns_null_when_no_department_and_no_snapshot(): void
    {
        $project = Project::factory()->create([
            'department_id' => null,
            'organization_id' => null,
        ]);

        $this->assertNull($project->scopeOrganizationId());
    }

    public function test_department_org_overrides_snapshot_when_they_differ(): void
    {
        // P1 "project org = department org" invariant (closed 2026-06-29):
        // ProjectObserver::saving auto-corrects project.organization_id to the
        // department's org on save. The "moved dept without backfill" state
        // (snapshot stale) is no longer legal at write-time — the observer
        // reconciles it. The accessor therefore resolves to the department's
        // org (orgA), not the originally-passed snapshot org (orgB).
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptInA = Department::factory()->create([
            'organization_id' => $orgA->id,
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'department_id' => $deptInA->id,
            'organization_id' => $orgB->id,
        ]);

        $this->assertSame((int) $orgA->id, $project->scopeOrganizationId());
    }
}
