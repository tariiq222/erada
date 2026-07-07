<?php

namespace Tests\Unit\Models;

use App\Modules\Projects\Models\Project;
use Tests\TestCase;

class ProjectProgressTest extends TestCase
{
    public function test_calculate_progress_from_loaded_method_exists(): void
    {
        $project = new Project;
        $this->assertTrue(method_exists($project, 'calculateProgressFromLoaded'));
    }

    public function test_calculate_progress_from_loaded_returns_zero_when_no_tasks(): void
    {
        $project = new Project;
        $project->setRelation('tasks', collect());

        $progress = $project->calculateProgressFromLoaded();

        $this->assertEquals(0.0, $progress);
    }

    public function test_calculate_progress_from_loaded_uses_loaded_relation_when_available(): void
    {
        $project = new Project;

        // Create mock tasks with progress values (no parent_id means root tasks).
        // A status is required because calculateProgressFromLoaded() now filters
        // out cancelled tasks before averaging progress.
        $tasks = collect([
            (object) ['progress' => 50, 'parent_id' => null, 'status' => 'in_progress'],
            (object) ['progress' => 100, 'parent_id' => null, 'status' => 'in_progress'],
        ]);

        // Simulate loaded relation
        $project->setRelation('tasks', $tasks);

        $progress = $project->calculateProgressFromLoaded();

        $this->assertEquals(75.0, $progress);
    }
}
