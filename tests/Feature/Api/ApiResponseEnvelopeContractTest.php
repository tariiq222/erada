<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Surveys\Enums\ImportStatus;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiResponseEnvelopeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_attachment_download_json_errors_have_envelope_and_authorized_stream_is_not_wrapped(): void
    {
        [$owner, $project] = $this->makeProjectContext();
        $crossOrgUser = $this->makeUserInOrganization(Organization::factory()->create());

        $storeResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/comments', [
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق بمرفق خاص',
            'attachments' => [UploadedFile::fake()->createWithContent('evidence.txt', 'private bytes')],
        ])->assertCreated();

        $attachment = Attachment::findOrFail($storeResponse->json('comment.attachments.0.id'));

        $downloadResponse = $this->actingAs($owner, 'sanctum')
            ->get("/api/attachments/{$attachment->id}/download");

        $downloadResponse->assertOk();
        $this->assertSame('private bytes', $downloadResponse->streamedContent());
        $this->assertFalse(str_contains((string) $downloadResponse->headers->get('content-type'), 'application/json'));

        $this->actingAs($crossOrgUser, 'sanctum')
            ->getJson("/api/attachments/{$attachment->id}/download")
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message']);

        Storage::disk('local')->delete($attachment->file_path);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/attachments/{$attachment->id}/download")
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_data_import_index_and_show_add_success_without_moving_resource_or_pagination_payloads(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->makeUserInOrganization($organization);
        $import = $this->makeImport($organization);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/data-imports')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $import->id)
            ->assertJsonPath('data.0.can_approve', false)
            ->assertJsonMissingPath('data.0.payload')
            ->assertJsonStructure(['success', 'data', 'links', 'meta' => ['current_page', 'per_page', 'total']]);

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/data-imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('id', $import->id)
            ->assertJsonPath('can_approve', false)
            ->assertJsonMissingPath('payload')
            ->assertJsonMissingPath('diff')
            ->assertJsonMissingPath('upsert_key_value');
    }

    public function test_ovr_incident_surfaces_add_success_without_reintroducing_pii(): void
    {
        [$user, $report] = $this->makeIncidentContext(['status' => ReportStatus::New]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.report_number', $report->report_number)
            ->assertJsonMissingPath('data.0.patient_name')
            ->assertJsonMissingPath('data.0.patient_file_number')
            ->assertJsonMissingPath('data.0.reporter_email');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.privacy_mode', 'detail')
            ->assertJsonPath('data.patient_name', 'Sensitive Patient');

        $this->getJson("/api/ovr/track/{$report->report_number}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.report_number', $report->report_number)
            ->assertJsonMissingPath('data.patient_name')
            ->assertJsonMissingPath('data.patient_file_number')
            ->assertJsonMissingPath('data.reporter_email');
    }

    public function test_activity_log_index_show_and_json_export_add_success_and_keep_redaction(): void
    {
        [$user, $activityLog] = $this->makeActivityLogContext();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/activity-logs?action=privacy_probe')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $activityLog->id)
            ->assertJsonPath('data.0.old_values.token', '[REDACTED]')
            ->assertJsonMissingPath('data.0.ip_address')
            ->assertJsonMissingPath('data.0.user.email');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/activity-logs/{$activityLog->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $activityLog->id)
            ->assertJsonPath('data.new_values.reporter_email', '[REDACTED]')
            ->assertJsonMissingPath('data.user_agent');

        $exportResponse = $this->actingAs($user, 'sanctum')
            ->get('/api/activity-logs/export?format=json&action=privacy_probe');

        $exportResponse->assertOk();
        $payload = json_decode($exportResponse->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame($activityLog->id, $payload['logs'][0]['id']);
        $this->assertSame('[REDACTED]', $payload['logs'][0]['old_values']['patient_name']);
        $this->assertArrayNotHasKey('ip_address', $payload['logs'][0]);
        $this->assertStringNotContainsString('reporter@example.test', $exportResponse->streamedContent());
    }

    public function test_public_survey_show_submit_and_errors_add_success_without_moving_version_hash(): void
    {
        $survey = Survey::factory()->published()->public()->create([
            'allow_multiple_responses' => true,
        ]);
        SurveyField::factory()->text()->create([
            'survey_id' => $survey->id,
            'field_key' => 'feedback',
            'name' => 'feedback',
            'is_required' => false,
        ]);

        $showResponse = $this->getJson("/api/surveys/public/{$survey->code}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data', 'version_hash']);

        $versionHash = $showResponse->json('version_hash');

        $this->postJson("/api/surveys/public/{$survey->code}/submit", [
            'answers' => ['feedback' => 'answer without hash'],
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['version_hash']);

        $this->postJson("/api/surveys/public/{$survey->code}/submit", [
            'answers' => ['feedback' => 'answer with stale hash'],
            'version_hash' => 'stale-version-hash',
        ])->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'version_mismatch');

        $this->postJson("/api/surveys/public/{$survey->code}/submit", [
            'answers' => ['feedback' => 'valid answer'],
            'version_hash' => $versionHash,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data', 'thank_you_message']);
    }

    /**
     * Regression: abort(401) inside a controller must surface a 401 envelope,
     * not silently fall through to the 500 fallback. Previously the global
     * handler only special-cased AuthenticationException + 403 HttpException;
     * a controller-level abort(401) raised HttpException(401) which leaked
     * into the 500 branch.
     */
    public function test_abort_401_inside_controller_returns_401_envelope_not_500(): void
    {
        // Bypass Authenticate middleware so the controller's internal abort(401)
        // runs (request()->user() is null). Throttle/CSRF are also dropped so
        // the GET request reaches the handler unmodified.
        $response = $this->withoutMiddleware()
            ->getJson('/api/activity-logs');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonStructure(['message', 'code', 'request_id']);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function makeProjectContext(): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $user = $this->makeUserInOrganization($organization, $department->id);
        $user->assignRole('admin');

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'created_by' => $user->id,
        ]);

        return [$user, $project];
    }

    private function makeUserInOrganization(Organization $organization, ?int $departmentId = null): User
    {
        $departmentId ??= Department::factory()->create(['organization_id' => $organization->id])->id;

        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $departmentId,
            'is_active' => true,
        ]);
        $user->assignRole('member');

        return $user;
    }

    private function makeImport(Organization $organization): DataImportRequest
    {
        $survey = Survey::factory()->create(['organization_id' => $organization->id]);
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

        return DataImportRequest::factory()->create([
            'response_id' => $response->id,
            'template_id' => $template->id,
            'payload' => ['name' => 'PAYLOAD-SECRET'],
            'diff' => ['name' => ['old' => 'old-secret', 'new' => 'PAYLOAD-SECRET']],
            'upsert_key_field' => 'name',
            'upsert_key_value' => 'UPSERT-SECRET',
            'error_message' => 'ERROR-SECRET',
            'status' => ImportStatus::Pending,
        ]);
    }

    /**
     * @return array{0: User, 1: IncidentReport}
     */
    private function makeIncidentContext(array $overrides = []): array
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $report = IncidentReport::create(array_merge([
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'reporter_name' => $user->name,
            'reporter_email' => 'reporter-pii@example.test',
            'reporter_department_id' => $department->id,
            'incident_datetime' => now(),
            'is_patient_related' => true,
            'patient_name' => 'Sensitive Patient',
            'patient_file_number' => 'PF-PRIV-001',
            'patient_gender' => 'female',
            'patient_dob' => '1985-03-12',
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'Privacy regression incident',
            'actions_taken' => 'Initial action',
            'contributing_factors' => ['privacy'],
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::Draft,
            'is_confidential' => false,
        ], $overrides));

        return [$user, $report];
    }

    /**
     * @return array{0: User, 1: ActivityLog}
     */
    private function makeActivityLogContext(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'audit-user@example.test',
            'is_active' => true,
        ]);
        // ActivityLogController now routes through AccessDecision (engine),
        // which grants audit.view/audit.export to the admin org functional role
        // (is_admin_role=true in scoped_role_definitions). Flat Spatie permissions
        // alone are not read by the engine — assign the admin role instead.
        $user->assignRole('admin');

        $activityLog = ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'privacy_probe',
            'description' => 'Updated sensitive record',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'old_values' => [
                'token' => 'raw-token-value',
                'patient_name' => 'Patient Old',
            ],
            'new_values' => [
                'reporter_email' => 'reporter@example.test',
            ],
            'metadata' => [
                'safe_context' => 'kept',
                'secret' => 'metadata-secret',
            ],
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Raw User Agent',
            'target_user_id' => $user->id,
            'scope_type' => 'organization',
            'scope_id' => $organization->id,
            'role' => 'admin',
            'reason' => 'Privacy test',
        ]);

        return [$user, $activityLog];
    }
}
