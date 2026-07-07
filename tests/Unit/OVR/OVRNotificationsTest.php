<?php

namespace Tests\Unit\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\ReportComment;
use App\Modules\OVR\Notifications\CommentAddedNotification;
use App\Modules\OVR\Notifications\ReportSubmittedNotification;
use App\Modules\OVR\Notifications\StatusChangedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OVRNotificationsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_report_submitted_notification_channels_and_payload(): void
    {
        $report = $this->report(['severity_level' => SeverityLevel::Critical]);
        $notification = new ReportSubmittedNotification($report);

        $this->assertSame(['mail', 'database'], $notification->via($this->reporter));

        $data = $notification->toArray($this->reporter);
        $this->assertSame('ovr_report_submitted', $data['type']);
        $this->assertSame($report->id, $data['report_id']);
        $this->assertSame($report->report_number, $data['report_number']);
        $this->assertSame(SeverityLevel::Critical->value, $data['severity']);
        $this->assertStringContainsString($report->report_number, $data['message']);

        $mail = $notification->toMail($this->reporter);
        $this->assertStringContainsString($report->report_number, $mail->subject);
        $this->assertStringContainsString($this->reporter->name, $mail->greeting);
    }

    public function test_comment_added_notification_channels_and_payload(): void
    {
        $report = $this->report();
        $comment = ReportComment::create([
            'report_id' => $report->id,
            'user_id' => $this->reporter->id,
            'author_name' => 'محمد المراجع',
            'text' => 'تعليق توضيحي على التقرير',
            'is_internal' => false,
        ]);

        $notification = new CommentAddedNotification($report, $comment);

        $this->assertSame(['mail', 'database'], $notification->via($this->reporter));

        $data = $notification->toArray($this->reporter);
        $this->assertSame('ovr_comment_added', $data['type']);
        $this->assertSame($report->id, $data['report_id']);
        $this->assertSame($comment->id, $data['comment_id']);
        $this->assertSame('محمد المراجع', $data['author_name']);
        $this->assertStringContainsString($report->report_number, $data['message']);

        $mail = $notification->toMail($this->reporter);
        $this->assertStringContainsString($report->report_number, $mail->subject);
        $this->assertStringContainsString('محمد المراجع', implode(' ', $mail->introLines));
    }

    public function test_status_changed_notification_channels_and_payload(): void
    {
        $report = $this->report();
        $notification = new StatusChangedNotification(
            $report,
            ReportStatus::New,
            ReportStatus::UnderReview,
        );

        $this->assertSame(['mail', 'database'], $notification->via($this->reporter));

        $data = $notification->toArray($this->reporter);
        $this->assertSame('ovr_status_changed', $data['type']);
        $this->assertSame($report->id, $data['report_id']);
        $this->assertSame(ReportStatus::New->value, $data['from_status']);
        $this->assertSame(ReportStatus::UnderReview->value, $data['to_status']);
        $this->assertStringContainsString(ReportStatus::UnderReview->label(), $data['message']);

        $mail = $notification->toMail($this->reporter);
        $this->assertStringContainsString($report->report_number, $mail->subject);
        $this->assertStringContainsString(ReportStatus::UnderReview->label(), implode(' ', $mail->introLines));
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
            'incident_description' => 'OVR notification coverage report',
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
