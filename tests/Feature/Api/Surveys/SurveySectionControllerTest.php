<?php

namespace Tests\Feature\Api\Surveys;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveySection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class SurveySectionControllerTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Survey $survey;

    protected Organization $orgA;

    protected Organization $orgB;

    protected Department $deptA;

    protected Department $deptB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
        $this->deptA = Department::factory()->create(['organization_id' => $this->orgA->id]);
        $this->deptB = Department::factory()->create(['organization_id' => $this->orgB->id]);
        $this->department = $this->deptA;
        $this->user = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);
        $this->survey = Survey::factory()->draft()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeSection(array $overrides = []): SurveySection
    {
        return $this->survey->sections()->create(array_merge([
            'title' => 'قسم اختباري',
            'order' => 1,
        ], $overrides));
    }

    private function makeCrossOrgActor(): User
    {
        $actor = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'department_id' => $this->deptA->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($actor, 'admin');
        $this->grantEngineCapability($actor, Capability::SURVEYS_EDIT);

        return $actor;
    }

    // ========== index ==========

    public function test_can_list_survey_sections(): void
    {
        $this->makeSection();
        $this->makeSection(['title' => 'قسم ثانٍ', 'order' => 2]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/sections");

        $response->assertStatus(200);
    }

    public function test_unauthenticated_cannot_list_sections(): void
    {
        $response = $this->getJson("/api/surveys/{$this->survey->id}/sections");

        $response->assertStatus(401);
    }

    // ========== store ==========

    public function test_can_create_section(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/sections", [
                'title' => 'قسم جديد',
                'description' => 'وصف القسم',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('survey_sections', [
            'survey_id' => $this->survey->id,
            'title' => 'قسم جديد',
        ]);
    }

    public function test_create_section_requires_title(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/sections", [
                'description' => 'بدون عنوان',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    // ========== update ==========

    public function test_can_update_section(): void
    {
        $section = $this->makeSection();

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/surveys/{$this->survey->id}/sections/{$section->id}", [
                'title' => 'عنوان محدث',
                'description' => 'وصف محدث',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('survey_sections', [
            'id' => $section->id,
            'title' => 'عنوان محدث',
        ]);
    }

    // ========== destroy ==========

    public function test_can_delete_section(): void
    {
        $section = $this->makeSection();

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/surveys/{$this->survey->id}/sections/{$section->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('survey_sections', ['id' => $section->id]);
    }

    // ========== reorder ==========

    public function test_can_reorder_sections(): void
    {
        $section1 = $this->makeSection(['title' => 'القسم الأول', 'order' => 1]);
        $section2 = $this->makeSection(['title' => 'القسم الثاني', 'order' => 2]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/surveys/{$this->survey->id}/sections/reorder", [
                'sections' => [$section2->id, $section1->id],
            ]);

        $response->assertStatus(200);
    }

    public function test_returns_404_for_nonexistent_survey(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/surveys/99999/sections');

        $response->assertStatus(404);
    }

    // ========== Cross-org isolation (T-A applied to destroy / reorder) ==========
    //
    // destroy and reorder are gated by the engine SURVEYS_EDIT capability via
    // DestroySurveySectionRequest / ReorderSurveySectionsRequest. The engine
    // enforces org isolation (D-02): an actor from orgA with SURVEYS_EDIT must
    // still be blocked from acting on a survey that belongs to orgB.

    public function test_cross_org_actor_with_edit_capability_cannot_delete_foreign_section(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->draft()->create([
            'organization_id' => $this->orgB->id,
        ]);
        $foreignSection = $foreignSurvey->sections()->create([
            'title' => 'قسم في مؤسسة أخرى',
            'order' => 1,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/surveys/{$foreignSurvey->id}/sections/{$foreignSection->id}");

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor with SURVEYS_EDIT must not delete a section of an org-B survey'
        );
        // Section must not have been deleted by a denied request.
        $this->assertDatabaseHas('survey_sections', [
            'id' => $foreignSection->id,
        ]);
    }

    public function test_cross_org_actor_with_edit_capability_cannot_reorder_foreign_survey_sections(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->draft()->create([
            'organization_id' => $this->orgB->id,
        ]);
        $section1 = $foreignSurvey->sections()->create(['title' => 'الأول', 'order' => 1]);
        $section2 = $foreignSurvey->sections()->create(['title' => 'الثاني', 'order' => 2]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$foreignSurvey->id}/sections/reorder", [
                'sections' => [$section2->id, $section1->id],
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor with SURVEYS_EDIT must not reorder sections of an org-B survey'
        );
        // Order must not have been changed.
        $this->assertSame(1, $section1->fresh()->order);
        $this->assertSame(2, $section2->fresh()->order);
    }

    public function test_cross_org_actor_with_edit_capability_cannot_update_foreign_section(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->draft()->create([
            'organization_id' => $this->orgB->id,
        ]);
        $foreignSection = $foreignSurvey->sections()->create([
            'title' => 'عنوان أصلي',
            'order' => 1,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->putJson("/api/surveys/{$foreignSurvey->id}/sections/{$foreignSection->id}", [
                'title' => 'عنوان مسروق',
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor with SURVEYS_EDIT must not update a section of an org-B survey'
        );
        // Title must not have been changed by a denied request.
        $this->assertSame('عنوان أصلي', $foreignSection->fresh()->title);
    }

    public function test_cross_org_actor_with_edit_capability_cannot_create_section_on_foreign_survey(): void
    {
        $actor = $this->makeCrossOrgActor();
        $foreignSurvey = Survey::factory()->draft()->create([
            'organization_id' => $this->orgB->id,
        ]);

        $response = $this->actingAs($actor, 'sanctum')
            ->postJson("/api/surveys/{$foreignSurvey->id}/sections", [
                'title' => 'قسم مسروق',
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'org-A actor with SURVEYS_EDIT must not create a section on an org-B survey'
        );
        $this->assertDatabaseMissing('survey_sections', [
            'survey_id' => $foreignSurvey->id,
            'title' => 'قسم مسروق',
        ]);
    }
}
