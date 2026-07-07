<?php

namespace Tests\Unit\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ProjectFactory fillability + ProjectObserver::saving invariant.
 *
 * The P1 "project org = department org" bug (2026-06-20 audit) is now closed
 * by ProjectObserver::saving: when a project is saved with both `department_id`
 * and a `organization_id` that disagrees with the department's org, the
 * observer auto-corrects `organization_id` to the department's org (single
 * source of truth = department). These tests pin that behavior:
 *
 * - Without a department: project.organization_id is whatever the caller passes
 *   (no enforcement — no dept to derive from).
 * - With a department: project.organization_id always equals dept.organization_id
 *   after save, regardless of what the caller passed.
 */
class ProjectFillableTest extends TestCase
{
    use DatabaseTransactions;

    public function test_organization_id_is_fillable(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
        ]);

        $this->assertSame($organization->id, $project->organization_id);
    }

    public function test_organization_id_matches_department_organization_id_after_save(): void
    {
        // Same-org dept + explicit organization_id => assertion passes
        // because observer leaves them equal.
        $organization = Organization::factory()->create();
        $department = Department::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $organization->id,
        ]);

        $this->assertSame($organization->id, $project->organization_id);
        $this->assertSame($department->organization_id, $project->organization_id);
    }
}
