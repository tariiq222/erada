<?php

namespace Tests\Feature\Surveys;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\FieldType;
use App\Modules\Surveys\Enums\InvitationStatus;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyInvitation;
use App\Modules\Surveys\Services\VersioningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicSurveyControllerTopUpTest extends TestCase
{
    use DatabaseTransactions;

    private Organization $organization;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->creator = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    public function test_show_returns_latest_or_revision_and_rejects_missing_private_and_inactive_surveys(): void
    {
        $survey = $this->survey(['code' => 'PUB-001', 'revision' => 1, 'title' => 'Revision One']);
        SurveyField::factory()->create(['survey_id' => $survey->id, 'field_key' => 'name', 'type' => FieldType::Text->value]);
        $revisionTwo = $this->survey(['code' => 'PUB-001', 'revision' => 2, 'title' => 'Revision Two']);
        SurveyField::factory()->create(['survey_id' => $revisionTwo->id, 'field_key' => 'name', 'type' => FieldType::Text->value]);

        $this->getJson('/api/surveys/public/missing-code')->assertNotFound();

        $private = Survey::factory()->published()->create(['organization_id' => $this->organization->id, 'created_by' => $this->creator->id, 'code' => 'PRIVATE']);
        $this->getJson('/api/surveys/public/PRIVATE')->assertForbidden();

        $inactive = Survey::factory()->public()->published()->create(['organization_id' => $this->organization->id, 'created_by' => $this->creator->id, 'code' => 'INACTIVE', 'starts_at' => now()->addDay()]);
        $this->getJson('/api/surveys/public/INACTIVE')
            ->assertForbidden()
            ->assertJsonPath('status', SurveyStatus::Published->value);

        $this->getJson('/api/surveys/public/PUB-001')
            ->assertOk()
            ->assertJsonPath('data.title', 'Revision Two')
            ->assertJsonStructure(['data', 'version_hash']);

        $this->getJson('/api/surveys/public/PUB-001?rev=1')
            ->assertOk()
            ->assertJsonPath('data.title', 'Revision One');
    }

    public function test_submit_rejects_version_mismatch_invalid_payload_and_accepts_valid_response(): void
    {
        $survey = $this->survey(['code' => 'SUBMIT-001', 'thank_you_message' => 'Thanks']);
        SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'field_key' => 'name',
            'name' => 'name',
            'label' => 'Name',
            'type' => FieldType::Text->value,
            'is_required' => true,
        ]);
        $hash = (new VersioningService)->getOrCreateVersion($survey->fresh(['sections.fields', 'fields']))->version_hash;

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson('/api/surveys/public/SUBMIT-001/submit', [
            'version_hash' => 'wrong-hash',
            'answers' => ['name' => 'A'],
        ])->assertStatus(409)
            ->assertJsonPath('error', 'version_mismatch');

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson('/api/surveys/public/SUBMIT-001/submit', [
            'version_hash' => $hash,
            'answers' => [],
            'respondent_name' => 'bad<script>',
        ])->assertStatus(422);

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson('/api/surveys/public/SUBMIT-001/submit', [
            'version_hash' => $hash,
            'answers' => ['name' => 'Valid Respondent'],
            'respondent_name' => 'Valid Respondent',
            'respondent_email' => 'valid@example.test',
            'completion_time' => 10,
        ], ['X-Fingerprint-Hash' => 'fp-submit'])
            ->assertCreated()
            ->assertJsonPath('message', 'تم إرسال الإجابة بنجاح')
            ->assertJsonPath('thank_you_message', 'Thanks');

        $this->assertDatabaseHas('survey_responses', [
            'survey_id' => $survey->id,
            'respondent_email' => 'valid@example.test',
            'fingerprint_hash' => 'fp-submit',
        ]);
    }

    public function test_invitation_show_and_submit_cover_missing_unusable_mismatch_and_success_paths(): void
    {
        $survey = $this->survey(['code' => 'INV-PUB']);
        SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'field_key' => 'answer',
            'name' => 'answer',
            'label' => 'Answer',
            'type' => FieldType::Text->value,
            'is_required' => true,
        ]);
        $hash = (new VersioningService)->getOrCreateVersion($survey->fresh(['sections.fields', 'fields']))->version_hash;
        $active = SurveyInvitation::create([
            'survey_id' => $survey->id,
            'email' => 'invitee@example.test',
            'name' => 'Invitee',
            'status' => InvitationStatus::Active,
            'expires_at' => now()->addDay(),
            'max_uses' => 1,
            'used_count' => 0,
        ]);
        $expired = SurveyInvitation::create([
            'survey_id' => $survey->id,
            'email' => 'expired@example.test',
            'name' => 'Expired',
            'status' => InvitationStatus::Active,
            'expires_at' => now()->subMinute(),
            'max_uses' => 1,
            'used_count' => 0,
        ]);

        $this->getJson('/api/surveys/public/invitation/not-a-token')->assertNotFound();
        $this->getJson("/api/surveys/public/invitation/{$expired->token}")
            ->assertForbidden()
            ->assertJsonPath('status', InvitationStatus::Expired->value);

        $this->getJson("/api/surveys/public/invitation/{$active->token}")
            ->assertOk()
            ->assertJsonPath('invitation.email', 'invitee@example.test');
        $this->assertNotNull($active->fresh()->opened_at);

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson("/api/surveys/public/invitation/{$active->token}/submit", [
            'version_hash' => 'bad-hash',
            'answers' => ['answer' => 'A'],
        ])->assertStatus(409);

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson("/api/surveys/public/invitation/{$active->token}/submit", [
            'version_hash' => $hash,
            'answers' => ['answer' => 'Invitation answer'],
            'respondent_name' => 'Request Name',
            'respondent_email' => 'request@example.test',
        ])->assertCreated()
            ->assertJsonPath('message', 'تم إرسال الإجابة بنجاح');

        $active->refresh();
        $this->assertSame(InvitationStatus::Used, $active->status);
        $this->assertDatabaseHas('survey_responses', [
            'survey_id' => $survey->id,
            'invitation_id' => $active->id,
            'respondent_email' => 'invitee@example.test',
        ]);

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson("/api/surveys/public/invitation/{$active->token}/submit", [
            'version_hash' => $hash,
            'answers' => ['answer' => 'Again'],
        ])->assertForbidden();
    }

    private function survey(array $overrides = []): Survey
    {
        return Survey::factory()->published()->public()->create(array_merge([
            'organization_id' => $this->organization->id,
            'created_by' => $this->creator->id,
            'settings' => [],
        ], $overrides));
    }
}
