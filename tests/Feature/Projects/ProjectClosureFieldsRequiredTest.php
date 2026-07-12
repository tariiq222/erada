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
 * ProjectClosureFieldsRequiredTest - اختبارات فرض حقول الإغلاق عند إكمال المشروع
 *
 * يفرض أن PUT /api/projects/{id} إلى status=completed يتطلب:
 *  - lessons_learned أو outcome_summary (واحد على الأقل)
 *  - achievement_status
 *
 * بينما PUT بحالات أخرى (in_progress, on_hold, ...) لا يشترط هذه الحقول.
 */
class ProjectClosureFieldsRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);
    }

    /**
     * Case A: PUT status=completed بدون أي حقول إغلاق → 422 على lessons_learned و outcome_summary و achievement_status
     */
    public function test_completing_project_without_closure_fields_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", [
                'status' => 'completed',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lessons_learned', 'outcome_summary', 'achievement_status']);

        $this->assertSame('in_progress', $this->project->fresh()->status);
    }

    /**
     * Case A2: PUT status=completed مع whitespace فقط في lessons_learned (بدون outcome_summary) → 422
     */
    public function test_whitespace_only_lessons_learned_does_not_satisfy_closure_requirement(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", [
                'status' => 'completed',
                'lessons_learned' => '   ',
                'achievement_status' => 'achieved',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lessons_learned', 'outcome_summary']);
    }

    /**
     * Case B: PUT status=completed مع lessons_learned فقط → 200
     */
    public function test_completing_project_with_only_lessons_learned_succeeds(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", [
                'status' => 'completed',
                'lessons_learned' => 'تعلّمنا ضرورة التخطيط المبكر',
                'achievement_status' => 'achieved',
            ]);

        $response->assertOk()
            ->assertJsonPath('project.status', 'completed')
            ->assertJsonPath('project.lessons_learned', 'تعلّمنا ضرورة التخطيط المبكر')
            ->assertJsonPath('project.achievement_status', 'achieved');

        $this->assertDatabaseHas('projects', [
            'id' => $this->project->id,
            'status' => 'completed',
            'lessons_learned' => 'تعلّمنا ضرورة التخطيط المبكر',
            'achievement_status' => 'achieved',
        ]);
    }

    /**
     * Case C: PUT status=completed مع outcome_summary فقط → 200
     */
    public function test_completing_project_with_only_outcome_summary_succeeds(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", [
                'status' => 'completed',
                'outcome_summary' => 'تحقّق 95% من الأهداف',
                'achievement_status' => 'partial',
            ]);

        $response->assertOk()
            ->assertJsonPath('project.status', 'completed')
            ->assertJsonPath('project.outcome_summary', 'تحقّق 95% من الأهداف')
            ->assertJsonPath('project.achievement_status', 'partial');
    }

    /**
     * Case D: PUT status=completed مع achievement_status فقط (بدون lessons/outcome) → 422
     */
    public function test_completing_project_with_only_achievement_status_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", [
                'status' => 'completed',
                'achievement_status' => 'achieved',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lessons_learned', 'outcome_summary']);

        $this->assertSame('in_progress', $this->project->fresh()->status);
    }

    /**
     * Case E: PUT status=in_progress بدون حقول إغلاق → 200 (الحقول غير مطلوبة خارج completed)
     */
    public function test_non_completed_status_does_not_require_closure_fields(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}", [
                'name' => 'مشروع محدّث',
                'status' => 'in_progress',
            ]);

        $response->assertOk()
            ->assertJsonPath('project.status', 'in_progress')
            ->assertJsonPath('project.name', 'مشروع محدّث');
    }
}
