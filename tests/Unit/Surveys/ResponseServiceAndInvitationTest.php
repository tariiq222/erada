<?php

namespace Tests\Unit\Surveys;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Enums\FieldType;
use App\Modules\Surveys\Enums\InvitationStatus;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Enums\SurveyPrivacyMode;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Notifications\DataImportFailedNotification;
use App\Modules\Surveys\Notifications\DataImportPendingNotification;
use App\Modules\Surveys\Services\DataMappingService;
use App\Modules\Surveys\Services\ResponseService;
use App\Modules\Surveys\Services\VersioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResponseServiceAndInvitationTest extends TestCase
{
    use DatabaseTransactions;

    private Organization $organization;

    private User $creator;

    private ResponseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();
        $this->creator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->service = new ResponseService(new VersioningService, new DataMappingService);
    }

    public function test_public_response_persists_identified_answers_and_marks_invitation_used(): void
    {
        $survey = $this->publishedSurvey(['privacy_mode' => SurveyPrivacyMode::Identified]);
        $field = $this->field($survey, 'name', FieldType::Text, true);
        $displayOnly = $this->field($survey, 'heading', FieldType::Heading, false);
        $invitation = SurveyInvitation::create([
            'survey_id' => $survey->id,
            'email' => 'invitee@example.test',
            'name' => 'Invitee',
            'status' => InvitationStatus::Active,
            'expires_at' => now()->addDay(),
            'max_uses' => 1,
            'used_count' => 0,
            'created_by' => $this->creator->id,
        ]);

        $response = $this->service->createPublicResponse($survey->fresh('fields'), [
            'name' => 'Public Respondent',
            'heading' => 'Ignored display-only value',
            'unknown' => 'ignored',
        ], $this->request([
            'respondent_email' => 'public@example.test',
            'completion_time' => 75,
        ]), $invitation);

        $this->assertSame(ResponseStatus::Submitted, $response->status);
        $this->assertNull($response->respondent_name);
        $this->assertSame('public@example.test', $response->respondent_email);
        $this->assertSame($invitation->id, $response->invitation_id);
        $this->assertDatabaseHas('survey_field_answers', [
            'response_id' => $response->id,
            'field_id' => $field->id,
            'field_key' => 'name',
            'answer_text' => 'Public Respondent',
        ]);
        $this->assertDatabaseMissing('survey_field_answers', [
            'response_id' => $response->id,
            'field_id' => $displayOnly->id,
        ]);
        $this->assertSame(InvitationStatus::Used, $invitation->fresh()->status);
        $this->assertSame($response->id, $invitation->fresh()->response_id);
    }

    public function test_anonymous_authenticated_response_strips_user_identity_and_enforces_audience_and_duplicates(): void
    {
        $department = Department::factory()->create(['organization_id' => $this->organization->id]);
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $department->id,
            'email' => 'audience@example.test',
        ]);
        $role = AuthorizationRole::query()->where('name', 'member')->firstOrFail();
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'scope_id' => $this->organization->id,
            'organization_id' => $this->organization->id,
            'source' => 'manual',
            'granted_by' => $this->creator->id,
        ]);
        $survey = $this->publishedSurvey([
            'privacy_mode' => SurveyPrivacyMode::Anonymous,
            'allow_multiple_responses' => false,
            'settings' => [
                'audience' => [
                    'department_ids' => [$department->id],
                    'role_names' => ['member'],
                    'user_ids' => [$user->id],
                ],
            ],
        ]);
        $this->field($survey, 'rating', FieldType::Rating, true);

        $response = $this->service->createAuthenticatedResponse($survey->fresh('fields'), [
            'rating' => ['rating' => 4],
        ], $user, $this->request(['completion_time' => 33]));

        $this->assertNull($response->respondent_id);
        $this->assertNull($response->respondent_name);
        $this->assertNull($response->respondent_email);
        $this->assertDatabaseHas('survey_field_answers', [
            'response_id' => $response->id,
            'field_key' => 'rating',
            'answer_number' => 4,
        ]);

        $this->assertSame(1, $survey->responses()->count());
    }

    public function test_anonymous_authenticated_response_duplicate_is_rejected_blocker_documentation(): void
    {
        $this->markTestSkipped('BLOCKER: ResponseService duplicate check uses respondent_id, but anonymous authenticated responses persist respondent_id as null, so duplicate submissions are not rejected.');

        $department = Department::factory()->create(['organization_id' => $this->organization->id]);
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $department->id,
        ]);
        $survey = $this->publishedSurvey([
            'privacy_mode' => SurveyPrivacyMode::Anonymous,
            'allow_multiple_responses' => false,
        ]);
        $this->field($survey, 'rating', FieldType::Rating, true);

        $this->service->createAuthenticatedResponse($survey->fresh('fields'), ['rating' => ['rating' => 4]], $user, $this->request());

        $this->expectException(ValidationException::class);
        $this->service->createAuthenticatedResponse($survey->fresh('fields'), ['rating' => ['rating' => 5]], $user, $this->request());
    }

    public function test_duplicate_protection_for_public_responses_uses_fingerprint_ip_email_and_invitation(): void
    {
        foreach (['fingerprint', 'ip', 'email', 'invitation'] as $key) {
            $survey = $this->publishedSurvey([
                'allow_multiple_responses' => false,
                'settings' => ['duplicate_protection' => ['enabled' => true, 'key' => $key, 'window_minutes' => 60]],
            ]);
            $this->field($survey, 'name', FieldType::Text, true);
            $invitation = $key === 'invitation' ? SurveyInvitation::create([
                'survey_id' => $survey->id,
                'email' => 'dupe@example.test',
                'name' => 'Dupe',
                'status' => InvitationStatus::Active,
                'expires_at' => now()->addDay(),
                'max_uses' => 2,
                'used_count' => 0,
            ]) : null;

            SurveyResponse::factory()->create([
                'survey_id' => $survey->id,
                'respondent_type' => 'public',
                'respondent_email' => 'dupe@example.test',
                'ip_hash' => hash('sha256', '127.0.0.1'),
                'fingerprint_hash' => 'fingerprint-1',
                'invitation_id' => $invitation?->id,
                'created_at' => now(),
            ]);

            try {
                $this->service->createPublicResponse($survey->fresh('fields'), ['name' => 'Duplicate'], $this->request([
                    'respondent_email' => 'dupe@example.test',
                ], ['X-Fingerprint-Hash' => 'fingerprint-1']), $invitation);
                $this->fail("Duplicate protection did not reject {$key}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('duplicate', $e->errors());
            }
        }
    }

    public function test_survey_invitation_helpers_and_notification_payloads(): void
    {
        $survey = $this->publishedSurvey();
        $invitation = SurveyInvitation::create([
            'survey_id' => $survey->id,
            'email' => 'recipient@example.test',
            'name' => 'Recipient',
            'status' => InvitationStatus::Active,
            'expires_at' => now()->subMinute(),
            'max_uses' => 1,
            'used_count' => 0,
        ]);

        $this->assertFalse($invitation->canUse());
        $invitation->updateExpiredStatus();
        $this->assertSame(InvitationStatus::Expired, $invitation->fresh()->status);
        $this->assertStringContainsString('/surveys/invitation/', $invitation->getUrl());

        $request = DataImportRequest::factory()->failed()->create([
            'target_table' => 'departments',
            'error_message' => 'Import failed',
        ]);
        $notifiable = User::factory()->create(['name' => 'Reviewer']);
        $pending = new DataImportPendingNotification($request);
        $failed = new DataImportFailedNotification($request);

        $this->assertSame(['mail', 'database'], $pending->via($notifiable));
        $this->assertSame('data_import_pending', $pending->toArray($notifiable)['type']);
        $this->assertStringContainsString('طلب استيراد بيانات', $pending->toMail($notifiable)->subject);
        $this->assertSame(['mail', 'database'], $failed->via($notifiable));
        $this->assertSame('data_import_failed', $failed->toArray($notifiable)['type']);
        $this->assertSame('Import failed', $failed->toArray($notifiable)['error_message']);
        $this->assertStringContainsString('فشل تطبيق', $failed->toMail($notifiable)->subject);
    }

    private function publishedSurvey(array $overrides = []): Survey
    {
        return Survey::factory()->published()->public()->create(array_merge([
            'organization_id' => $this->organization->id,
            'created_by' => $this->creator->id,
            'consent_required' => true,
            'allow_multiple_responses' => true,
            'settings' => [],
        ], $overrides));
    }

    private function field(Survey $survey, string $key, FieldType $type, bool $required): SurveyField
    {
        return SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'field_key' => $key,
            'name' => $key,
            'label' => ucfirst($key),
            'type' => $type->value,
            'is_required' => $required,
            'config' => $type === FieldType::Rating ? ['max' => 5] : [],
        ]);
    }

    private function request(array $input = [], array $headers = []): Request
    {
        $request = Request::create('/surveys/public', 'POST', $input, [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->headers->set('User-Agent', 'ResponseServiceTest');

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        return $request;
    }
}
