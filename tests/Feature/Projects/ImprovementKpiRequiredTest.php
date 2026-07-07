<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImprovementKpiRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actor(): User
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'مشروع تحسيني',
            'description' => 'وصف',
            'priority' => 'medium',
            'start_date' => '2026-06-01',
            'end_date' => '2026-12-01',
        ], $overrides);
    }

    public function test_improvement_project_without_kpis_is_rejected(): void
    {
        $this->actingAs($this->actor())
            ->postJson('/api/projects', $this->basePayload([
                'type' => 'improvement',
                'problem_statement' => 'مشكلة',
            ]), ['X-Skip-Csrf' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kpis']);
    }

    public function test_improvement_project_with_kpi_is_created_and_linked(): void
    {
        $response = $this->actingAs($this->actor())
            ->postJson('/api/projects', $this->basePayload([
                'type' => 'improvement',
                'problem_statement' => 'مشكلة',
                'kpis' => [
                    ['name' => 'نسبة الإنجاز', 'target' => 100, 'baseline' => 20],
                ],
            ]), ['X-Skip-Csrf' => '1'])
            ->assertStatus(201);

        $projectId = $response->json('project.id');
        $project = Project::findOrFail($projectId);

        $this->assertCount(1, $project->kpis);
        $this->assertSame('نسبة الإنجاز', $project->kpis->first()->name);
    }

    public function test_new_project_without_kpis_is_allowed(): void
    {
        $this->actingAs($this->actor())
            ->postJson('/api/projects', $this->basePayload([
                'type' => 'development',
            ]), ['X-Skip-Csrf' => '1'])
            ->assertStatus(201);
    }
}
