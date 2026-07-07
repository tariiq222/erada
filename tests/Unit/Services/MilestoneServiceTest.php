<?php

namespace Tests\Unit\Services;

use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\Project\MilestoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneServiceTest extends TestCase
{
    use RefreshDatabase;

    private MilestoneService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MilestoneService;
    }

    public function test_can_create_milestone(): void
    {
        $project = Project::factory()->create();

        $milestoneData = [
            'name' => 'المرحلة الأولى',
            'description' => 'وصف المرحلة',
            'start_date' => now()->format('Y-m-d'),
            'due_date' => now()->addMonth()->format('Y-m-d'),
        ];

        $milestone = $this->service->createMilestone($project, $milestoneData);

        $this->assertInstanceOf(Milestone::class, $milestone);
        $this->assertEquals('المرحلة الأولى', $milestone->name);
        $this->assertEquals($project->id, $milestone->project_id);
    }

    public function test_can_create_multiple_milestones(): void
    {
        $project = Project::factory()->create();

        $milestones = [
            [
                'name' => 'المرحلة الأولى',
                'start_date' => now()->format('Y-m-d'),
                'due_date' => now()->addMonth()->format('Y-m-d'),
            ],
            [
                'name' => 'المرحلة الثانية',
                'start_date' => now()->addMonth()->format('Y-m-d'),
                'due_date' => now()->addMonths(2)->format('Y-m-d'),
            ],
        ];

        $milestoneIds = $this->service->createMilestones($project, $milestones);

        $this->assertCount(2, $milestoneIds);
        $this->assertEquals(2, $project->milestones()->count());
    }

    public function test_skips_empty_milestones(): void
    {
        $project = Project::factory()->create();

        $milestones = [
            [
                'name' => 'المرحلة الأولى',
                'start_date' => now()->format('Y-m-d'),
                'due_date' => now()->addMonth()->format('Y-m-d'),
            ],
            [
                'name' => '', // فارغ
                'start_date' => '',
                'due_date' => '',
            ],
        ];

        $milestoneIds = $this->service->createMilestones($project, $milestones);

        $this->assertCount(1, $milestoneIds);
        $this->assertEquals(1, $project->milestones()->count());
    }

    public function test_can_update_milestone(): void
    {
        $project = Project::factory()->create();
        $milestone = $project->milestones()->create([
            'name' => 'المرحلة الأصلية',
            'start_date' => now(),
            'due_date' => now()->addMonth(),
            'order' => 1,
            'status' => 'pending',
            'progress' => 0,
        ]);

        $updatedMilestone = $this->service->updateMilestone($milestone, [
            'name' => 'المرحلة المحدثة',
            'status' => 'in_progress',
            'progress' => 50,
        ]);

        $this->assertEquals('المرحلة المحدثة', $updatedMilestone->name);
        $this->assertEquals('in_progress', $updatedMilestone->status);
        $this->assertEquals(50, $updatedMilestone->progress);
    }

    public function test_can_delete_milestone(): void
    {
        $project = Project::factory()->create();
        $milestone = $project->milestones()->create([
            'name' => 'مرحلة للحذف',
            'start_date' => now(),
            'due_date' => now()->addMonth(),
            'order' => 1,
            'status' => 'pending',
            'progress' => 0,
        ]);

        $result = $this->service->deleteMilestone($milestone);

        $this->assertTrue($result);
        $this->assertSoftDeleted('milestones', ['id' => $milestone->id]);
    }

    public function test_can_create_deliverables(): void
    {
        $project = Project::factory()->create();
        $milestone = $project->milestones()->create([
            'name' => 'مرحلة مع مخرجات',
            'start_date' => now(),
            'due_date' => now()->addMonth(),
            'order' => 1,
            'status' => 'pending',
            'progress' => 0,
        ]);

        $deliverables = [
            ['name' => 'المخرج الأول', 'description' => 'وصف المخرج'],
            ['name' => 'المخرج الثاني'],
        ];

        $this->service->createDeliverables($milestone, $deliverables);

        $this->assertEquals(2, $milestone->deliverables()->count());
    }

    public function test_can_reorder_milestones(): void
    {
        $project = Project::factory()->create();

        $milestone1 = $project->milestones()->create([
            'name' => 'مرحلة 1',
            'start_date' => now(),
            'due_date' => now()->addMonth(),
            'order' => 1,
            'status' => 'pending',
            'progress' => 0,
        ]);

        $milestone2 = $project->milestones()->create([
            'name' => 'مرحلة 2',
            'start_date' => now()->addMonth(),
            'due_date' => now()->addMonths(2),
            'order' => 2,
            'status' => 'pending',
            'progress' => 0,
        ]);

        // عكس الترتيب
        $this->service->reorderMilestones($project, [$milestone2->id, $milestone1->id]);

        $this->assertEquals(2, $milestone1->fresh()->order);
        $this->assertEquals(1, $milestone2->fresh()->order);
    }
}
