<?php

namespace Tests\Feature\Surveys;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NestedSurveyResourceBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeAdmin(Organization $organization): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($user);

        return $user;
    }

    private function makeDraftSurvey(Organization $organization): Survey
    {
        return Survey::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'draft',
        ]);
    }

    private function makePublishedSurvey(Organization $organization): Survey
    {
        return Survey::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'published',
        ]);
    }

    public function test_section_from_sibling_survey_cannot_be_updated_and_persists(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeAdmin($organization);
        $surveyOne = $this->makeDraftSurvey($organization);
        $surveyTwo = $this->makeDraftSurvey($organization);
        $section = $surveyTwo->sections()->create([
            'title' => 'Original section',
            'order' => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/surveys/{$surveyOne->id}/sections/{$section->id}", [
                'title' => 'Hijacked section',
            ])->assertNotFound();

        $this->assertDatabaseHas('survey_sections', [
            'id' => $section->id,
            'survey_id' => $surveyTwo->id,
            'title' => 'Original section',
        ]);
    }

    public function test_section_from_sibling_survey_returns_404_when_route_survey_is_non_editable(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeAdmin($organization);
        $publishedSurvey = $this->makePublishedSurvey($organization);
        $draftSurvey = $this->makeDraftSurvey($organization);
        $section = $draftSurvey->sections()->create([
            'title' => 'Original section under draft sibling',
            'order' => 1,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/surveys/{$publishedSurvey->id}/sections/{$section->id}", [
                'title' => 'Hijacked section',
            ])
            ->assertStatus(404)
            ->assertJsonMissingValidationErrors(['survey', 'title']);

        $this->assertDatabaseHas('survey_sections', [
            'id' => $section->id,
            'survey_id' => $draftSurvey->id,
            'title' => 'Original section under draft sibling',
        ]);
    }

    public function test_section_of_non_editable_survey_in_same_parent_still_blocks_with_validation_error(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeAdmin($organization);
        $publishedSurvey = $this->makePublishedSurvey($organization);
        $section = $publishedSurvey->sections()->create([
            'title' => 'Section under published survey',
            'order' => 1,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/surveys/{$publishedSurvey->id}/sections/{$section->id}", [
                'title' => 'Attempted edit',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['survey']);

        $this->assertDatabaseHas('survey_sections', [
            'id' => $section->id,
            'survey_id' => $publishedSurvey->id,
            'title' => 'Section under published survey',
        ]);
    }

    public function test_field_from_sibling_survey_cannot_be_deleted_and_persists(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeAdmin($organization);
        $surveyOne = $this->makeDraftSurvey($organization);
        $surveyTwo = $this->makeDraftSurvey($organization);
        $field = SurveyField::factory()->create([
            'survey_id' => $surveyTwo->id,
            'name' => 'Original field',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/surveys/{$surveyOne->id}/fields/{$field->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('survey_fields', [
            'id' => $field->id,
            'survey_id' => $surveyTwo->id,
            'name' => 'Original field',
        ]);
    }

    public function test_response_from_sibling_survey_cannot_be_reviewed_and_persists(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeAdmin($organization);
        $surveyOne = $this->makeDraftSurvey($organization);
        $surveyTwo = $this->makeDraftSurvey($organization);
        $response = SurveyResponse::factory()->create([
            'survey_id' => $surveyTwo->id,
            'status' => ResponseStatus::Submitted,
            'reviewer_notes' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/surveys/{$surveyOne->id}/responses/{$response->id}/review", [
                'status' => ResponseStatus::Invalid->value,
                'notes' => 'Should not persist',
            ])->assertNotFound();

        $this->assertDatabaseHas('survey_responses', [
            'id' => $response->id,
            'survey_id' => $surveyTwo->id,
            'status' => ResponseStatus::Submitted->value,
            'reviewer_notes' => null,
        ]);
    }

    public function test_mapping_from_sibling_survey_cannot_be_destroyed_and_persists(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeAdmin($organization);
        $surveyOne = $this->makeDraftSurvey($organization);
        $surveyTwo = $this->makeDraftSurvey($organization);
        $template = DataMappingTemplate::factory()->create([
            'survey_id' => $surveyTwo->id,
            'name' => 'Original mapping',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/surveys/{$surveyOne->id}/mappings/{$template->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('data_mapping_templates', [
            'id' => $template->id,
            'survey_id' => $surveyTwo->id,
            'name' => 'Original mapping',
        ]);
    }
}
