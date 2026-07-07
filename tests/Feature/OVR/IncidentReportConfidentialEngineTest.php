<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class IncidentReportConfidentialEngineTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    public function test_confidential_visible_with_engine_ovr_confidential(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);
        $this->grantEngineCapability($user, [Capability::OVR_VIEW, Capability::OVR_CONFIDENTIAL]);

        // User is the reporter — scopeVisibleTo admits them via reporter_id, then
        // the confidentiality gate (engine) lifts the is_confidential filter.
        $report = $this->makeReport($org, $dept, $user, [
            'reporter_id' => $user->id,
            'is_confidential' => true,
        ]);

        $this->assertTrue(
            IncidentReport::query()->visibleTo($user)->where('id', $report->id)->exists()
        );
    }

    public function test_confidential_hidden_without_grant(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);
        $reporter = User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id]);

        $report = $this->makeReport($org, $dept, $reporter, [
            'reporter_id' => $reporter->id,
            'is_confidential' => true,
        ]);

        $this->assertFalse(
            IncidentReport::query()->visibleTo($user)->where('id', $report->id)->exists()
        );
    }

    private function makeReport(Organization $org, Department $dept, User $reporter, array $override = []): IncidentReport
    {
        $incidentType = IncidentType::firstOrCreate(
            ['name' => 'wave4_confidential_test'],
            [
                'name_ar' => 'سري',
                'is_active' => true,
                'organization_id' => $org->id,
            ]
        );

        return IncidentReport::create(array_merge([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'wave4 confidential test',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => true,
        ], $override));
    }
}
