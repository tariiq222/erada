<?php

namespace Tests\Feature\Api\Surveys;

use App\Modules\Surveys\Enums\SurveyPrivacyMode;
use App\Modules\Surveys\Http\Resources\SurveyResponseResource;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyFieldAnswer;
use App\Modules\Surveys\Models\SurveyResponse;
use App\Modules\Surveys\Services\ResponseService;
use App\Modules\Surveys\Services\SurveyExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SurveyPrivacyHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_survey_defaults_to_identified_and_keeps_existing_identified_response_serialization(): void
    {
        $survey = Survey::factory()->published()->create();
        $field = $this->createField($survey, 'Name', 'name');
        $response = SurveyResponse::factory()->create([
            'survey_id' => $survey->id,
            'respondent_name' => 'Ali',
            'respondent_email' => 'ali@example.com',
            'respondent_phone' => '+966500000000',
        ]);
        SurveyFieldAnswer::createFromValue($response, $field, 'visible raw answer');

        $payload = (new SurveyResponseResource(
            $response->load(['survey', 'answers.field'])
        ))->resolve(request());

        $this->assertSame(SurveyPrivacyMode::Identified, $survey->privacy_mode);
        $this->assertSame('identified', $payload['privacy_mode']);
        $this->assertSame('Ali', $payload['respondent_name']);
        $this->assertSame('ali@example.com', $payload['respondent_email']);
        $this->assertSame('+966500000000', $payload['respondent_phone']);
        $this->assertArrayHasKey('answer_value', $payload['answers'][0]);
        $this->assertArrayHasKey('answer_text', $payload['answers'][0]);
        $this->assertArrayHasKey('answer_number', $payload['answers'][0]);
    }

    public function test_confidential_response_masks_pii_and_omits_raw_answer_values(): void
    {
        $survey = Survey::factory()->published()->create([
            'privacy_mode' => SurveyPrivacyMode::Confidential,
        ]);
        $field = $this->createField($survey, 'Confidential note', 'confidential_note');
        $response = SurveyResponse::factory()->create([
            'survey_id' => $survey->id,
            'respondent_name' => 'Sara',
            'respondent_email' => 'sara@example.com',
            'respondent_phone' => '+966511111111',
        ]);
        SurveyFieldAnswer::createFromValue($response, $field, 'sensitive free text');

        $payload = (new SurveyResponseResource(
            $response->load(['survey', 'answers.field'])
        ))->resolve(request());

        $this->assertSame('confidential', $payload['privacy_mode']);
        $this->assertNull($payload['respondent_name']);
        $this->assertNull($payload['respondent_email']);
        $this->assertNull($payload['respondent_phone']);
        $this->assertSame('مجيب سري', $payload['respondent_display_name']);
        $this->assertSame($field->id, $payload['answers'][0]['field_id']);
        $this->assertSame('confidential_note', $payload['answers'][0]['field_key']);
        $this->assertSame('Confidential note', $payload['answers'][0]['field']['label']);
        $this->assertSame('sensitive free text', $payload['answers'][0]['display_value']);
        $this->assertArrayNotHasKey('answer_value', $payload['answers'][0]);
        $this->assertArrayNotHasKey('answer_text', $payload['answers'][0]);
        $this->assertArrayNotHasKey('answer_number', $payload['answers'][0]);
    }

    public function test_anonymous_public_submission_does_not_persist_direct_identifiers_and_serializes_anonymously(): void
    {
        $survey = Survey::factory()->published()->public()->create([
            'privacy_mode' => SurveyPrivacyMode::Anonymous,
            'allow_multiple_responses' => true,
        ]);
        $this->createField($survey, 'Feedback', 'feedback');

        $request = Request::create('/api/surveys/public/'.$survey->code.'/submit', 'POST', [
            'respondent_name' => 'Nora',
            'respondent_email' => 'nora@example.com',
            'respondent_phone' => '+966522222222',
            'answers' => ['feedback' => 'anonymous answer'],
        ]);
        $request->headers->set('X-Fingerprint-Hash', 'hashed-browser-fingerprint');

        $response = app(ResponseService::class)->createPublicResponse(
            $survey->load('fields'),
            ['feedback' => 'anonymous answer'],
            $request
        );

        $this->assertNull($response->respondent_id);
        $this->assertNull($response->respondent_name);
        $this->assertNull($response->respondent_email);
        $this->assertNull($response->respondent_phone);

        $payload = (new SurveyResponseResource(
            $response->load(['survey', 'answers.field'])
        ))->resolve(request());

        $this->assertSame('anonymous', $payload['privacy_mode']);
        $this->assertSame('مجيب مجهول', $payload['respondent_display_name']);
        $this->assertArrayNotHasKey('answer_value', $payload['answers'][0]);
        $this->assertArrayNotHasKey('answer_text', $payload['answers'][0]);
        $this->assertArrayNotHasKey('answer_number', $payload['answers'][0]);
    }

    public function test_survey_csv_export_escapes_formula_leading_headers_and_dynamic_values_only(): void
    {
        Storage::fake('local');

        $survey = Survey::factory()->published()->create(['code' => 'CSV-SAFE']);
        $dangerousHeader = $this->createField($survey, '=Danger Header', 'danger_header', 1);
        $normalHeader = $this->createField($survey, 'النص الآمن', 'safe_text', 2);
        $response = SurveyResponse::factory()->create([
            'survey_id' => $survey->id,
            'respondent_type' => 'public',
            'respondent_id' => null,
            'respondent_name' => '=Respondent',
        ]);
        SurveyFieldAnswer::createFromValue($response, $dangerousHeader, '=cmd|calc');
        SurveyFieldAnswer::createFromValue($response, $normalHeader, 'نص عربي آمن');

        $path = app(SurveyExportService::class)->exportToCsv($survey);
        $content = Storage::disk('local')->get($path);

        $this->assertStringContainsString("'=Danger Header", $content);
        $this->assertStringContainsString("'=Respondent", $content);
        $this->assertStringContainsString("'=cmd|calc", $content);
        $this->assertStringContainsString('النص الآمن', $content);
        $this->assertStringContainsString('نص عربي آمن', $content);
        $this->assertStringNotContainsString("'النص الآمن", $content);
    }

    public function test_escape_csv_cell_covers_all_formula_prefixes_without_modifying_safe_values(): void
    {
        $service = app(SurveyExportService::class);

        foreach (['=SUM(A1:A2)', '+SUM(A1:A2)', '-SUM(A1:A2)', '@SUM(A1:A2)', "\tSUM(A1:A2)", "\rSUM(A1:A2)", "\nSUM(A1:A2)"] as $value) {
            $this->assertSame("'{$value}", $service->escapeCsvCell($value));
        }

        $this->assertSame('نص عربي', $service->escapeCsvCell('نص عربي'));
        $this->assertSame('normal text', $service->escapeCsvCell('normal text'));
        $this->assertSame('', $service->escapeCsvCell(''));
        $this->assertSame(123, $service->escapeCsvCell(123));
        $this->assertTrue($service->escapeCsvCell(true));
        $this->assertNull($service->escapeCsvCell(null));
    }

    public function test_public_survey_version_hash_contract_requires_client_to_submit_hash(): void
    {
        $survey = Survey::factory()->published()->public()->create([
            'allow_multiple_responses' => true,
        ]);
        $this->createField($survey, 'Feedback', 'feedback');

        $showResponse = $this->getJson("/api/surveys/public/{$survey->code}");

        $showResponse->assertOk()
            ->assertJsonStructure(['data', 'version_hash']);

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson("/api/surveys/public/{$survey->code}/submit", [
            'answers' => ['feedback' => 'answer without hash'],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['version_hash']);

        $this->withHeaders(['X-Skip-Csrf' => '1'])->postJson("/api/surveys/public/{$survey->code}/submit", [
            'answers' => ['feedback' => 'answer with stale hash'],
            'version_hash' => 'stale-version-hash',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'version_mismatch');
    }

    private function createField(Survey $survey, string $label, string $fieldKey, int $order = 1): SurveyField
    {
        return SurveyField::factory()->text()->create([
            'survey_id' => $survey->id,
            'field_key' => $fieldKey,
            'name' => $fieldKey,
            'label' => $label,
            'is_required' => false,
            'order' => $order,
        ]);
    }
}
