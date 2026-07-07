<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Enums\ConflictPolicy;
use App\Modules\Surveys\Enums\ImportStatus;
use App\Modules\Surveys\Enums\InsertPolicy;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Services\DataMappingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataImportPrivacyAndMappingSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;

    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();
    }

    private function makeUser(Organization $org, string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeImport(Organization $org, array $overrides = []): DataImportRequest
    {
        $survey = Survey::factory()->create(['organization_id' => $org->id]);
        $response = SurveyResponse::factory()->create([
            'survey_id' => $survey->id,
            'respondent_name' => 'Sensitive Respondent',
        ]);
        $field = SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'field_key' => 'secret_answer',
            'name' => 'secret_answer',
        ]);
        $response->answers()->create([
            'field_id' => $field->id,
            'field_key' => 'secret_answer',
            'answer_value' => 'RAW-ANSWER-SECRET',
        ]);
        $template = DataMappingTemplate::factory()->create(['survey_id' => $survey->id]);

        return DataImportRequest::factory()->create(array_merge([
            'response_id' => $response->id,
            'template_id' => $template->id,
            'payload' => ['name' => 'PAYLOAD-SECRET', 'password' => 'hidden-password'],
            'diff' => ['name' => ['old' => 'old-secret', 'new' => 'PAYLOAD-SECRET']],
            'upsert_key_field' => 'name',
            'upsert_key_value' => 'UPSERT-SECRET',
            'error_message' => 'ERROR-SECRET',
            'status' => ImportStatus::Pending,
        ], $overrides));
    }

    public function test_non_reviewer_index_and_show_are_redacted_and_action_flags_are_permission_aware(): void
    {
        $member = $this->makeUser($this->orgA, 'member');
        $import = $this->makeImport($this->orgA);

        $index = $this->actingAs($member, 'sanctum')->getJson('/api/data-imports')->assertOk();
        $index->assertJsonPath('data.0.id', $import->id)
            ->assertJsonPath('data.0.can_approve', false)
            ->assertJsonPath('data.0.can_reject', false)
            ->assertJsonMissingPath('data.0.payload')
            ->assertJsonMissingPath('data.0.diff')
            ->assertJsonMissingPath('data.0.upsert_key_value')
            ->assertJsonMissingPath('data.0.error_message');

        $show = $this->actingAs($member, 'sanctum')->getJson("/api/data-imports/{$import->id}")->assertOk();
        $show->assertJsonPath('can_approve', false)
            ->assertJsonPath('can_reject', false)
            ->assertJsonMissingPath('payload')
            ->assertJsonMissingPath('diff')
            ->assertJsonMissingPath('upsert_key_value')
            ->assertJsonMissingPath('error_message');

        $this->assertStringNotContainsString('PAYLOAD-SECRET', $show->getContent());
        $this->assertStringNotContainsString('RAW-ANSWER-SECRET', $show->getContent());
        $this->assertStringNotContainsString('UPSERT-SECRET', $show->getContent());
    }

    public function test_reviewer_show_receives_detail_fields_and_status_flags(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin');
        $import = $this->makeImport($this->orgA);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/data-imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('payload.name', 'PAYLOAD-SECRET')
            ->assertJsonPath('diff.name.new', 'PAYLOAD-SECRET')
            ->assertJsonPath('upsert_key_value', 'UPSERT-SECRET')
            ->assertJsonPath('can_approve', true)
            ->assertJsonPath('can_reject', true)
            ->assertJsonPath('can_apply', false);
    }

    public function test_mapping_store_and_update_reject_unknown_sensitive_columns_and_transforms(): void
    {
        $admin = $this->makeUser($this->orgA, 'admin');
        $survey = Survey::factory()->create(['organization_id' => $this->orgA->id]);

        foreach (['organization_id', 'password', 'is_active', 'roles', 'remember_token', 'email_verified_at', 'created_at', 'updated_at', 'arbitrary_column'] as $column) {
            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/surveys/{$survey->id}/mappings", $this->mappingPayload($column))
                ->assertStatus(422);
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/surveys/{$survey->id}/mappings", $this->mappingPayload('name', ['not_a_transform']))
            ->assertStatus(422);

        $template = DataMappingTemplate::factory()->create(['survey_id' => $survey->id]);
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/surveys/{$survey->id}/mappings/{$template->id}", ['mappings' => ['bad' => ['column' => 'password']]])
            ->assertStatus(422);
    }

    public function test_department_and_user_transforms_are_scoped_to_survey_organization(): void
    {
        $deptA = Department::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Shared Department']);
        $deptB = Department::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Shared Department']);
        $userB = User::factory()->create(['organization_id' => $this->orgB->id, 'email' => 'shared@example.com']);
        $survey = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $template = DataMappingTemplate::factory()->create([
            'survey_id' => $survey->id,
            'target_model' => 'departments',
            'mappings' => [
                'parent' => ['column' => 'parent_id', 'transforms' => ['map_department_by_name']],
                'manager' => ['column' => 'manager_id', 'transforms' => ['map_user_by_email']],
            ],
        ]);

        $payload = app(DataMappingService::class)->transformAnswersToPayload($template, [
            'parent' => 'Shared Department',
            'manager' => 'shared@example.com',
        ], $survey->organization_id);

        $this->assertSame($deptA->id, $payload['parent_id']);
        $this->assertNull($payload['manager_id']);
        $this->assertNotSame($deptB->id, $payload['parent_id']);
        $this->assertNotSame($userB->id, $payload['manager_id']);
    }

    public function test_apply_refuses_cross_org_update_and_sanitizes_sensitive_columns_on_create(): void
    {
        $survey = Survey::factory()->create(['organization_id' => $this->orgA->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);
        $template = DataMappingTemplate::factory()->create(['survey_id' => $survey->id]);
        $otherDepartment = Department::factory()->create([
            'organization_id' => $this->orgB->id,
            'name' => 'Other Org Original',
            'is_active' => true,
        ]);

        $update = DataImportRequest::factory()->approved()->create([
            'response_id' => $response->id,
            'template_id' => $template->id,
            'target_id' => $otherDepartment->id,
            'operation' => 'update',
            'payload' => ['name' => 'Cross Org Write', 'organization_id' => $this->orgA->id, 'is_active' => false],
        ]);

        $this->assertFalse(app(DataMappingService::class)->applyImportRequest($update));
        $this->assertSame('Other Org Original', $otherDepartment->fresh()->name);
        $this->assertTrue($otherDepartment->fresh()->is_active);

        $create = DataImportRequest::factory()->approved()->create([
            'response_id' => $response->id,
            'template_id' => $template->id,
            'operation' => 'create',
            'payload' => ['name' => 'Safe Department', 'organization_id' => $this->orgB->id, 'is_active' => false],
        ]);

        $this->assertTrue(app(DataMappingService::class)->applyImportRequest($create));
        $created = Department::findOrFail($create->fresh()->applied_id);
        $this->assertSame($this->orgA->id, $created->organization_id);
        $this->assertTrue($created->is_active);
    }

    private function mappingPayload(string $column, array $transforms = []): array
    {
        return [
            'name' => 'اختبار الربط',
            'target_model' => 'departments',
            'mappings' => [
                'field' => [
                    'column' => $column,
                    'transforms' => $transforms,
                ],
            ],
            'insert_policy' => InsertPolicy::Upsert->value,
            'conflict_policy' => ConflictPolicy::RequireReview->value,
            'is_active' => true,
        ];
    }
}
