<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Scopes\UserProjectScope;
use App\Modules\Projects\Scopes\UserTaskScope;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class UserTaskScopeEngineTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_scope_includes_org_tasks_when_engine_grants_org_view(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->grantEngineCapability($user, Capability::TASKS_VIEW);

        $project = Project::factory()->create(['organization_id' => $org->id]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
        ]);

        $this->assertTrue($this->applyScope($user)->where('id', $task->id)->exists());
    }

    public function test_scope_excludes_when_no_grant(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $otherUser = User::factory()->create(['organization_id' => $org->id]);

        $project = Project::factory()->create(['organization_id' => $org->id]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $otherUser->id,
            'assigned_to' => $otherUser->id,
            'created_by' => $otherUser->id,
        ]);

        $this->assertFalse($this->applyScope($user)->where('id', $task->id)->exists());
    }

    private function applyScope(User $user)
    {
        $query = Task::query();
        (new UserTaskScope(new UserProjectScope))->apply($query, $user);

        return $query;
    }
}
