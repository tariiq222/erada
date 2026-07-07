<?php

namespace Tests\Unit\Surveys;

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
use App\Modules\Surveys\Models\SurveyFieldAnswer;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Services\DataMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DataMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private DataMappingService $service;

    private Organization $organization;

    private User $creator;

    private Survey $survey;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->service = new DataMappingService;
        $this->organization = Organization::factory()->create();
        $this->creator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->survey = Survey::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->creator->id,
        ]);
    }

    public function test_transform_answers_to_payload_applies_transforms_and_drops_sensitive_columns(): void
    {
        $manager = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => 'manager@example.test',
        ]);
        $template = $this->template([
            'dept_name' => ['column' => 'name', 'transforms' => ['trim']],
            'dept_level' => ['column' => 'level', 'transforms' => ['to_integer']],
            'manager_email' => ['column' => 'manager_id', 'transforms' => ['map_user_by_email']],
            'password' => ['column' => 'password', 'transforms' => ['trim']],
            'ignored' => ['column' => 'not_allowed'],
        ]);

        $payload = $this->service->transformAnswersToPayload($template, [
            'dept_name' => '  Quality  ',
            'dept_level' => '3',
            'manager_email' => 'MANAGER@EXAMPLE.TEST',
            'password' => 'secret',
            'ignored' => 'x',
        ], $this->organization->id);

        $this->assertSame([
            'name' => 'Quality',
            'level' => 3,
            'manager_id' => $manager->id,
        ], $payload);
    }

    public function test_create_import_requests_from_response_requires_required_answers_and_detects_update_diff(): void
    {
        $existing = Department::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Existing Department',
            'code' => 'EX-1',
            'level' => 2,
        ]);
        $template = $this->template([
            'code' => ['column' => 'code', 'required' => true, 'upsert_key' => true, 'transforms' => ['trim']],
            'name' => ['column' => 'name', 'required' => true, 'transforms' => ['trim']],
            'level' => ['column' => 'level', 'transforms' => ['to_integer']],
        ], InsertPolicy::Upsert, ConflictPolicy::RequireReview);
        $response = SurveyResponse::factory()->create(['survey_id' => $this->survey->id]);
        $this->answer($response, 'code', ' EX-1 ');
        $this->answer($response, 'name', ' Updated Department ');
        $this->answer($response, 'level', '3');

        $requests = $this->service->createImportRequestsFromResponse($response->fresh('survey.activeMappingTemplate', 'answers'));

        $this->assertCount(1, $requests);
        $request = $requests[0]->fresh();
        $this->assertSame('update', $request->operation);
        $this->assertSame($existing->id, $request->target_id);
        $this->assertSame(ImportStatus::Pending, $request->status);
        $this->assertSame('EX-1', $request->upsert_key_value);
        $this->assertSame('Updated Department', $request->payload['name']);
        $this->assertSame(['old' => 'Existing Department', 'new' => 'Updated Department'], $request->diff['name']);

        $missingRequiredResponse = SurveyResponse::factory()->create(['survey_id' => $this->survey->id]);
        $this->assertSame([], $this->service->createImportRequestsFromResponse($missingRequiredResponse->fresh('survey.activeMappingTemplate', 'answers')));
    }

    public function test_apply_import_request_creates_updates_fails_and_bulk_applies_only_approved_requests(): void
    {
        $createTemplate = $this->template([
            'name' => ['column' => 'name'],
            'code' => ['column' => 'code'],
            'level' => ['column' => 'level'],
            'organization_id' => ['column' => 'organization_id'],
        ], InsertPolicy::CreateOnly, ConflictPolicy::Overwrite);
        $response = SurveyResponse::factory()->create(['survey_id' => $this->survey->id]);
        $createRequest = DataImportRequest::factory()->approved()->create([
            'response_id' => $response->id,
            'template_id' => $createTemplate->id,
            'target_table' => 'departments',
            'operation' => 'create',
            'payload' => ['name' => 'Created Department', 'code' => 'CR-1', 'level' => 1, 'organization_id' => 999],
        ]);

        $this->assertTrue($this->service->applyImportRequest($createRequest));
        $this->assertDatabaseHas('departments', [
            'name' => 'Created Department',
            'code' => 'CR-1',
            'organization_id' => $this->organization->id,
        ]);
        $this->assertSame(ImportStatus::Applied, $createRequest->fresh()->status);

        $department = Department::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Before']);
        $updateRequest = DataImportRequest::factory()->approved()->create([
            'response_id' => $response->id,
            'template_id' => $createTemplate->id,
            'target_table' => 'departments',
            'target_id' => $department->id,
            'operation' => 'update',
            'payload' => ['name' => 'After'],
        ]);
        $this->assertTrue($this->service->applyImportRequest($updateRequest));
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'After']);

        $outsideDepartment = Department::factory()->create(['name' => 'Outside']);
        $failingRequest = DataImportRequest::factory()->approved()->create([
            'response_id' => $response->id,
            'template_id' => $createTemplate->id,
            'target_table' => 'departments',
            'target_id' => $outsideDepartment->id,
            'operation' => 'update',
            'payload' => ['name' => 'Should Not Apply'],
            'reviewed_by' => $this->creator->id,
        ]);
        $this->assertFalse($this->service->applyImportRequest($failingRequest));
        $this->assertSame(ImportStatus::Failed, $failingRequest->fresh()->status);
        $this->assertStringContainsString('خارج مؤسسة الاستبيان', $failingRequest->fresh()->error_message);

        $bulkApproved = DataImportRequest::factory()->approved()->create([
            'response_id' => $response->id,
            'template_id' => $createTemplate->id,
            'operation' => 'create',
            'payload' => ['name' => 'Bulk Department'],
        ]);
        $bulkPending = DataImportRequest::factory()->pending()->create([
            'response_id' => $response->id,
            'template_id' => $createTemplate->id,
        ]);

        $result = $this->service->bulkApply([$bulkApproved->id, $bulkPending->id, $failingRequest->id]);
        $this->assertSame([$bulkApproved->id], $result['success']);
        $this->assertSame([], $result['failed']);
    }

    private function template(array $mappings, InsertPolicy $insertPolicy = InsertPolicy::Upsert, ConflictPolicy $conflictPolicy = ConflictPolicy::RequireReview): DataMappingTemplate
    {
        return DataMappingTemplate::factory()->create([
            'survey_id' => $this->survey->id,
            'created_by' => $this->creator->id,
            'target_model' => 'departments',
            'mappings' => $mappings,
            'insert_policy' => $insertPolicy,
            'conflict_policy' => $conflictPolicy,
            'is_active' => true,
        ]);
    }

    private function answer(SurveyResponse $response, string $fieldKey, mixed $value): SurveyFieldAnswer
    {
        $field = SurveyField::factory()->create([
            'survey_id' => $response->survey_id,
            'field_key' => $fieldKey,
            'name' => $fieldKey,
            'type' => is_numeric($value) ? 'number' : 'text',
        ]);

        return SurveyFieldAnswer::create([
            'response_id' => $response->id,
            'field_id' => $field->id,
            'field_key' => $fieldKey,
            'answer_value' => $value,
            'answer_text' => is_scalar($value) ? (string) $value : null,
        ]);
    }
}
