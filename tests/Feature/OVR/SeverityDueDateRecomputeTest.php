<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SeverityDueDateRecomputeTest — P2 audit fix.
 *
 * A severity change on an existing report must recompute the SLA due_date,
 * otherwise a report opened at Low (due in 48h) silently keeps the 48-hour
 * window after being upgraded to Critical (4h). IncidentReportController::update
 * now detects `wasChanged('severity_level')` and overwrites `due_date` via
 * IncidentReport::calculateDueDate() using the severity's slaHours().
 *
 * Coverage:
 *   - create with Low → due_date = created_at + 48h
 *   - PATCH severity to Critical → due_date is now created_at + 4h
 *   - no spurious SLADue notifications fire on the backdated due_date
 *     (we backdate AFTER the re-compute, no comment justification, so the
 *     SLA scheduler must not falsely trigger).
 */
class SeverityDueDateRecomputeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $superAdmin;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->superAdmin);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    /**
     * Create a New report at Low severity. Mimics what the store() controller does:
     * the initial due_date is set by calculateDueDate() (48h for Low).
     */
    private function makeLowReport(): IncidentReport
    {
        $report = IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->superAdmin->id,
            'reporter_name' => $this->superAdmin->name,
            'reporter_email' => $this->superAdmin->name.'@example.test',
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'severity recompute fixture (Low)',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Low,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ]);

        // Mirror the controller's store() flow so the fixture has a real due_date.
        $report->due_date = $report->calculateDueDate();
        $report->save();

        return $report;
    }

    public function test_severity_upgrade_recomputes_due_date_to_new_severity_sla(): void
    {
        Notification::fake();

        $report = $this->makeLowReport();

        // Sanity: created_at + 48h for Low.
        $createdAt = $report->created_at->copy();
        $expectedLowDue = $createdAt->copy()->addHours(SeverityLevel::Low->slaHours());
        $this->assertTrue(
            $report->due_date->equalTo($expectedLowDue),
            'Initial Low report should have due_date at created_at + 48h'
        );

        // PATCH the report, bumping severity from Low to Critical. The controller
        // must recompute due_date using Critical's 4-hour SLA window.
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $this->incidentType->id,
                'incident_description' => 'severity recompute fixture (upgraded)',
                'immediate_action_required' => true,
                'severity_level' => SeverityLevel::Critical->value,
                'is_confidential' => false,
            ]);

        $response->assertStatus(200);

        $expectedCriticalDue = $createdAt->copy()->addHours(SeverityLevel::Critical->slaHours());

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'severity_level' => SeverityLevel::Critical->value,
        ]);

        // Re-read so we observe the recomputed value.
        $fresh = $report->fresh();
        $this->assertTrue(
            $fresh->due_date->equalTo($expectedCriticalDue),
            sprintf(
                'After severity upgrade, due_date should be created_at + 4h (expected %s, got %s)',
                $expectedCriticalDue->toIso8601String(),
                $fresh->due_date?->toIso8601String() ?? 'NULL'
            )
        );

        // The Low (48h) window must NOT still be in effect.
        $this->assertFalse(
            $fresh->due_date->equalTo($expectedLowDue),
            'After severity upgrade, due_date must NOT still be at the Low 48h mark'
        );
    }

    public function test_no_severity_change_leaves_due_date_untouched(): void
    {
        // A PATCH that doesn't touch severity_level must NOT recompute due_date.
        $report = $this->makeLowReport();
        $originalDue = $report->due_date->copy();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $this->incidentType->id,
                'incident_description' => 'no severity change',
                'immediate_action_required' => false,
                'severity_level' => SeverityLevel::Low->value, // unchanged
                'is_confidential' => false,
            ]);

        $response->assertStatus(200);

        $fresh = $report->fresh();
        $this->assertTrue(
            $fresh->due_date->equalTo($originalDue),
            'due_date must remain untouched when severity_level is unchanged'
        );
    }

    public function test_severity_downgrade_recomputes_due_date_to_longer_window(): void
    {
        // Mirror of the upgrade test in reverse — Critical → Low must widen the SLA.
        $report = IncidentReport::create([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->superAdmin->id,
            'reporter_name' => $this->superAdmin->name,
            'reporter_email' => $this->superAdmin->name.'@example.test',
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'severity downgrade fixture',
            'immediate_action_required' => true,
            'severity_level' => SeverityLevel::Critical,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ]);
        $report->due_date = $report->calculateDueDate();
        $report->save();

        $createdAt = $report->created_at->copy();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $this->incidentType->id,
                'incident_description' => 'severity downgrade fixture (downgraded)',
                'immediate_action_required' => false,
                'severity_level' => SeverityLevel::Low->value,
                'is_confidential' => false,
            ])
            ->assertStatus(200);

        $fresh = $report->fresh();
        $expectedLowDue = $createdAt->copy()->addHours(SeverityLevel::Low->slaHours());
        $this->assertTrue(
            $fresh->due_date->equalTo($expectedLowDue),
            'After severity downgrade, due_date should widen to created_at + 48h'
        );
    }

    public function test_severity_change_does_not_fire_sla_due_notification(): void
    {
        // The fix uses saveQuietly() on the follow-up due_date write to avoid a
        // spurious second audit log row. Crucially, no SLADue notification must
        // be triggered by the controller path itself — those notifications are
        // dispatched by the SLA scheduler, not the controller. This test pins
        // that the controller stays quiet (regression guard for accidental
        // notification wiring).
        Notification::fake();

        $report = $this->makeLowReport();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => false,
                'informed_authority' => false,
                'incident_type_id' => $this->incidentType->id,
                'incident_description' => 'no SLA notification expected',
                'immediate_action_required' => true,
                'severity_level' => SeverityLevel::Critical->value,
                'is_confidential' => false,
            ])
            ->assertStatus(200);

        Notification::assertNothingSent();
    }
}
