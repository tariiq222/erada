<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Enums\TaskType;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TaskEngineVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    public function test_visible_to_includes_org_tasks_when_engine_grants_org_view(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        // Grant the engine `tasks.view` capability via an org-scoped
        // scoped_role definition (`member` is a legacy test role seeded
        // by RolesAndPermissionsSeeder::seedLegacyTestRoles with every
        // `view*` capability). The previous assertion incorrectly relied
        // on the Spatie `viewer` role auto-granting engine capabilities,
        // but Phase 3's ScopedDepartmentRolesSeeder narrowed the
        // engine grant to explicit scoped_role_definitions rows only.
        $user->scopedRoles()->create([
            'role' => 'member',
            'scope_type' => 'organization',
            'scope_id' => $org->id,
            'inherit_to_children' => true,
            'source' => 'manual',
        ]);

        // Pass department_id in same org so ProjectObserver::saving does not override organization_id.
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $task = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $project->id,
            'owner_id' => $user->id,
        ]);

        $this->assertTrue(
            Task::query()->visibleTo($user)->where('id', $task->id)->exists()
        );
    }

    public function test_visible_to_excludes_org_tasks_when_no_grant(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $otherUser = User::factory()->create(['organization_id' => $org->id]);

        $project = Project::factory()->create(['organization_id' => $org->id]);
        $task = Task::factory()->create([
            'type' => TaskType::PROJECT->value,
            'project_id' => $project->id,
            'owner_id' => $otherUser->id,
            'assigned_to' => $otherUser->id,
            'created_by' => $otherUser->id,
        ]);

        $this->assertFalse(
            Task::query()->visibleTo($user)->where('id', $task->id)->exists()
        );
    }

    public function test_visible_to_includes_own_personal_tasks_regardless_of_grant(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $task = Task::factory()->create([
            'type' => TaskType::PERSONAL->value,
            'owner_id' => $user->id,
        ]);

        $this->assertTrue(
            Task::query()->visibleTo($user)->where('id', $task->id)->exists()
        );
    }
}
