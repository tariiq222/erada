<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Save as draft" relaxes the normally-required charter fields so an
 * incomplete project can be persisted with status=draft and finished later.
 */
class ProjectDraftSaveTest extends TestCase
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

    public function test_new_project_draft_persists_with_only_a_name(): void
    {
        // No priority — normally required — but save_as_draft relaxes it.
        $response = $this->actingAs($this->actor())
            ->postJson('/api/projects', [
                'name' => 'مسودة مشروع',
                'type' => 'development',
                'save_as_draft' => true,
            ], ['X-Skip-Csrf' => '1'])
            ->assertStatus(201);

        $project = Project::findOrFail($response->json('project.id'));
        $this->assertSame('draft', $project->status);
    }

    public function test_improvement_project_draft_omits_problem_statement_and_kpis(): void
    {
        $response = $this->actingAs($this->actor())
            ->postJson('/api/projects', [
                'name' => 'مسودة تحسين',
                'type' => 'improvement',
                'save_as_draft' => true,
            ], ['X-Skip-Csrf' => '1'])
            ->assertStatus(201);

        $project = Project::findOrFail($response->json('project.id'));
        $this->assertSame('draft', $project->status);
        $this->assertCount(0, $project->kpis);
    }

    public function test_draft_flag_is_not_persisted_as_a_column(): void
    {
        $response = $this->actingAs($this->actor())
            ->postJson('/api/projects', [
                'name' => 'مسودة',
                'type' => 'development',
                'save_as_draft' => true,
            ], ['X-Skip-Csrf' => '1'])
            ->assertStatus(201);

        $project = Project::findOrFail($response->json('project.id'));
        $this->assertArrayNotHasKey('save_as_draft', $project->getAttributes());
    }

    public function test_non_draft_improvement_still_requires_kpis(): void
    {
        // Regression guard: relaxing for drafts must not relax a normal create.
        $this->actingAs($this->actor())
            ->postJson('/api/projects', [
                'name' => 'مشروع تحسيني',
                'type' => 'improvement',
                'priority' => 'medium',
                'problem_statement' => 'مشكلة',
            ], ['X-Skip-Csrf' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kpis']);
    }
}
