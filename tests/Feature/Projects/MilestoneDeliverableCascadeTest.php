<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\MilestoneDeliverable;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneDeliverableCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withHeaders(['X-Skip-Csrf' => '1']);
    }

    /**
     * @return array{0: User, 1: Project, 2: Milestone}
     */
    private function milestoneWithManager(array $projectOverrides = []): array
    {
        $project = Project::factory()->create($projectOverrides);

        $manager = User::factory()->create([
            'organization_id' => $project->organization_id,
        ]);
        $manager->assignRole('super_admin');

        $milestone = Milestone::factory()->create([
            'project_id' => $project->id,
        ]);

        return [$manager, $project, $milestone];
    }

    /**
     * Case A: حذف مرحلة يحذف مخرجاتها المرتبطة بشكل تتابعي
     * (Deliverables تُحذف فعلياً — لا SoftDeletes — لمنع الـ orphans).
     */
    public function test_destroy_milestone_cascades_deliverables(): void
    {
        [$manager, $project, $milestone] = $this->milestoneWithManager();

        $deliverable1 = $milestone->deliverables()->create([
            'name' => 'Deliverable 1',
            'description' => 'First deliverable',
            'status' => 'pending',
            'progress' => 0,
            'order' => 1,
        ]);
        $deliverable2 = $milestone->deliverables()->create([
            'name' => 'Deliverable 2',
            'description' => 'Second deliverable',
            'status' => 'in_progress',
            'progress' => 50,
            'order' => 2,
        ]);

        $this->assertSame(2, MilestoneDeliverable::query()
            ->where('milestone_id', $milestone->id)
            ->count());

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertOk()
            ->assertJson(['message' => 'تم حذف المرحلة بنجاح']);

        $this->assertSame(0, MilestoneDeliverable::query()
            ->where('milestone_id', $milestone->id)
            ->count(), 'Deliverables must be hard-deleted (no SoftDeletes on MilestoneDeliverable).');

        $this->assertDatabaseMissing('milestone_deliverables', [
            'id' => $deliverable1->id,
        ]);
        $this->assertDatabaseMissing('milestone_deliverables', [
            'id' => $deliverable2->id,
        ]);

        $this->assertNotNull(Milestone::withTrashed()->find($milestone->id)?->deleted_at);
    }

    /**
     * Case B: المرحلة التي عليها مهام مرتبطة لا تُحذف (422) ولا تتأثر مخرجاتها.
     */
    public function test_destroy_milestone_with_tasks_returns_422_and_keeps_data(): void
    {
        [$manager, $project, $milestone] = $this->milestoneWithManager();

        $task = Task::factory()->create([
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
        ]);

        $deliverable = $milestone->deliverables()->create([
            'name' => 'Deliverable still here',
            'status' => 'pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertStatus(422)
            ->assertJson(['message' => 'لا يمكن حذف مرحلة بها مهام. قم بنقل أو حذف المهام أولاً.']);

        $refreshed = Milestone::find($milestone->id);
        $this->assertNotNull($refreshed, 'Milestone must still exist after 422.');
        $this->assertNull($refreshed->deleted_at, 'Milestone must not be soft-deleted.');

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('milestone_deliverables', ['id' => $deliverable->id]);
    }

    /**
     * Case C: مستخدم بصلاحية عرض فقط (viewer) لا يستطيع حذف مرحلة (403).
     */
    public function test_viewer_cannot_destroy_milestone(): void
    {
        [$manager, $project, $milestone] = $this->milestoneWithManager();

        $viewer = User::factory()->create([
            'organization_id' => $project->organization_id,
        ]);
        $viewer->assignRole('viewer');

        $deliverable = $milestone->deliverables()->create([
            'name' => 'Should still exist',
            'status' => 'pending',
            'progress' => 0,
            'order' => 1,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/milestones/{$milestone->id}");

        $response->assertStatus(403);

        $refreshed = Milestone::find($milestone->id);
        $this->assertNotNull($refreshed, 'Milestone must still exist after 403.');
        $this->assertNull($refreshed->deleted_at, 'Milestone must not be soft-deleted by viewer.');

        $this->assertDatabaseHas('milestone_deliverables', ['id' => $deliverable->id]);
    }
}
