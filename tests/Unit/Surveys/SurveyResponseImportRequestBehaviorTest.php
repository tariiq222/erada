<?php

namespace Tests\Unit\Surveys;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\FieldType;
use App\Modules\Surveys\Enums\ImportStatus;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Models\DataImportRequest;
use App\Modules\Surveys\Models\DataMappingTemplate;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyFieldAnswer;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyResponseImportRequestBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Survey $survey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->survey = Survey::factory()->published()->public()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_survey_response_scopes_review_flags_answers_and_display_name(): void
    {
        $field = SurveyField::factory()->create([
            'survey_id' => $this->survey->id,
            'field_key' => 'department_name',
            'type' => FieldType::Text->value,
        ]);
        $response = SurveyResponse::factory()->create([
            'survey_id' => $this->survey->id,
            'respondent_type' => 'user',
            'respondent_id' => $this->user->id,
            'respondent_name' => 'Fallback Name',
            'status' => ResponseStatus::Submitted,
        ]);
        SurveyFieldAnswer::createFromValue($response, $field, 'Operations');
        SurveyResponse::factory()->create([
            'survey_id' => $this->survey->id,
            'respondent_type' => 'public',
            'respondent_name' => 'Public Person',
            'status' => ResponseStatus::Flagged,
        ]);

        $this->assertTrue($response->isFromAuthenticatedUser());
        $this->assertSame(1, SurveyResponse::submitted()->whereKey($response->id)->count());
        $this->assertSame(1, SurveyResponse::fromUsers()->whereKey($response->id)->count());
        $this->assertSame(1, SurveyResponse::fromPublic()->count());
        $this->assertSame(1, SurveyResponse::flagged()->count());
        $this->assertSame('Operations', $response->getAnswerValue('department_name'));
        $this->assertSame(['department_name' => 'Operations'], $response->fresh('answers')->getAnswersAsArray());
        $this->assertSame($this->user->name, $response->fresh('respondent')->getRespondentDisplayName());

        $response->flag('Suspicious answer');
        $this->assertSame(ResponseStatus::Flagged, $response->fresh()->status);
        $this->assertSame('Suspicious answer', $response->fresh()->reviewer_notes);

        $response->markAsReviewed($this->user, 'Reviewed manually');
        $this->assertSame($this->user->id, $response->fresh()->reviewed_by);
        $this->assertSame('Reviewed manually', $response->fresh()->reviewer_notes);
    }

    public function test_data_import_request_status_transitions_scopes_relations_and_helpers(): void
    {
        $response = SurveyResponse::factory()->create(['survey_id' => $this->survey->id]);
        $template = DataMappingTemplate::factory()->create([
            'survey_id' => $this->survey->id,
            'target_model' => 'departments',
        ]);
        $request = DataImportRequest::factory()->create([
            'response_id' => $response->id,
            'template_id' => $template->id,
            'target_table' => 'departments',
            'operation' => 'upsert',
            'status' => ImportStatus::Pending,
            'diff' => ['name' => ['old' => 'Old', 'new' => 'New']],
        ]);

        $this->assertSame(1, DataImportRequest::pending()->whereKey($request->id)->count());
        $this->assertTrue($request->canApprove());
        $this->assertTrue($request->canReject());
        $this->assertFalse($request->canApply());
        $this->assertSame($response->id, $request->response->id);
        $this->assertSame($template->id, $request->template->id);
        $this->assertSame('إنشاء أو تحديث في الأقسام', $request->getOperationSummary());
        $this->assertTrue($request->hasConflict());
        $this->assertSame(['name' => ['old' => 'Old', 'new' => 'New']], $request->getDiffFields());

        $this->assertTrue($request->approve($this->user, 'approve it'));
        $this->assertSame(1, DataImportRequest::approved()->whereKey($request->id)->count());
        $this->assertSame(1, DataImportRequest::readyToApply()->whereKey($request->id)->count());
        $this->assertSame($this->user->id, $request->fresh()->reviewer->id);
        $this->assertFalse($request->fresh()->approve($this->user));

        $request->markAsApplied(123);
        $this->assertSame(ImportStatus::Applied, $request->fresh()->status);
        $this->assertSame(123, $request->fresh()->applied_id);

        $failed = DataImportRequest::factory()->failed()->create(['response_id' => $response->id]);
        $this->assertTrue($failed->resetForRetry());
        $this->assertSame(ImportStatus::Approved, $failed->fresh()->status);
        $this->assertNull($failed->fresh()->error_message);

        $pending = DataImportRequest::factory()->pending()->create(['response_id' => $response->id]);
        $this->assertTrue($pending->reject($this->user->id, 'bad payload'));
        $this->assertSame(ImportStatus::Rejected, $pending->fresh()->status);
        $this->assertSame('bad payload', $pending->fresh()->rejection_reason);

        $pending->markAsFailed('database error');
        $this->assertSame(ImportStatus::Failed, $pending->fresh()->status);
        $this->assertSame('database error', $pending->fresh()->error_message);
    }
}
