<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class UserProjectScopeEngineTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_scope_includes_org_projects_when_engine_grants_org_view(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, Capability::PROJECTS_VIEW);

        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $this->assertTrue($this->applyScope($user)->where('id', $project->id)->exists());
    }

    public function test_scope_excludes_when_no_grant(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $otherUser = User::factory()->create(['organization_id' => $org->id]);

        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $otherUser->id,
        ]);

        $this->assertFalse($this->applyScope($user)->where('id', $project->id)->exists());
    }

    private function applyScope(User $user)
    {
        $query = Project::query();
        (new UserProjectScope)->apply($query, $user);

        return $query;
    }
}
