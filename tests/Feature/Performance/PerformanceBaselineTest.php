<?php

namespace Tests\Feature\Performance;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Department $department;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);
    }

    public function test_dashboard_stats_query_budget_is_bounded(): void
    {
        $projects = Project::factory()->count(12)->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        foreach ($projects as $project) {
            Task::factory()->count(2)->create([
                'project_id' => $project->id,
                'assigned_to' => $this->user->id,
                'created_by' => $this->user->id,
            ]);
        }

        $queries = $this->countQueries(function (): void {
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/dashboard/stats')
                ->assertOk();
        });

        $this->assertLessThanOrEqual(24, $queries, "Dashboard stats exceeded query budget: {$queries}");
    }

    public function test_project_index_query_budget_is_bounded_for_paginated_lists(): void
    {
        Project::factory()->count(25)->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        $queries = $this->countQueries(function (): void {
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects?per_page=15')
                ->assertOk();
        });

        $this->assertLessThanOrEqual(18, $queries, "Project index exceeded query budget: {$queries}");
    }

    public function test_task_index_query_budget_is_bounded_for_paginated_lists(): void
    {
        $project = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        Task::factory()->count(25)->create([
            'project_id' => $project->id,
            'assigned_to' => $this->user->id,
            'created_by' => $this->user->id,
        ]);

        $queries = $this->countQueries(function (): void {
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/unified-tasks?per_page=15')
                ->assertOk();
        });

        $this->assertLessThanOrEqual(18, $queries, "Task index exceeded query budget: {$queries}");
    }

    public function test_ovr_incident_index_query_budget_is_bounded_for_paginated_lists(): void
    {
        $incidentType = IncidentType::create([
            'name' => 'Medication Error',
            'name_ar' => 'خطأ دوائي',
            'is_active' => true,
        ]);

        for ($i = 0; $i < 25; $i++) {
            IncidentReport::create([
                'organization_id' => $this->organization->id,
                'reporter_id' => $this->user->id,
                'reporter_name' => $this->user->name,
                'reporter_email' => $this->user->email,
                'reporter_department_id' => $this->department->id,
                'incident_datetime' => now()->subMinutes($i),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $incidentType->id,
                'incident_description' => 'Performance baseline incident '.$i,
                'actions_taken' => 'Initial action',
                'contributing_factors' => ['process'],
                'immediate_action_required' => false,
                'severity_level' => SeverityLevel::Medium->value,
                'is_confidential' => false,
                'status' => ReportStatus::New,
            ]);
        }

        $queries = $this->countQueries(function (): void {
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/ovr/incidents?per_page=15')
                ->assertOk();
        });

        $this->assertLessThanOrEqual(20, $queries, "OVR incident index exceeded query budget: {$queries}");
    }

    private function countQueries(callable $callback): int
    {
        $queries = [];

        DB::flushQueryLog();
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $callback();

        return count($queries);
    }
}
