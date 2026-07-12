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
use App\Modules\Shared\Support\ElementAbilities;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * OvrAbilitiesTest — /api/incidents/{report} response carries the
 * engine-computed abilities map (view, edit, investigate, close, assign).
 */
class OvrAbilitiesTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);
    }

    private function makeIncidentType(): IncidentType
    {
        return IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    public function test_ovr_response_carries_engine_abilities(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $mgr = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $this->grantEngineCapability($mgr, [
            Capability::OVR_VIEW,
            Capability::OVR_EDIT,
            Capability::OVR_INVESTIGATE,
            Capability::OVR_CLOSE,
            Capability::OVR_ASSIGN,
        ], 'department', $dept->id, 'ovr_test_manager');

        $incidentType = $this->makeIncidentType();

        $report = IncidentReport::create([
            'organization_id' => $org->id,
            'reporter_id' => $mgr->id,
            'reporter_name' => $mgr->name,
            'reporter_email' => $mgr->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'Test incident',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Low,
            'is_confidential' => false,
            'status' => ReportStatus::New,
        ]);

        $this->actingAs($mgr->fresh(), 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}")
            ->assertOk()
            ->assertJsonPath('data.abilities.view', true)
            ->assertJsonPath('data.abilities.edit', true)
            ->assertJsonPath('data.abilities.investigate', true)
            ->assertJsonPath('data.abilities.close', true)
            ->assertJsonPath('data.abilities.assign', true);
    }

    public function test_ovr_outsider_gets_all_abilities_false_via_helper(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $reporter = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $incidentType = $this->makeIncidentType();

        $report = IncidentReport::create([
            'organization_id' => $org->id,
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_department_id' => $dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'Test incident',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Low,
            'is_confidential' => false,
            'status' => ReportStatus::New,
        ]);
        $outsider = User::factory()->create(['organization_id' => $org->id]);

        $abilities = ElementAbilities::resolve(
            $outsider,
            $report,
            [
                'view' => Capability::OVR_VIEW,
                'edit' => Capability::OVR_EDIT,
                'investigate' => Capability::OVR_INVESTIGATE,
                'close' => Capability::OVR_CLOSE,
                'assign' => Capability::OVR_ASSIGN,
            ]
        );

        foreach ($abilities as $key => $value) {
            $this->assertFalse($value, "Expected abilities.{$key} to be false for outsider");
        }
    }
}
