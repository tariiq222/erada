<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DepartmentCapacityRole;
use App\Modules\HR\Services\ScopedDepartmentRoleSyncService;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Support\ElementAbilities;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TaskAbilitiesTest — every /api/unified-tasks/{id} response carries an
 * `abilities` object computed by AccessDecision via ElementAbilities.
 *
 * Endpoint shape: JsonResource::withoutWrapping() is set globally in
 * AppServiceProvider, so the response is the raw resource array (no "data"
 * envelope). The negative isolation case is verified through the helper
 * directly, because the controller's authorization filter returns 404 for
 * users with no access to the project chain.
 */
class TaskAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    public function test_task_response_carries_engine_abilities(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        DepartmentCapacityRole::create([
            'department_id' => $dept->id,
            'capacity' => 'manager',
            'role_key' => 'dept_manager',
        ]);

        $mgr = User::factory()->create(['organization_id' => $org->id]);
        $dept->update(['manager_id' => $mgr->id]);
        app(ScopedDepartmentRoleSyncService::class)->syncUser($mgr->fresh());

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $this->actingAs($mgr->fresh(), 'sanctum')
            ->getJson("/api/unified-tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('abilities.view', true)
            ->assertJsonPath('abilities.edit', true)
            ->assertJsonPath('abilities.delete', true)
            ->assertJsonPath('abilities.complete', true)
            ->assertJsonPath('abilities.assign', true);
    }

    public function test_outsider_task_user_gets_all_abilities_false_via_helper(): void
    {
        // Negative isolation: a user in the same org with no role on the
        // project chain must not be granted any task abilities. Verified
        // through the helper directly because the controller's authorization
        // filter excludes unauthorized projects at the query layer.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $outsider = User::factory()->create(['organization_id' => $org->id]);

        $abilities = ElementAbilities::resolve(
            $outsider,
            $task,
            [
                'view' => Capability::TASKS_VIEW,
                'edit' => Capability::TASKS_EDIT,
                'delete' => Capability::TASKS_DELETE,
                'complete' => Capability::TASKS_COMPLETE,
                'assign' => Capability::TASKS_ASSIGN,
            ]
        );

        $this->assertFalse($abilities['view']);
        $this->assertFalse($abilities['edit']);
        $this->assertFalse($abilities['delete']);
        $this->assertFalse($abilities['complete']);
        $this->assertFalse($abilities['assign']);
    }

    public function test_super_admin_task_response_has_all_abilities_true(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $task = Task::factory()->create(['project_id' => $project->id]);

        $superAdmin = User::factory()->create(['organization_id' => $org->id]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/unified-tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('abilities.view', true)
            ->assertJsonPath('abilities.edit', true)
            ->assertJsonPath('abilities.complete', true);
    }
}
