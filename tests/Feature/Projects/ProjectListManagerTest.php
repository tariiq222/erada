<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectListManagerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected Department $department;

    protected Project $project;

    protected User $managerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->admin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');

        $this->managerUser = User::factory()->create([
            'name' => 'Test Manager Name',
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'Project With Manager',
        ]);

        $this->managerUser->assignProjectRole($this->project, ScopedRole::PROJECT_MANAGER);
    }

    public function test_list_endpoint_includes_manager_id_and_name(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/projects');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $this->project->id,
                'manager' => [
                    'id' => $this->managerUser->id,
                    'name' => 'Test Manager Name',
                ],
            ]);
    }

    public function test_list_endpoint_returns_null_manager_when_no_manager_assigned(): void
    {
        $projectWithoutManager = Project::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'Project Without Manager',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/projects');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $projectWithoutManager->id,
                'manager' => null,
            ]);
    }
}
