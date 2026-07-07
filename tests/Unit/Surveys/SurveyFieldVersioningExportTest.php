<?php

namespace Tests\Unit\Surveys;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Enums\FieldType;
use App\Modules\Surveys\Enums\ResponseStatus;
use App\Modules\Surveys\Enums\SurveyStatus;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyFieldAnswer;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Services\SurveyExportService;
use App\Modules\Surveys\Services\VersioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SurveyFieldVersioningExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->creator = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    public function test_survey_field_generates_keys_validation_rules_options_and_visibility_conditions(): void
    {
        $survey = $this->survey();
        $first = SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'name' => 'Arabic Field !!',
            'field_key' => null,
            'type' => FieldType::Email->value,
            'is_required' => true,
            'config' => ['validation_pattern' => '/@example\\.test$/'],
        ]);
        $second = SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'name' => 'Arabic Field !!',
            'field_key' => null,
            'type' => FieldType::Number->value,
            'is_required' => false,
            'config' => ['min' => 1, 'max' => 10],
        ]);

        $this->assertSame('arabic_field', $first->field_key);
        $this->assertSame('arabic_field_1', $second->field_key);
        $this->assertContains('required', $first->getValidationRules());
        $this->assertContains('email', $first->getValidationRules());
        $this->assertContains('regex:/@example\\.test$/', $first->getValidationRules());
        $this->assertContains('nullable', $second->getValidationRules());
        $this->assertContains('numeric', $second->getValidationRules());
        $this->assertContains('min:1', $second->getValidationRules());
        $this->assertContains('max:10', $second->getValidationRules());

        $choice = SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'type' => FieldType::Select->value,
            'is_visible' => true,
            'config' => ['options' => [['label' => 'Yes', 'value' => 'yes']]],
            'visibility_rules' => [
                'operator' => 'and',
                'conditions' => [
                    ['field' => 'age', 'operator' => 'greater_than', 'value' => 18],
                    ['field' => 'country', 'operator' => 'in', 'value' => ['SA', 'AE']],
                    ['field' => 'notes', 'operator' => 'contains', 'value' => 'ok'],
                ],
            ],
        ]);

        $this->assertSame([['label' => 'Yes', 'value' => 'yes']], $choice->getOptions());
        $this->assertTrue($choice->isVisibleForAnswers(['age' => 19, 'country' => 'SA', 'notes' => 'ok now']));
        $this->assertFalse($choice->isVisibleForAnswers(['age' => 16, 'country' => 'SA', 'notes' => 'ok now']));

        $or = SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'is_visible' => true,
            'visibility_rules' => [
                'operator' => 'or',
                'conditions' => [
                    ['field' => 'status', 'operator' => 'equals', 'value' => 'done'],
                    ['field' => 'status', 'operator' => 'not_equals', 'value' => 'blocked'],
                ],
            ],
        ]);
        $this->assertTrue($or->isVisibleForAnswers(['status' => 'new']));

        $hidden = SurveyField::factory()->create(['survey_id' => $survey->id, 'is_visible' => false]);
        $this->assertFalse($hidden->isVisibleForAnswers([]));
    }

    public function test_versioning_service_locks_hashes_validates_and_finds_revisions_by_code(): void
    {
        $survey = $this->survey(['revision' => 1]);
        SurveyField::factory()->create(['survey_id' => $survey->id, 'field_key' => 'email', 'type' => FieldType::Email->value]);
        $service = new VersioningService;

        $hash = $service->calculateHash($survey->fresh(['sections', 'fields']));
        $this->assertSame(64, strlen($hash));

        $version = $service->createVersionAndLock($survey->fresh(['sections', 'fields']));
        $this->assertNotNull($survey->fresh()->locked_at);
        $this->assertTrue($service->validateVersionHash($survey, $version->version_hash));
        $this->assertFalse($service->validateVersionHash($survey, 'missing-hash'));
        $this->assertSame($version->id, $service->getVersionByHash($version->version_hash)->id);
        $this->assertSame($version->id, $service->getOrCreateVersion($survey->fresh())->id);
        $this->assertSame($survey->id, $service->getLatestPublishedByCode($survey->code)->id);
        $this->assertSame($survey->id, $service->getRevision($survey->code, 1)->id);
        $this->assertCount(1, $service->getAllRevisions($survey->code));

        $newRevision = $service->createNewRevision($survey->fresh(['sections.fields', 'fields']));
        $this->assertSame(2, $newRevision->revision);
        $this->assertSame(SurveyStatus::Draft, $newRevision->status);
    }

    public function test_export_service_writes_csv_and_json_with_filtered_answers_and_csv_injection_escape(): void
    {
        Storage::fake('local');
        $survey = $this->survey(['code' => 'export-code']);
        $name = SurveyField::factory()->create(['survey_id' => $survey->id, 'field_key' => 'name', 'name' => 'name', 'label' => 'Name', 'type' => FieldType::Text->value, 'order' => 1]);
        $check = SurveyField::factory()->create(['survey_id' => $survey->id, 'field_key' => 'check', 'name' => 'check', 'label' => 'Check', 'type' => FieldType::Checkbox->value, 'order' => 2]);
        $response = SurveyResponse::factory()->create([
            'survey_id' => $survey->id,
            'respondent_id' => $this->creator->id,
            'respondent_name' => null,
            'respondent_email' => null,
            'status' => ResponseStatus::Submitted,
            'submitted_at' => now(),
        ]);
        SurveyFieldAnswer::createFromValue($response, $name, '=SUM(1,1)');
        SurveyFieldAnswer::createFromValue($response, $check, ['yes', 'no']);
        SurveyResponse::factory()->create([
            'survey_id' => $survey->id,
            'status' => ResponseStatus::Invalid,
            'submitted_at' => now()->subDays(3),
        ]);

        $service = new SurveyExportService;
        $csvPath = $service->exportToCsv($survey, ['status' => ResponseStatus::Submitted->value, 'from_date' => now()->subDay()]);
        $jsonPath = $service->exportToJson($survey, ['status' => ResponseStatus::Submitted->value]);

        Storage::disk('local')->assertExists($csvPath);
        Storage::disk('local')->assertExists($jsonPath);
        $csv = Storage::disk('local')->get($csvPath);
        $this->assertStringContainsString('Name', $csv);
        $this->assertStringContainsString("'=SUM(1,1)", $csv);
        $this->assertStringNotContainsString('invalid', $csv);

        $json = json_decode(Storage::disk('local')->get($jsonPath), true);
        $this->assertSame('export-code', $json['survey']['code']);
        $this->assertCount(1, $json['responses']);
        $this->assertSame('=SUM(1,1)', $json['responses'][0]['answers']['name']);
        $this->assertSame(['yes', 'no'], $json['responses'][0]['answers']['check']);
        $this->assertSame("'@cmd", $service->escapeCsvCell('@cmd'));
        $this->assertSame('', $service->escapeCsvCell(''));
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
