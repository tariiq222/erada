<?php

namespace Tests\Feature\E2E;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CriticalApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_critical_api_flows_smoke_end_to_end(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'email' => 'phase9-smoke@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $this->postJson('/api/login', [
            'email' => 'phase9-smoke@example.com',
            'password' => 'password',
        ])->assertOk();

        $projectId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'Phase 9 Smoke Project',
                'type' => 'development',
                'description' => 'Critical flow smoke project',
                'status' => 'planning',
                'priority' => 'high',
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonth()->format('Y-m-d'),
                'department_id' => $department->id,
            ])
            ->assertCreated()
            ->json('project.id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/unified-tasks', [
                'title' => 'Phase 9 Smoke Task',
                'description' => 'Critical flow smoke task',
                'type' => 'project',
                'project_id' => $projectId,
                'assigned_to' => $user->id,
                'priority' => 'medium',
                'status' => 'todo',
            ])
            ->assertCreated();

        $incidentType = IncidentType::create([
            'name' => 'Smoke Incident Type',
            'name_ar' => 'نوع بلاغ تجريبي',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ovr/incidents', [
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $incidentType->id,
                'incident_description' => 'Phase 9 smoke incident description',
                'actions_taken' => 'Initial smoke action',
                'contributing_factors' => ['process'],
                'immediate_action_required' => false,
                'severity_level' => SeverityLevel::Medium->value,
                'is_confidential' => false,
            ])
            ->assertCreated();

        $survey = Survey::factory()->published()->public()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'allow_multiple_responses' => true,
        ]);
        $field = SurveyField::factory()->text()->required()->create([
            'survey_id' => $survey->id,
            'name' => 'smoke_answer',
            'label' => 'Smoke Answer',
        ]);
        $versionHash = $this->getJson("/api/surveys/public/{$survey->code}")
            ->assertOk()
            ->json('version_hash');
        $this->assertNotNull($versionHash);

        $this->postJson("/api/surveys/public/{$survey->code}/submit", [
            'respondent_name' => 'Phase 9 Respondent',
            'respondent_email' => 'respondent@example.com',
            'version_hash' => $versionHash,
            'answers' => [
                $field->field_key => 'Smoke response value',
            ],
            'completion_time' => 30,
        ])->assertCreated();
    }
}
