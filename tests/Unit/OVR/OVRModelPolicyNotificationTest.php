<?php

namespace Tests\Unit\OVR;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Notifications\SLADueNotification;
use App\Modules\OVR\Policies\IncidentReportPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class OVRModelPolicyNotificationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private Organization $organization;

    private Department $department;

    private User $reporter;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create(['organization_id' => $this->organization->id]);
        $this->reporter = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->incidentType = IncidentType::create(['name' => 'Medication', 'name_ar' => 'دواء', 'is_active' => true]);
    }

    public function test_report_status_and_severity_enums_cover_labels_colors_sla_and_transitions(): void
    {
        $this->assertSame('مسودة', ReportStatus::Draft->label());
        $this->assertSame('danger', ReportStatus::New->color());
        $this->assertTrue(ReportStatus::Draft->canEdit());
        $this->assertFalse(ReportStatus::Closed->canEdit());
        $this->assertTrue(ReportStatus::UnderReview->canTransitionTo(ReportStatus::Resolved));
        $this->assertFalse(ReportStatus::Rejected->canTransitionTo(ReportStatus::Closed));

        $this->assertSame('منخفض', SeverityLevel::Low->label());
        $this->assertSame('danger', SeverityLevel::Critical->color());
        $this->assertSame(48, SeverityLevel::Medium->slaHours());
        $this->assertSame(24, SeverityLevel::High->slaHours());
        $this->assertSame(4, SeverityLevel::Critical->slaHours());
    }

    public function test_incident_report_helpers_scopes_relations_and_status_history(): void
    {
        $report = $this->report(['severity_level' => SeverityLevel::Critical, 'created_at' => now()->subHour()]);

        $this->assertSame('report_number', $report->getRouteKeyName());
        $this->assertFalse($report->isClosed());
        $this->assertTrue($report->canEdit());
        $this->assertTrue($report->canTransitionTo(ReportStatus::UnderReview));
        $this->assertTrue($report->calculateDueDate()->isSameMinute($report->created_at->copy()->addHours(4)));

        $history = $report->recordStatusChange(ReportStatus::New, ReportStatus::UnderReview, $this->reporter->id, 'review started');
        $this->assertSame('review started', $history->reason);
        $this->assertSame($this->organization->id, IncidentReport::forOrganization($this->organization->id)->first()->organization_id);
        $this->assertSame($report->id, IncidentReport::byStatus(ReportStatus::New)->bySeverity(SeverityLevel::Critical)->first()->id);
        $this->assertSame($this->reporter->id, $report->reporter->id);
        $this->assertSame($this->department->id, $report->reporterDepartment->id);
        $this->assertSame($this->incidentType->id, $report->incidentType->id);
        $this->assertCount(1, $report->statusHistory);
    }

    public function test_policy_blocks_cross_org_and_allows_engine_grants(): void
    {
        $policy = new IncidentReportPolicy;
        $report = $this->report(['is_confidential' => true]);
        $otherOrgUser = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $departmentViewer = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);

        // منح المحرك بدلاً من الصلاحيات المسطّحة القديمة. viewer Spatie يمنح
        // A canonical organization assignment grants OVR_VIEW.
        // المُنشَأ في الـ seeder، فيغلق back-door المسطّح ovr.view_confidential ويُلزم
        // المرور بطبقة can_view_confidential من المحرك. role_key فريد (ovr_viewer)
        // لتجنّب تعارض findByKey مع تعريف legacy.test roles الذي يسبقه بالـ insert.
        $this->grantCanonicalViewer($departmentViewer);
        $this->grantEngineCapability(
            $departmentViewer,
            [
                Capability::OVR_VIEW,
                Capability::OVR_COMMENT,
                Capability::OVR_ASSIGN,
                Capability::OVR_CHANGE_STATUS,
            ],
            'organization',
            $this->organization->id,
            'ovr_viewer',
            ['can_view_confidential' => true]
        );

        $this->assertFalse($policy->view($otherOrgUser, $report));
        $this->assertTrue($policy->viewAny($departmentViewer));
        $this->assertTrue($policy->view($departmentViewer, $report));
        $this->assertTrue($policy->comment($departmentViewer, $report));
        $this->assertTrue($policy->assign($departmentViewer, $report));
        $this->assertTrue($policy->changeStatus($departmentViewer, $report));
        $this->assertFalse($policy->create($otherOrgUser));

        // المُبلِّغ يملك قدرات تحرير/حذف/تصدير/إحصاء عبر المحرك (بدلاً من
        // ovr.edit_own / ovr.delete_own / ovr.export / ovr.view_statistics المسطّحة).
        // viewer Spatie يمنح grantsAtOrganization(OVR_VIEW) ليُغلق back-door المسطّح.
        $this->grantCanonicalViewer($this->reporter);
        $this->grantEngineCapability(
            $this->reporter,
            [
                Capability::OVR_EDIT,
                Capability::OVR_DELETE,
                Capability::OVR_EXPORT,
                Capability::OVR_VIEW_STATISTICS,
            ],
            'organization',
            $this->organization->id,
            'ovr_reporter'
        );

        $this->assertTrue($policy->update($this->reporter, $report));
        $this->assertTrue($policy->delete($this->reporter, $report));
        $this->assertTrue($policy->export($this->reporter));
        $this->assertTrue($policy->viewStatistics($this->reporter));
    }

    public function test_sla_due_notification_channels_mail_and_database_payload(): void
    {
        $report = $this->report(['due_date' => now()->addHour()]);
        $notification = new SLADueNotification($report);

        $this->assertSame(['mail', 'database'], $notification->via($this->reporter));
        $this->assertSame('ovr_sla_due', $notification->toArray($this->reporter)['type']);
        $this->assertSame($report->report_number, $notification->toArray($this->reporter)['report_number']);
        $this->assertStringContainsString($report->report_number, $notification->toMail($this->reporter)->subject);
    }

    private function report(array $overrides = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->reporter->id,
            'reporter_name' => $this->reporter->name,
            'reporter_email' => $this->reporter->email,
            'reporter_department_id' => $this->department->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'Behavioral OVR unit coverage report',
            'actions_taken' => 'Action taken',
            'contributing_factors' => ['process'],
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => false,
            'due_date' => now()->addDay(),
        ], $overrides));
    }
}
