<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProjectCompletionAuthorizationTest — مصفوفة صلاحيات إتمام المشروع على مستوى
 * الـ endpoint (PUT /api/projects/{id} status=completed عبر PROJECTS_EDIT):
 *  - مدير المشروع (PROJECT_MANAGER) يستطيع الإتمام.
 *  - عضو/مشاهد المشروع (PROJECT_MEMBER / PROJECT_VIEWER) لا يستطيع الإتمام (403).
 */
class ProjectCompletionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
    }

    protected function makeUser(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
    }

    protected function makeProject(): Project
    {
        return Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'status' => 'in_progress',
            'actual_end_date' => null,
        ]);
    }

    protected function completePayload(): array
    {
        return [
            'status' => 'completed',
            'lessons_learned' => 'درس',
            'achievement_status' => 'achieved',
        ];
    }

    public function test_project_manager_can_complete_project(): void
    {
        $project = $this->makeProject();
        $manager = $this->makeUser();
        $this->assignCanonicalRole($manager, 'project_manager', 'project', $project->id);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/projects/{$project->id}", $this->completePayload())
            ->assertOk()
            ->assertJsonPath('project.status', 'completed');

        $this->assertSame('completed', $project->fresh()->status);
    }

    public function test_project_member_cannot_complete_project(): void
    {
        $project = $this->makeProject();
        $member = $this->makeUser();
        $this->assignCanonicalRole($member, 'project_member', 'project', $project->id);

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/projects/{$project->id}", $this->completePayload())
            ->assertForbidden();

        $this->assertSame('in_progress', $project->fresh()->status);
    }

    public function test_project_viewer_cannot_complete_project(): void
    {
        $project = $this->makeProject();
        $viewer = $this->makeUser();
        $this->assignCanonicalRole($viewer, 'project_viewer', 'project', $project->id);

        $this->actingAs($viewer, 'sanctum')
            ->putJson("/api/projects/{$project->id}", $this->completePayload())
            ->assertForbidden();

        $this->assertSame('in_progress', $project->fresh()->status);
    }
}
