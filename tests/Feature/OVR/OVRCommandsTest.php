<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Notifications\ReportAssignedNotification;
use App\Modules\OVR\Notifications\SLADueNotification;
use App\Modules\OVR\Notifications\StatusChangedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OVRCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected Department $department;

    protected IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function makeReport(array $override = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'desc',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::New,
            'is_confidential' => false,
        ], $override));
    }

    public function test_archive_closed_command_archives_old_closed_reports(): void
    {
        $report = $this->makeReport([
            'status' => ReportStatus::Closed,
            'closed_at' => now()->subDays(31),
            'closed_by' => $this->user->id,
        ]);

        $this->artisan('ovr:archive-closed', ['--days' => 30])->assertSuccessful();

        $this->assertEquals(ReportStatus::Archived, $report->fresh()->status);
    }

    public function test_archive_closed_command_skips_recently_closed(): void
    {
        $report = $this->makeReport([
            'status' => ReportStatus::Closed,
            'closed_at' => now()->subDays(5),
            'closed_by' => $this->user->id,
        ]);

        $this->artisan('ovr:archive-closed', ['--days' => 30])->assertSuccessful();

        $this->assertEquals(ReportStatus::Closed, $report->fresh()->status);
    }

    public function test_sla_due_command_notifies_assignee_once(): void
    {
        Notification::fake();

        $report = $this->makeReport([
            'status' => ReportStatus::InProgress,
            'assigned_to' => $this->user->id,
            'due_date' => now()->addHours(3),
        ]);

        $this->artisan('ovr:notify-sla-due', ['--hours' => 6])->assertSuccessful();

        Notification::assertSentTo($this->user, SLADueNotification::class);
        $this->assertNotNull($report->fresh()->sla_notified_at);

        Notification::fake();
        $this->artisan('ovr:notify-sla-due', ['--hours' => 6])->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_pending_timeout_command_returns_stuck_reports_to_new(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::PendingInfo]);
        $report->forceFill(['updated_at' => now()->subDays(8)])->saveQuietly();

        $this->artisan('ovr:notify-pending-timeout', ['--days' => 7])->assertSuccessful();

        $this->assertEquals(ReportStatus::New, $report->fresh()->status);
    }

    public function test_status_change_notifies_reporter_and_assignee(): void
    {
        Notification::fake();

        $reporter = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $assignee = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        $report = $this->makeReport([
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'status' => ReportStatus::New,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::InProgress->value,
                'assigned_to' => $assignee->id,
            ])
            ->assertStatus(200);

        Notification::assertSentTo($reporter, StatusChangedNotification::class);
        Notification::assertSentTo($assignee, ReportAssignedNotification::class);
    }

    public function test_export_returns_csv_download(): void
    {
        $this->makeReport(['status' => ReportStatus::New]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/ovr/incidents/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('رقم التقرير', $body);
        $this->assertStringContainsString('OVR-', $body);
    }
}
