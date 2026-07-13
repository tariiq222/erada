<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImprovementTransitionDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function managerFor(Project $project): User
    {
        $user = User::factory()->create(['organization_id' => $project->organization_id]);
        $this->grantCanonicalSuperAdmin($user);

        return $user;
    }

    // ===== Tasks module: /api/unified-tasks =====

    public function test_completing_improvement_task_without_lessons_is_blocked(): void
    {
        $project = Project::factory()->create(['type' => 'improvement']);
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'in_review']);
        $user = $this->managerFor($project);

        $this->actingAs($user)
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed'], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422);
    }

    public function test_completing_improvement_task_with_lessons_succeeds(): void
    {
        $project = Project::factory()->create(['type' => 'improvement']);
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'in_review']);
        $user = $this->managerFor($project);

        $this->actingAs($user)
            ->patchJson("/api/unified-tasks/{$task->id}/status", [
                'status' => 'completed',
                'lessons_learned' => 'الدرس: حسّنا العملية وسنعممها.',
            ], ['X-Skip-Csrf' => '1'])
            ->assertOk();
    }

    public function test_new_project_task_is_not_gated(): void
    {
        $project = Project::factory()->create(['type' => 'development']);
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'in_review']);
        $user = $this->managerFor($project);

        $this->actingAs($user)
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed'], ['X-Skip-Csrf' => '1'])
            ->assertOk();
    }

    // ===== Unified module: review transition requires a status comment =====

    public function test_review_via_unified_put_requires_status_comment(): void
    {
        $project = Project::factory()->create(['type' => 'improvement']);
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'in_progress']);
        $user = $this->managerFor($project);

        $this->actingAs($user)
            ->putJson("/api/unified-tasks/{$task->id}", ['status' => 'in_review'], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422);
    }

    public function test_unified_status_endpoint_blocks_completion_without_lessons(): void
    {
        $project = Project::factory()->create(['type' => 'improvement']);
        $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'in_review']);
        $user = $this->managerFor($project);

        $this->actingAs($user)
            ->patchJson("/api/unified-tasks/{$task->id}/status", ['status' => 'completed'], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422);
    }
}
